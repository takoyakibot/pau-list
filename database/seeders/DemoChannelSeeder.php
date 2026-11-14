<?php

namespace Database\Seeders;

use App\Models\ChannelCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Local visual-check data only (fictional, not derived from the YouTube API).
 * Not called from DatabaseSeeder.
 */
class DemoChannelSeeder extends Seeder
{
    public function run(): void
    {
        $id = 'UC'.str_repeat('D', 22);
        $now = now();

        DB::table('channel_cache_videos')->where('channel_id', $id)->delete();
        ChannelCache::query()->whereKey($id)->delete();

        ChannelCache::create([
            'channel_id' => $id,
            'handle' => '@demo-channel',
            'title' => 'デモチャンネル（架空）',
            'thumbnail_url' => null,
            'uploads_playlist_id' => 'UU'.substr($id, 2),
            'meta_fetched_at' => $now,
            'cached_since' => $now,
            'last_synced_at' => $now,
            'truncated' => false,
            'expires_at' => $now->copy()->addDays(30),
        ]);

        $rows = [];
        for ($i = 1; $i <= 120; $i++) {
            $rows[] = [
                'channel_id' => $id,
                'video_id' => 'demo'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'title' => ($i % 3 === 0 ? 'ＡＢＣ 配信 ' : ($i % 2 === 0 ? 'abc 雑談 ' : 'ゲーム実況 ')).'#'.$i,
                'thumbnail_url' => null,
                'published_at' => $now->copy()->subDays($i),
                'fetched_at' => $now,
            ];
        }
        DB::table('channel_cache_videos')->insert($rows);

        $this->command?->info('Demo channel: /channels/'.$id.'/propose');
    }
}
