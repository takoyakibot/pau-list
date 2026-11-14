<?php

/*
 * Child process for MySqlConcurrentCreationTest: runs one creation operation on its own MySQL connection,
 * starting at a shared moment so that the workers overlap. Google is faked inside this process
 * (Http::fake + preventStrayRequests); playlists.insert answers after SLOW_INSERT_SECONDS.
 *
 * Usage: php tests/Support/mysql/creation-worker.php {start|step|abort} {proposalId|creationId} {startAt unix float}
 * Prints JSON: {"result": "...", "playlist_posts": n, "item_posts": n}.
 */

use App\Models\PlaylistCreation;
use App\Models\Proposal;
use App\Services\Creation\CreationLifecycle;
use App\Services\Creation\CreationRunner;
use App\Services\Creation\CreationStarter;
use App\Services\YouTube\YouTubeReader;
use App\Support\OwnerLogin;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

const SLOW_INSERT_SECONDS = 3;

[$action, $id, $startAt] = [(string) ($argv[1] ?? ''), (int) ($argv[2] ?? 0), (float) ($argv[3] ?? 0)];

Http::preventStrayRequests();
Http::fake(function (Request $request) {
    $url = $request->url();
    if ($request->method() === 'POST' && str_starts_with($url, YouTubeReader::BASE_URL.'playlists?')) {
        sleep(SLOW_INSERT_SECONDS); // the other worker's step runs meanwhile

        return Http::response(['kind' => 'youtube#playlist', 'id' => 'PLworker']);
    }
    if ($request->method() === 'POST' && str_starts_with($url, YouTubeReader::BASE_URL.'playlistItems?')) {
        return Http::response(['kind' => 'youtube#playlistItem', 'id' => 'Iworker']);
    }
    if ($request->method() === 'GET' && str_starts_with($url, YouTubeReader::BASE_URL.'videos?')) {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return Http::response(['items' => array_map(fn (string $v) => [
            'id' => $v,
            'snippet' => [
                'title' => 'Video '.$v,
                'channelId' => 'UCaaaaaaaaaaaaaaaaaaaaaa',
                'channelTitle' => 'Channel',
                'publishedAt' => '2025-01-02T03:04:05Z',
                'thumbnails' => ['medium' => ['url' => 'https://i.ytimg.com/vi/'.$v.'/mqdefault.jpg']],
            ],
            'contentDetails' => ['duration' => 'PT3M'],
            'status' => ['privacyStatus' => 'public'],
        ], explode(',', (string) ($q['id'] ?? '')))]);
    }

    return null;
});

$owner = fn (string $channelId) => new OwnerLogin('ya29.worker', CarbonImmutable::now()->addHour(), $channelId, 'ch');

if ($startAt > microtime(true)) {
    time_sleep_until($startAt);
}

$result = match ($action) {
    'start' => (function () use ($app, $id, $owner) {
        $proposal = Proposal::findOrFail($id);
        $started = $app->make(CreationStarter::class)->start($proposal, $owner($proposal->channel_id), [
            'title' => 'Worker list',
            'description' => '',
            'privacy' => 'private',
            'video_ids' => ['aaaaaaaaaaa'],
        ]);

        return $started->created ? 'created' : 'existing';
    })(),
    'step' => (function () use ($app, $id, $owner) {
        $creation = PlaylistCreation::findOrFail($id);
        $app->make(CreationRunner::class)->step($creation, $owner($creation->owner_channel_id));

        return 'stepped';
    })(),
    'abort' => (function () use ($app, $id) {
        $app->make(CreationLifecycle::class)->abort(PlaylistCreation::findOrFail($id));

        return 'aborted';
    })(),
    default => throw new InvalidArgumentException('Unknown action.'),
};

$posts = fn (string $prefix) => Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_starts_with($r->url(), $prefix))->count();

echo json_encode([
    'result' => $result,
    'playlist_posts' => $posts(YouTubeReader::BASE_URL.'playlists?'),
    'item_posts' => $posts(YouTubeReader::BASE_URL.'playlistItems?'),
]);
