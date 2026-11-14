<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hourly (policy I3, spec 4.3): deletes the encrypted session rows (owner tokens included) that have been unused for
 * SESSION_LIFETIME minutes, so such a row is gone within about an hour after it can no longer be used.
 */
class PruneSessions extends Command
{
    protected $signature = 'app:prune-sessions';

    protected $description = '操作のないまま SESSION_LIFETIME を過ぎたセッションの行を削除する（毎時）';

    public function handle(): int
    {
        try {
            $this->line('sessions: '.self::deleteStale());
        } catch (QueryException $e) {
            Log::error('Prune step failed', ['step' => 'sessions', 'sqlstate' => (string) $e->getCode(), 'exception' => $e::class]);
            $this->error('sessions: failed');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    public static function deleteStale(): int
    {
        return DB::table(config('session.table') ?: 'sessions')
            ->where('last_activity', '<', now()->getTimestamp() - (int) config('session.lifetime') * 60)
            ->delete();
    }
}
