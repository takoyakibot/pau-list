<?php

namespace Tests\Feature;

use App\Support\OwnerSession;
use App\Support\ProposalKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsNoInlineCode;
use Tests\Support\CreatesProposals;
use Tests\TestCase;

class OwnerEditAccessTest extends TestCase
{
    use AssertsNoInlineCode, CreatesProposals, RefreshDatabase;

    public function test_edit_without_login_redirects_to_preview_with_notice(): void
    {
        $key = $this->createProposal();
        $this->get("/p/$key/edit")->assertRedirect(route('proposals.show', $key))
            ->assertSessionHas('owner_notice', 'ログインの有効期限が切れたか、ログインしていません。もう一度ログインしてください');
    }

    public function test_expired_owner_is_sent_back_to_preview_at_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $key = $this->createProposal();
        $this->actAsOwner($this->channelId, now()->toImmutable()->addSeconds(5));
        $this->get("/p/$key/edit")->assertOk();
        $this->travel(5)->seconds();
        $this->get("/p/$key/edit")->assertRedirect(route('proposals.show', $key))->assertSessionHas('owner_notice');
        $this->assertFalse(session()->has(OwnerSession::OWNER_KEY));
    }

    public function test_matching_owner_sees_edit_page(): void
    {
        $key = $this->createProposal();
        $res = $this->actAsOwner($this->channelId, title: '<script>x</script>')->get("/p/$key/edit")
            ->assertOk()->assertSee('ログイン中: &lt;script&gt;x&lt;/script&gt;', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertDontSee('ya29.session-token');
        $this->assertNoInlineCode($res->getContent());
    }

    public function test_mismatched_channel_gets_403_mismatch_page(): void
    {
        $key = $this->createProposal(['channel_title' => '対象チャンネル']);
        $res = $this->actAsOwner('UC'.str_repeat('z', 22), title: '別チャンネル')->get("/p/$key/edit")->assertForbidden();
        $res->assertSee('ログイン中のチャンネルはこの提案の対象ではありません')
            ->assertSee('この提案は「対象チャンネル」向けです。ログイン中のチャンネル: 「別チャンネル」')
            ->assertSee('このチャンネルには作成できません')
            ->assertSee('別のチャンネルでログインし直す')
            ->assertSee('ブランドアカウントのチャンネルは、Google のアカウント選択画面で対象のチャンネルを選んでください')
            ->assertSee('別のチャンネルに作りたい場合は、そのチャンネルを対象に提案を作り直してもらってください')
            ->assertSee('/auth/google?p='.$key, false) // Task 4 で route('owner.login', ['p' => $key]) の href 検査に置き換える
            ->assertDontSee('ya29.session-token');
        $this->assertNoInlineCode($res->getContent());
    }

    public function test_unknown_or_expired_proposal_is_404_even_when_logged_in(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $expired = $this->createProposal(['expires_at' => now()]);
        $this->actAsOwner($this->channelId)->get("/p/$expired/edit")->assertNotFound();
        $this->actAsOwner($this->channelId)->get('/p/'.ProposalKey::generate().'/edit')->assertNotFound();
    }

    public function test_unknown_proposal_is_404_without_login(): void
    {
        $this->get('/p/'.ProposalKey::generate().'/edit')->assertNotFound();
    }

    public function test_edit_rejects_non_navigation_fetch(): void
    {
        $key = $this->createProposal();
        $this->actAsOwner($this->channelId)->withHeaders(['Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty'])
            ->get("/p/$key/edit")->assertStatus(400);
    }
}
