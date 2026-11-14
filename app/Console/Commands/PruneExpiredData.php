<?php

namespace App\Console\Commands;

use App\Services\ChannelVideoCatalog;
use App\Support\QuotaClock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneExpiredData extends Command
{
    /** quota_usage holds no YouTube data; kept for 90 days (spec 4.3). */
    public const QUOTA_USAGE_RETENTION_DAYS = 90;

    private const CHUNK = 500;

    protected $signature = 'app:prune-expired';

    protected $description = '保持期限切れのデータを削除する（4.3）';

    public function handle(ChannelVideoCatalog $catalog): int
    {
        $now = now();
        $timestamp = $now->getTimestamp();

        $this->report('proposals', $this->pruneProposals());

        // Per-channel lock path: deletes the channel row and all its video rows and records the expiration.
        $this->report('channel_caches', $catalog->expireStale());

        $this->report('channel_cache_expirations', DB::table('channel_cache_expirations')
            ->where('expired_at', '<=', $now->copy()->subDays((int) config('listmaker.retention_days')))
            ->delete());

        $cutoffDay = CarbonImmutable::parse(QuotaClock::todayPt())
            ->subDays(self::QUOTA_USAGE_RETENTION_DAYS)
            ->format('Y-m-d');
        $this->report('quota_usage', DB::table('quota_usage')->where('day_pt', '<', $cutoffDay)->delete());

        // The database cache store never deletes expired rows by itself.
        $this->report('cache', DB::table(config('cache.stores.database.table') ?: 'cache')
            ->where('expiration', '<=', $timestamp)
            ->delete());
        $this->report('cache_locks', DB::table(config('cache.stores.database.lock_table') ?: 'cache_locks')
            ->where('expiration', '<=', $timestamp)
            ->delete());

        $this->report('sessions', DB::table(config('session.table') ?: 'sessions')
            ->where('last_activity', '<', $timestamp - (int) config('session.lifetime') * 60)
            ->delete());

        return self::SUCCESS;
    }

    /** Deletes expired proposals and their items in chunks (items explicitly, not only via FK cascade). */
    private function pruneProposals(): int
    {
        $deleted = 0;
        do {
            $ids = DB::table('proposals')
                ->where('expires_at', '<=', now())
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();
            if ($ids === []) {
                break;
            }
            $deleted += DB::transaction(function () use ($ids) {
                DB::table('proposal_items')->whereIn('proposal_id', $ids)->delete();

                return DB::table('proposals')->whereIn('id', $ids)->delete();
            });
        } while (count($ids) === self::CHUNK);

        return $deleted;
    }

    private function report(string $table, int $count): void
    {
        $this->line("{$table}: {$count}");
    }
}
