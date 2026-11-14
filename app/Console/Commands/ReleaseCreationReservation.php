<?php

namespace App\Console\Commands;

use App\Models\PlaylistCreation;
use App\Models\QuotaReservation;
use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\WriteLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Operator fallback (final review, security I1): an abandoned creation keeps its write reservation for the rest
 * of the PT day (user decision Y15), which can block other owners. This releases the creation's active
 * reservations; a running creation is paused (paused(user)) so that no step re-reserves behind the operator's
 * back; the owner can still press 「続きから」, which reserves again only if there is room (spec 7.4).
 * Lock order of 5.4: the creation row first, then today's write row. Prints counts only.
 */
class ReleaseCreationReservation extends Command
{
    protected $signature = 'app:release-creation-reservation {creationId : playlist_creations.id（ログの creation_id）}';

    protected $description = '放置された作成の書込予約を解放する（運用。実行中なら一時停止にする）';

    private const RUNNING = [PlaylistCreation::PENDING, PlaylistCreation::CREATING_PLAYLIST, PlaylistCreation::ADDING];

    public function handle(QuotaLedger $ledger): int
    {
        $arg = (string) $this->argument('creationId');
        $id = ctype_digit($arg) ? (int) $arg : 0;

        $ledger->ensureWriteRow();
        $result = $id < 1 ? null : DB::transaction(function () use ($id, $ledger) {
            $c = PlaylistCreation::whereKey($id)->lockForUpdate()->first();
            if ($c === null) {
                return null;
            }

            return $ledger->withWriteLock(function (WriteLock $lock) use ($c) {
                $paused = in_array($c->status, self::RUNNING, true);
                if ($paused) {
                    $c->fill(['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_USER, 'paused_day_pt' => null])->save();
                }
                $released = QuotaReservation::where('creation_id', $c->id)->where('status', QuotaReservation::ACTIVE)->count();
                $lock->release($c->id);

                return [$released, $paused];
            });
        }, 3);

        if ($result === null) {
            $this->error('creation not found');

            return self::FAILURE;
        }
        [$released, $paused] = $result;
        Log::info('Creation reservation released by operator', ['creation_id' => $id, 'reservations' => $released, 'paused' => $paused]);
        $this->line("reservations released: {$released}");
        $this->line('paused: '.($paused ? 1 : 0));

        return self::SUCCESS;
    }
}
