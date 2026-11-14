<?php

namespace Tests\Feature;

use App\Services\YouTube\QuotaLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesYouTube;
use Tests\TestCase;

/**
 * Cross-site pages must not be able to make a visitor's browser spend our YouTube read budget.
 */
class FetchMetadataTest extends TestCase
{
    use FakesYouTube, RefreshDatabase;

    private const VID = 'abcdefghijk';

    private function ch(): string
    {
        return 'UC'.str_repeat('a', 22);
    }

    private function lookupUrl(): string
    {
        return route('channels.video', $this->ch()).'?url='.self::VID;
    }

    public function test_lookup_allows_same_origin_or_legacy_xhr(): void
    {
        $this->fakeVideos([$this->videoResource(self::VID, $this->ch())]);
        $this->get($this->lookupUrl(), ['Sec-Fetch-Site' => 'same-origin'])->assertOk();
        $this->get($this->lookupUrl(), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->get($this->lookupUrl(), ['Sec-Fetch-Site' => 'same-origin', 'X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    }

    /** @return array<string, array{array<string, string>}> */
    public static function rejectedLookupHeaders(): array
    {
        return [
            'no headers' => [[]],
            'cross-site' => [['Sec-Fetch-Site' => 'cross-site']],
            'same-site' => [['Sec-Fetch-Site' => 'same-site']],
            'none (typed in address bar)' => [['Sec-Fetch-Site' => 'none']],
            'cross-site with XHR header' => [['Sec-Fetch-Site' => 'cross-site', 'X-Requested-With' => 'XMLHttpRequest']],
            'wrong XHR value' => [['X-Requested-With' => 'fetch']],
        ];
    }

    #[DataProvider('rejectedLookupHeaders')]
    public function test_lookup_rejects_other_requests_before_api_and_limiter(array $headers): void
    {
        Http::fake();
        // More than the 30/min video-lookup limit: rejected requests must not consume it.
        for ($i = 0; $i < 35; $i++) {
            $this->get($this->lookupUrl(), $headers + ['Accept' => 'application/json'])
                ->assertForbidden()
                ->assertExactJson(['message' => 'この操作はこの画面からのみ行えます']);
        }
        Http::assertNothingSent();
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::READ));

        Http::swap(new Factory);
        $this->fakeVideos([$this->videoResource(self::VID, $this->ch())]);
        $this->get($this->lookupUrl(), ['Sec-Fetch-Site' => 'same-origin'])->assertOk();
    }

    /** @return array<string, array{array<string, string>}> */
    public static function rejectedPageHeaders(): array
    {
        return [
            'img' => [['Sec-Fetch-Mode' => 'no-cors', 'Sec-Fetch-Dest' => 'image']],
            'iframe' => [['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'iframe']],
            'fetch' => [['Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty']],
            'mode only' => [['Sec-Fetch-Mode' => 'no-cors']],
            'dest only' => [['Sec-Fetch-Dest' => 'script']],
        ];
    }

    #[DataProvider('rejectedPageHeaders')]
    public function test_page_gets_reject_non_navigation_before_api(array $headers): void
    {
        Http::fake();
        $this->get(route('channels.propose', $this->ch()), $headers)->assertStatus(400);
        $this->get('/channels?q='.urlencode('@foo'), $headers)->assertStatus(400);
        Http::assertNothingSent();
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::READ));
    }

    public function test_page_gets_allow_navigation_and_missing_headers(): void
    {
        $this->fakeChannel($this->ch(), ['handle' => '@foo']);
        $this->fakeUploads('UU'.str_repeat('a', 22), [[$this->uploadItem(self::VID, '2026-01-01T00:00:00Z')]]);
        $nav = ['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Site' => 'cross-site'];

        $this->get('/channels?q='.urlencode('@foo'), $nav)->assertRedirect(route('channels.propose', $this->ch()));
        $this->get(route('channels.propose', $this->ch()), $nav)->assertOk();
        $this->get(route('channels.propose', $this->ch()))->assertOk();
    }
}
