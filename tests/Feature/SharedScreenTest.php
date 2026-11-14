<?php

namespace Tests\Feature;

use App\Models\ChannelCache;
use App\Models\Proposal;
use App\Support\ProposalKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsNoInlineCode;
use Tests\Support\FakesYouTube;
use Tests\TestCase;

class SharedScreenTest extends TestCase
{
    use AssertsNoInlineCode, FakesYouTube, RefreshDatabase;

    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $this->id = 'UC'.str_repeat('a', 22);
        $now = now();
        ChannelCache::create([
            'channel_id' => $this->id,
            'handle' => '@a',
            'title' => 'チャンネル <A>',
            'thumbnail_url' => null,
            'uploads_playlist_id' => 'UU'.substr($this->id, 2),
            'meta_fetched_at' => $now,
            'cached_since' => $now,
            'last_synced_at' => $now,
            'truncated' => false,
            'expires_at' => $now->addDays(30),
        ]);
        $this->fakeVideos([$this->videoResource('aaaaaaaaaaa', $this->id)]);
    }

    public function test_shown_once_right_after_store(): void
    {
        $res = $this->followingRedirects()
            ->post(route('proposals.store', $this->id), ['title' => '私の案 "x"', 'video_ids' => ['aaaaaaaaaaa']])
            ->assertOk();
        // the flash is consumed by the followed GET, so read the key from the page
        $this->assertSame(1, preg_match('#data-url="[^"]*/p/('.ProposalKey::ROUTE_PATTERN.')"#', $res->getContent(), $m));
        $key = $m[1];
        $this->assertSame(ProposalKey::hash($key), Proposal::sole()->key_hash);
        $url = route('proposals.show', $key);

        $res->assertSee('value="'.$url.'"', false);
        $res->assertSee('data-url="'.$url.'"', false);
        $res->assertSee('data-title="私の案 &quot;x&quot;"', false);
        $res->assertSee('data-channel-title="チャンネル &lt;A&gt;"', false);
        $res->assertSee('data-channel-id="'.$this->id.'"', false);
        $res->assertSee('data-expires-at="2026-12-14T03:00:00Z"', false);
        $res->assertSee('有効期限: 12月14日までに削除されます');
        $res->assertSee('この URL を知っている人は誰でも閲覧できます');
        $res->assertSee('この URL は再表示できません。必ず控えてください');
        $res->assertSee('href="'.$url.'"', false);
        $res->assertSee('id="proposal-shared"', false);
        $res->assertSee('readonly', false);
        $res->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertNoInlineCode($res->getContent());

        $this->get(route('proposals.shared', $key))->assertRedirect($url);
    }

    public function test_shared_page_has_attribution(): void
    {
        $this->followingRedirects()
            ->post(route('proposals.store', $this->id), ['title' => 't', 'video_ids' => ['aaaaaaaaaaa']])
            ->assertOk()
            ->assertSee('<p class="text-xs text-gray-600">データ提供: YouTube</p>', false);
    }

    public function test_direct_access_without_flash_redirects_to_preview(): void
    {
        $key = ProposalKey::generate();
        $this->get(route('proposals.shared', $key))->assertRedirect(route('proposals.show', $key));

        // a flashed key for a different proposal does not unlock this one
        $this->withSession(['proposal_key_shown' => ProposalKey::hash(ProposalKey::generate())])
            ->get(route('proposals.shared', $key))
            ->assertRedirect(route('proposals.show', $key));
    }

    public function test_raw_key_in_session_does_not_unlock(): void
    {
        $res = $this->post(route('proposals.store', $this->id), ['title' => 't', 'video_ids' => ['aaaaaaaaaaa']]);
        $this->assertSame(1, preg_match('#/p/('.ProposalKey::ROUTE_PATTERN.')/shared$#', (string) $res->headers->get('Location'), $m));
        $this->withSession(['proposal_key_shown' => $m[1]])
            ->get(route('proposals.shared', $m[1]))
            ->assertRedirect(route('proposals.show', $m[1]));
    }

    public function test_flash_matching_but_expired_proposal_redirects(): void
    {
        $res = $this->post(route('proposals.store', $this->id), ['title' => 't', 'video_ids' => ['aaaaaaaaaaa']]);
        $this->assertSame(1, preg_match('#/p/('.ProposalKey::ROUTE_PATTERN.')/shared$#', (string) $res->headers->get('Location'), $m));
        $key = $m[1];
        $this->travel(31)->days();
        $this->withSession(['proposal_key_shown' => ProposalKey::hash($key)])
            ->get(route('proposals.shared', $key))
            ->assertRedirect(route('proposals.show', $key));
    }

    public function test_home_has_my_proposals_container_and_no_inline_code(): void
    {
        $res = $this->get('/')->assertOk();
        $res->assertSee('id="my-proposals"', false);
        $res->assertSee('この端末で作った提案');
        $res->assertDontSee('X-Robots-Tag');
        $this->assertNull($res->headers->get('X-Robots-Tag'));
        $this->assertNoInlineCode($res->getContent());
    }
}
