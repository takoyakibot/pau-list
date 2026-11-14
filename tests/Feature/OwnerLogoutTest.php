<?php

namespace Tests\Feature;

use App\Services\YouTube\GoogleOAuth;
use App\Services\YouTube\YouTubeReader;
use App\Support\OwnerSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\AssertsNoInlineCode;
use Tests\Support\CreatesProposals;
use Tests\Support\EnforcesCsrf;
use Tests\Support\FakesGoogle;
use Tests\TestCase;

class OwnerLogoutTest extends TestCase
{
    use AssertsNoInlineCode, CreatesProposals, EnforcesCsrf, FakesGoogle, RefreshDatabase;

    public function test_logout_revokes_and_invalidates_session(): void
    {
        $key = $this->createProposal();
        $this->fakeRevoke();
        $this->actAsOwner($this->channelId, token: 'ya29.logout-me');
        session()->put(OwnerSession::UNLISTED_KEY, ['channel_id' => $this->channelId, 'fetched_at' => now()->getTimestamp(), 'videos' => []]);
        $this->post(route('owner.logout'))->assertRedirect(route('owner.logged-out'));
        $this->assertSame(['token' => 'ya29.logout-me'], $this->googleRequests(GoogleOAuth::REVOKE_URL)->sole()->data());
        $this->assertFalse(session()->has(OwnerSession::OWNER_KEY));
        $this->assertFalse(session()->has(OwnerSession::UNLISTED_KEY));
        $this->get(route('proposals.edit', $key))->assertRedirect(route('proposals.show', $key));
        $res = $this->get(route('owner.logged-out'))->assertOk()
            ->assertSee('ログアウトしました')
            ->assertSee('href="https://myaccount.google.com/permissions"', false)
            ->assertSee('href="'.route('home').'"', false)
            ->assertDontSee('取り消しに失敗しました');
        $this->assertNoInlineCode($res->getContent());
    }

    public function test_revoke_failure_still_logs_out(): void
    {
        $this->fakeRevoke(503);
        $this->actAsOwner($this->channelId)->post(route('owner.logout'))->assertRedirect(route('owner.logged-out'));
        $this->assertFalse(session()->has(OwnerSession::OWNER_KEY));
        $this->get(route('owner.logged-out'))
            ->assertSee('Google 側の承認の取り消しに失敗しました。下のリンクから取り消してください')
            ->assertSee('href="https://myaccount.google.com/permissions"', false);
    }

    public function test_expired_token_is_still_revoked(): void
    {
        $this->fakeRevoke();
        $this->actAsOwner($this->channelId, expiresAt: now()->toImmutable()->subMinutes(5), token: 'ya29.expired')
            ->post(route('owner.logout'))->assertRedirect(route('owner.logged-out'));
        $this->assertSame(['token' => 'ya29.expired'], $this->googleRequests(GoogleOAuth::REVOKE_URL)->sole()->data());
        $this->assertFalse(session()->has(OwnerSession::OWNER_KEY));
    }

    public function test_logout_without_login_skips_revoke(): void
    {
        $this->fakeRevoke();
        $this->post(route('owner.logout'))->assertRedirect(route('owner.logged-out'));
        $this->assertCount(0, $this->googleRequests(GoogleOAuth::REVOKE_URL));
        $this->get(route('owner.logged-out'))->assertSee('ログアウトしました')->assertDontSee('取り消しに失敗しました');
    }

    public function test_logout_requires_post(): void
    {
        $this->actAsOwner($this->channelId)->get('/logout')->assertStatus(405);
        $this->assertTrue(session()->has(OwnerSession::OWNER_KEY));
        // CSRF is not checked while running tests; the route must be in the standard web group (VerifyCsrfToken).
        $this->assertContains('web', Route::getRoutes()->getByName('owner.logout')->gatherMiddleware());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('owner.logout')->methods());
    }

    public function test_logout_without_csrf_token_is_419_and_keeps_the_login(): void
    {
        $this->enforceCsrf();
        $this->fakeRevoke();
        $this->actAsOwner($this->channelId)->get('/');
        $this->post(route('owner.logout'))->assertStatus(419);
        $this->post(route('owner.logout'), ['_token' => 'wrong'])->assertStatus(419);
        $this->post(route('owner.logout'), [], ['Sec-Fetch-Site' => 'cross-site'])->assertStatus(419);
        $this->assertTrue(session()->has(OwnerSession::OWNER_KEY));
        $this->assertCount(0, $this->googleRequests(GoogleOAuth::REVOKE_URL));

        $this->post(route('owner.logout'), ['_token' => session()->token()])->assertRedirect(route('owner.logged-out'));
        $this->assertFalse(session()->has(OwnerSession::OWNER_KEY));
    }

    public function test_csrf_token_changes_on_logout(): void
    {
        $this->fakeRevoke();
        $this->actAsOwner($this->channelId)->get('/');
        $before = session()->token();
        $this->post(route('owner.logout'))->assertRedirect(route('owner.logged-out'));
        $this->assertNotSame($before, session()->token());
    }

    public function test_session_id_changes_on_logout(): void
    {
        $this->fakeRevoke();
        $this->actAsOwner($this->channelId)->get('/');
        $before = session()->getId();
        $this->withCookie(config('session.cookie'), $before)->post(route('owner.logout'));
        $this->assertNotSame($before, session()->getId());
    }

    public function test_logout_form_is_on_owner_pages(): void
    {
        $key = $this->createProposal();
        $form = 'action="'.route('owner.logout').'"';
        $button = 'ログアウト（Google 側の承認も取り消します）';

        // S4 while logged in (matching channel), and while logged in as another channel.
        $this->actAsOwner($this->channelId)->get(route('proposals.show', $key))->assertSee($form, false)->assertSee($button);
        $this->actAsOwner('UC'.str_repeat('z', 22))->get(route('proposals.show', $key))->assertSee($form, false);
        // S5.
        Http::fake(fn ($request) => str_starts_with($request->url(), YouTubeReader::BASE_URL) ? Http::response(['items' => []]) : null);
        $this->actAsOwner($this->channelId)->get(route('proposals.edit', $key))->assertOk()->assertSee($form, false)->assertSee($button);
        // Mismatch page.
        $this->actAsOwner('UC'.str_repeat('z', 22))->get(route('proposals.edit', $key))->assertStatus(403)->assertSee($form, false)->assertSee('name="_token"', false);
        // S4 logged out: no logout form.
        session()->forget(OwnerSession::OWNER_KEY);
        $this->get(route('proposals.show', $key))->assertDontSee($form, false);
    }
}
