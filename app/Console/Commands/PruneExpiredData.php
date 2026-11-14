<?php

namespace App\Console\Commands;

use App\Services\ChannelVideoCatalog;
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
     * The batch runs once a day, so it deletes everything that expires before the next run:
     * nothing outlives its displayed delete date (display still hides rows at expires_at <= now()).
     */
    public const LOOKAHEAD_HOURS = 24;

    private const CHUNK = 500;

    protected $signature = 'app:prune-expired';

    protected $description = '保持期限切れのデータを削除する（4.3）';

    private bool $failed = false;

    public function handle(ChannelVideoCatalog $catalog): int
    {
        $this->failed = false;
        $now = now()->toImmutable();
        $cutoff = $now->addHours(self::LOOKAHEAD_HOURS);
        $timestamp = $now->getTimestamp();

        $this->step('proposals', fn () => $this->pruneProposals($cutoff));

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

        $this->step('sessions', fn () => DB::table(config('session.table') ?: 'sessions')
            ->where('last_activity', '<', $timestamp - (int) config('session.lifetime') * 60)
            ->delete());

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Deletes expired proposals in chunks together with their items, creation snapshot and reservations
     * (explicitly, children first, not only via FK cascade).
     */
    private function pruneProposals(CarbonInterface $cutoff): int
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
            $deleted += DB::transaction(function () use ($ids) {
                $creationIds = DB::table('playlist_creations')->whereIn('proposal_id', $ids)->pluck('id')->all();
                if ($creationIds !== []) {
                    DB::table('quota_reservations')->whereIn('creation_id', $creationIds)->delete();
                    DB::table('playlist_creation_items')->whereIn('creation_id', $creationIds)->delete();
                    DB::table('playlist_creations')->whereIn('id', $creationIds)->delete();
                }
                DB::table('proposal_items')->whereIn('proposal_id', $ids)->delete();

                return DB::table('proposals')->whereIn('id', $ids)->delete();
            });
        } while (count($ids) === self::CHUNK);

        return $deleted;
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
