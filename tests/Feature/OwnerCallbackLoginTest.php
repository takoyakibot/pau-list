<?php

namespace Tests\Feature;

use App\Services\YouTube\GoogleOAuth;
use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\YouTubeOwnerClient;
use App\Services\YouTube\YouTubeReader;
use App\Support\OwnerSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesProposals;
use Tests\Support\FakesGoogle;
use Tests\TestCase;

class OwnerCallbackLoginTest extends TestCase
{
    use CreatesProposals, FakesGoogle, RefreshDatabase;

    private const REDIRECT = 'http://localhost:8000/auth/google/callback';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google' => [
            'client_id' => 'cid.apps.googleusercontent.com',
            'client_secret' => 'test-client-secret',
            'redirect' => self::REDIRECT,
        ]]);
    }

    /** Starts a login for the proposal and returns the state sent to Google. */
    private function begin(string $key): string
    {
        $loc = $this->get('/auth/google?p='.$key)->assertRedirect()->headers->get('Location');
        parse_str(parse_url($loc, PHP_URL_QUERY), $q);

        return $q['state'];
    }

    private function loginCallback(string $key, array $extra = []): TestResponse
    {
        $state = $this->begin($key);

        return $this->get('/auth/google/callback?'.http_build_query(array_merge(['state' => $state, 'code' => '4/0Acode'], $extra)));
    }

    /** Sends the session cookie like a browser would, so the request keeps the session id unless it is regenerated. */
    private function withSessionCookie(string $id): static
    {
        return $this->withCookie(config('session.cookie'), $id);
    }

    /** The edit page also reads the channel list and the owner's uploads: answer them with empty lists. */
    private function fakeEditPageReads(): void
    {
        Http::fake(fn ($request) => str_starts_with($request->url(), YouTubeReader::BASE_URL)
            ? Http::response(['items' => []])
            : null);
    }

    public function test_success_logs_in_and_redirects_to_edit(): void
    {
        $key = $this->createProposal();
        $this->fakeToken();
        $this->fakeMyChannel($this->channelId, 'チャンネル A');
        $res = $this->loginCallback($key, ['code' => '4/0Acode-xyz']);
        $res->assertStatus(302)->assertRedirect(route('proposals.edit', $key))->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringNotContainsString('4/0Acode-xyz', $res->getContent());
        $this->assertFalse(session()->has(OwnerSession::PENDING_KEY));
        $owner = app(OwnerSession::class)->current();
        $this->assertSame($this->channelId, $owner->channelId);
        $this->assertSame('チャンネル A', $owner->channelTitle);
        $this->assertSame('ya29.test-access-token', $owner->accessToken);
        $this->assertSame(1, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
        $this->assertSame(['4/0Acode-xyz'], $this->googleRequests(GoogleOAuth::TOKEN_URL)->map(fn ($r) => $r->data()['code'])->all());
        $this->assertCount(0, $this->googleRequests(GoogleOAuth::REVOKE_URL));
        $this->fakeEditPageReads();
        $this->get(route('proposals.edit', $key))->assertOk();
    }

    public function test_token_request_carries_the_pending_verifier(): void
    {
        $key = $this->createProposal();
        $this->fakeToken();
        $this->fakeMyChannel($this->channelId);
        $state = $this->begin($key);
        $verifier = session(OwnerSession::PENDING_KEY)['verifier'];
        $this->get('/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'c']))->assertRedirect(route('proposals.edit', $key));
        $data = $this->googleRequests(GoogleOAuth::TOKEN_URL)->sole()->data();
        $this->assertSame($verifier, $data['code_verifier']);
        $this->assertSame('authorization_code', $data['grant_type']);
    }

    public function test_mismatched_login_lands_on_mismatch_page(): void
    {
        $key = $this->createProposal(['channel_title' => '対象チャンネル']);
        $this->fakeToken();
        $this->fakeMyChannel('UC'.str_repeat('z', 22), '別チャンネル');
        $this->loginCallback($key)->assertRedirect(route('proposals.edit', $key));
        $this->get(route('proposals.edit', $key))->assertForbidden()
            ->assertSee('ログイン中のチャンネルはこの提案の対象ではありません')
            ->assertSee('ログイン中のチャンネル: 「別チャンネル」');
        $this->assertCount(0, $this->googleRequests(GoogleOAuth::REVOKE_URL));
    }

    public function test_redirect_uri_is_identical_in_both_requests(): void
    {
        $key = $this->createProposal();
        $this->fakeToken();
        $this->fakeMyChannel($this->channelId);
        $loc = $this->get('/auth/google?p='.$key)->headers->get('Location');
        parse_str(parse_url($loc, PHP_URL_QUERY), $q);
        $this->get('/auth/google/callback?'.http_build_query(['state' => $q['state'], 'code' => 'c']))->assertRedirect(route('proposals.edit', $key));
        $sent = $this->googleRequests(GoogleOAuth::TOKEN_URL)->sole()->data()['redirect_uri'];
        $this->assertSame(self::REDIRECT, $q['redirect_uri']);
        $this->assertSame(self::REDIRECT, $sent);
        $this->assertSame(config('services.google.redirect'), $sent);
    }

    public function test_session_id_changes_on_login(): void
    {
        $key = $this->createProposal();
        $this->fakeToken();
        $this->fakeMyChannel($this->channelId);
        $state = $this->begin($key);
        $before = session()->getId();
        $this->withSessionCookie($before)
            ->get('/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'c']))->assertRedirect(route('proposals.edit', $key));
        $this->assertNotSame($before, session()->getId());
    }

    public function test_csrf_token_changes_on_login(): void
    {
        $key = $this->createProposal();
        $this->fakeToken();
        $this->fakeMyChannel($this->channelId);
        $state = $this->begin($key);
        $before = session()->token();
        $this->assertNotEmpty($before);
        $this->withSessionCookie(session()->getId())
            ->get('/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'c']))->assertRedirect(route('proposals.edit', $key));
        $this->assertNotSame($before, session()->token());
    }

    public function test_session_id_is_kept_when_login_fails(): void
    {
        $key = $this->createProposal();
        $this->fakeToken([], 500);
        $state = $this->begin($key);
        $before = session()->getId();
        $this->withSessionCookie($before)
            ->get('/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'c']))->assertRedirect(route('proposals.show', $key));
        $this->assertSame($before, session()->getId());
    }

    public function test_session_holds_only_owner_fields(): void
    {
        $key = $this->createProposal();
        $this->fakeToken(['refresh_token' => '1//refresh-secret', 'id_token' => 'eyJ.id-token-secret']);
        $this->fakeMyChannel($this->channelId);
        $this->loginCallback($key)->assertRedirect(route('proposals.edit', $key));
        $this->assertSame(['access_token', 'channel_id', 'channel_title', 'expires_at'], collect(session(OwnerSession::OWNER_KEY))->keys()->sort()->values()->all());
        $this->assertStringNotContainsString('refresh-secret', json_encode(session()->all()));
        $this->assertStringNotContainsString('id-token-secret', json_encode(session()->all()));
    }

    public function test_relogin_overwrites_existing_owner_without_revoking(): void
    {
        $key = $this->createProposal();
        $this->actAsOwner('UC'.str_repeat('z', 22), title: '前のチャンネル', token: 'ya29.old-token');
        $this->fakeToken();
        $this->fakeMyChannel($this->channelId, 'チャンネル A');
        $this->loginCallback($key)->assertRedirect(route('proposals.edit', $key));
        $owner = app(OwnerSession::class)->current();
        $this->assertSame($this->channelId, $owner->channelId);
        $this->assertSame('ya29.test-access-token', $owner->accessToken);
        $this->assertCount(0, $this->googleRequests(GoogleOAuth::REVOKE_URL));
    }

    public function test_insufficient_scope_revokes_and_explains(): void
    {
        $key = $this->createProposal();
        $this->fakeToken(['scope' => 'https://www.googleapis.com/auth/youtube.readonly']);
        $this->fakeRevoke();
        $this->loginCallback($key)->assertRedirect(route('proposals.show', $key));
        $this->assertSame(['token' => 'ya29.test-access-token'], $this->googleRequests(GoogleOAuth::REVOKE_URL)->sole()->data());
        $this->assertNull(app(OwnerSession::class)->current());
        $this->assertCount(0, $this->googleRequests(YouTubeReader::BASE_URL));
        $this->get(route('proposals.show', $key))
            ->assertSee('YouTube の管理が許可されなかったため作成できません。もう一度ログインし、YouTube の管理を許可してください');
    }

    public function test_missing_scope_field_is_treated_as_insufficient(): void
    {
        $key = $this->createProposal();
        $this->fakeToken(['scope' => null]);
        $this->fakeRevoke();
        $this->loginCallback($key)->assertRedirect(route('proposals.show', $key));
        $this->assertCount(1, $this->googleRequests(GoogleOAuth::REVOKE_URL));
        $this->assertNull(app(OwnerSession::class)->current());
        $this->get(route('proposals.show', $key))->assertSee('YouTube の管理が許可されなかったため');
    }

    public function test_account_without_channel_is_revoked_and_explained(): void
    {
        $key = $this->createProposal();
        $this->fakeToken();
        $this->fakeMyChannel(null);
        $this->fakeRevoke();
        $this->loginCallback($key)->assertRedirect(route('proposals.show', $key));
        $this->assertSame(['token' => 'ya29.test-access-token'], $this->googleRequests(GoogleOAuth::REVOKE_URL)->sole()->data());
        $this->assertNull(app(OwnerSession::class)->current());
        $this->get(route('proposals.show', $key))
            ->assertSee('このアカウントには YouTube チャンネルがありません。チャンネルのあるアカウントでログインしてください');
    }

    public function test_channel_lookup_failure_revokes(): void
    {
        $key = $this->createProposal();
        $this->fakeToken();
        $this->fakeRevoke();
        Http::fake(fn ($request) => str_starts_with($request->url(), YouTubeReader::BASE_URL.'channels?')
            ? Http::response(['error' => ['code' => 500, 'message' => '<b>INTERNAL-DETAIL</b>']], 500)
            : null);
        $this->loginCallback($key)->assertRedirect(route('proposals.show', $key));
        $this->assertCount(1, $this->googleRequests(GoogleOAuth::REVOKE_URL));
        $this->assertNull(app(OwnerSession::class)->current());
        $this->get(route('proposals.show', $key))
            ->assertSee('YouTube のチャンネルを確認できませんでした。時間をおいてもう一度ログインしてください')
            ->assertDontSee('INTERNAL-DETAIL');
    }

    public function test_token_failure_returns_to_preview_without_retry(): void
    {
        $key = $this->createProposal();
        $this->fakeToken(['error' => 'server_error'], 500);
        $this->fakeMyChannel($this->channelId);
        $this->loginCallback($key)->assertRedirect(route('proposals.show', $key));
        $this->assertCount(1, $this->googleRequests(GoogleOAuth::TOKEN_URL));
        $this->assertCount(0, $this->googleRequests(YouTubeReader::BASE_URL));
        $this->assertCount(0, $this->googleRequests(GoogleOAuth::REVOKE_URL));
        $this->assertNull(app(OwnerSession::class)->current());
        $this->assertFalse(session()->has(OwnerSession::PENDING_KEY));
        $this->get(route('proposals.show', $key))
            ->assertSee('Google との通信に失敗しました。もう一度ログインしてください');
    }

    public function test_unexpected_failure_after_exchange_still_revokes_the_token(): void
    {
        $key = $this->createProposal();
        $this->fakeToken();
        $this->fakeRevoke();
        $this->mock(YouTubeOwnerClient::class, fn ($m) => $m->shouldReceive('myChannel')->andThrow(new \RuntimeException('database is down')));
        $this->loginCallback($key)->assertStatus(500);
        $this->assertSame(['token' => 'ya29.test-access-token'], $this->googleRequests(GoogleOAuth::REVOKE_URL)->sole()->data());
        $this->assertNull(app(OwnerSession::class)->current());
    }
}
