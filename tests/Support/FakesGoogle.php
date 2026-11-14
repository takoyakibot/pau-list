<?php

namespace Tests\Support;

use App\Services\YouTube\GoogleOAuth;
use App\Services\YouTube\YouTubeReader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

trait FakesGoogle
{
    /** A token endpoint response. Keys overridden with null are removed from the body. */
    protected function fakeToken(array $override = [], int $status = 200): void
    {
        $body = array_filter(array_merge([
            'access_token' => 'ya29.test-access-token',
            'expires_in' => 3599,
            'token_type' => 'Bearer',
            'scope' => GoogleOAuth::SCOPE,
        ], $override), fn ($v) => $v !== null);

        $this->fakeGoogleUrl(GoogleOAuth::TOKEN_URL, fn () => Http::response($body, $status));
    }

    protected function fakeRevoke(int $status = 200): void
    {
        $this->fakeGoogleUrl(GoogleOAuth::REVOKE_URL, fn () => Http::response('', $status));
    }

    /** channels.list?mine=true for the token's account; null = an account without a YouTube channel. */
    protected function fakeMyChannel(?string $channelId, string $title = 'My Channel'): void
    {
        $this->fakeGoogleUrl(YouTubeReader::BASE_URL.'channels?', function (Request $request) use ($channelId, $title) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            if (($q['mine'] ?? null) !== 'true') {
                return null;
            }
            $items = $channelId === null ? [] : [['kind' => 'youtube#channel', 'id' => $channelId, 'snippet' => ['title' => $title]]];

            return Http::response(['kind' => 'youtube#channelListResponse', 'items' => $items]);
        });
    }

    /** @return Collection<int, Request> */
    protected function googleRequests(string $urlPrefix): Collection
    {
        return Http::recorded(fn (Request $r) => str_starts_with($r->url(), $urlPrefix))
            ->map(fn (array $pair) => $pair[0])
            ->values();
    }

    private function fakeGoogleUrl(string $prefix, callable $handler): void
    {
        Http::fake(function (Request $request) use ($prefix, $handler) {
            return str_starts_with($request->url(), $prefix) ? $handler($request) : null;
        });
    }
}
