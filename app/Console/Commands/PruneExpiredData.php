<?php

namespace App\Console\Commands;

use App\Models\PlaylistCreation;
use App\Models\PlaylistCreationItem;
use App\Services\ChannelVideoCatalog;
use App\Services\Creation\CreationProgress;
use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\WriteLock;
use App\Support\QuotaClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PruneExpiredData extends Command
{
    /** quota_usage holds no YouTube data; kept for 90 days (spec 4.3). */
    public const QUOTA_USAGE_RETENTION_DAYS = 90;

    /**
     * Data obtained with the channel owner's token (a creation's items, a non-public playlist id) is deleted within
     * this many days after the owner's last use or logout (Developer Policies III.D.2; spec 4.3).
     */
    public const AUTHORIZED_RETENTION_DAYS = 7;

    /**
     * The batch runs once a day, so it deletes everything that expires before the next run:
     * nothing outlives its displayed delete date (display still hides rows at expires_at <= now()).
     */
    public const LOOKAHEAD_HOURS = 24;

    private const CHUNK = 500;

    protected $signature = 'app:prune-expired';

    protected $description = '保持期限切れのデータを削除する（4.3）';

    private bool $failed = false;

    public function handle(ChannelVideoCatalog $catalog, QuotaLedger $ledger): int
    {
        $this->failed = false;
        $now = now()->toImmutable();
        $cutoff = $now->addHours(self::LOOKAHEAD_HOURS);
        $timestamp = $now->getTimestamp();

        $this->step('proposals', fn () => $this->pruneProposals($cutoff, $ledger));

        $this->step('authorized_data', fn () => $this->pruneAuthorizedData(
            $cutoff->subDays(self::AUTHORIZED_RETENTION_DAYS),
            $ledger,
        ));

        // Per-channel lock path: deletes the channel row and all its video rows and records the expiration.
        $this->step('channel_caches', fn () => $catalog->expireStale($cutoff));

        $this->step('channel_cache_expirations', fn () => DB::table('channel_cache_expirations')
            ->where('expired_at', '<=', $cutoff->subDays((int) config('listmaker.retention_days')))
            ->delete());

        $this->step('quota_usage', function () {
            $cutoffDay = CarbonImmutable::parse(QuotaClock::todayPt())
                ->subDays(self::QUOTA_USAGE_RETENTION_DAYS)
                ->format('Y-m-d');

            return DB::table('quota_usage')->where('day_pt', '<', $cutoffDay)->delete();
        });

        // The database cache store never deletes expired rows by itself.
        $this->step('cache', fn () => DB::table(config('cache.stores.database.table') ?: 'cache')
            ->where('expiration', '<=', $timestamp)
            ->delete());
        $this->step('cache_locks', fn () => DB::table(config('cache.stores.database.lock_table') ?: 'cache_locks')
            ->where('expiration', '<=', $timestamp)
            ->delete());

        // Also hourly (app:prune-sessions).
        $this->step('sessions', fn () => PruneSessions::deleteStale());

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Deletes expired proposals in chunks together with their items, creation snapshot and reservations
     * (explicitly, children first, not only via FK cascade). Lock order of 5.4: the creation rows (FOR UPDATE, in
     * id order) first, then today's write row, then the children are deleted, so a step or operation running on
     * one of these creations finishes (or waits) instead of deadlocking.
     */
    private function pruneProposals(CarbonInterface $cutoff, QuotaLedger $ledger): int
    {

        $deleted = 0;
        do {
            $ids = DB::table('proposals')
                ->where('expires_at', '<=', $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();
            if ($ids === []) {
                break;
            }
            if (DB::table('playlist_creations')->whereIn('proposal_id', $ids)->exists()) {
                $ledger->ensureWriteRow(); // outside the transaction (see QuotaLedger::ensureWriteRow)
            }
            $deleted += DB::transaction(function () use ($ids, $ledger) {
                $creationIds = DB::table('playlist_creations')->whereIn('proposal_id', $ids)->orderBy('id')->pluck('id')->all();
                if ($creationIds !== []) {
                    // Locked by primary key, in id order (no gap locks on proposal_id).
                    $creationIds = DB::table('playlist_creations')->whereIn('id', $creationIds)->orderBy('id')->lockForUpdate()->pluck('id')->all();
                    $ledger->withWriteLock(function () use ($creationIds) {
                        DB::table('quota_reservations')->whereIn('creation_id', $creationIds)->delete();
                        DB::table('playlist_creation_items')->whereIn('creation_id', $creationIds)->delete();
                        DB::table('playlist_creations')->whereIn('id', $creationIds)->delete();
                    });
                }
                DB::table('proposal_items')->whereIn('proposal_id', $ids)->delete();

                return DB::table('proposals')->whereIn('id', $ids)->delete();
            }, 3);
        } while (count($ids) === self::CHUNK);

        return $deleted;
    }

    /**
     * The owner's data of creations not used since $cutoff (7 days before the next run): the items are deleted and a
     * non-public playlist id is dropped; the counts and whether a playlist was created stay for S4/S6. A creation
     * that was not completed (paused, needs_check, or a run whose page was closed) is closed as completed and its
     * unused reservation released, so nothing can run on it afterwards. Lock order of 5.4: the creation row, then
     * today's write row, then the items. The last use is checked again under the row lock (an owner may be back).
     */
    private function pruneAuthorizedData(CarbonInterface $cutoff, QuotaLedger $ledger): int
    {
        $stale = fn ($q) => $q->whereNull('authorized_data_pruned_at')
            ->where(fn ($q) => $q->where('owner_last_active_at', '<=', $cutoff)
                ->orWhere(fn ($q) => $q->whereNull('owner_last_active_at')->where('updated_at', '<=', $cutoff)));

        $pruned = 0;
        do {
            $ids = PlaylistCreation::query()->where($stale)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            $ledger->ensureWriteRow(); // outside the transactions (see QuotaLedger::ensureWriteRow)
            foreach ($ids as $id) {
                $pruned += DB::transaction(function () use ($id, $stale, $ledger) {
                    $c = PlaylistCreation::whereKey($id)->where($stale)->lockForUpdate()->first();
                    if ($c === null) {
                        return 0;
                    }

                    return $ledger->withWriteLock(function (WriteLock $lock) use ($c) {
                        $counts = CreationProgress::counts($c);
                        $hadPlaylist = $c->youtube_playlist_id !== null;
                        $lock->release($c->id);
                        PlaylistCreationItem::where('creation_id', $c->id)->delete();
                        $c->fill([
                            'status' => PlaylistCreation::COMPLETED,
                            'pause_reason' => null,
                            'paused_day_pt' => null,
                            'playlist_leased_at' => null,
                            'completed_at' => $c->completed_at ?? now(),
                            'youtube_playlist_id' => $c->privacy_status === 'public' ? $c->youtube_playlist_id : null,
                            'pruned_had_playlist' => $hadPlaylist,
                            'done_count' => $counts['done'],
                            'failed_count' => $counts['failed'],
                            'skipped_count' => $counts['skipped'],
                            'total_count' => $counts['total'],
                            'authorized_data_pruned_at' => now(),
                        ])->save();

                        return 1;
                    });
                }, 3);
            }
        } while (count($ids) === self::CHUNK);

        return $pruned;
    }

    /** Runs one step; a failure is logged (step name and SQLSTATE only, never SQL or bindings) and the rest still run. */
    private function step(string $name, callable $fn): void
    {
        try {
            $this->line("{$name}: ".(int) $fn());
        } catch (Throwable $e) {
            $this->failed = true;
            Log::error('Prune step failed', [
                'step' => $name,
                'sqlstate' => $e instanceof QueryException ? (string) $e->getCode() : null,
                'exception' => $e::class,
            ]);
            $this->error("{$name}: failed");
        }
    }
}
