<?php

namespace Tests\Feature;

use App\Services\YouTube\GoogleOAuth;
use App\Support\OwnerSession;
use App\Support\Pkce;
use App\Support\ProposalKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesProposals;
use Tests\Support\FakesGoogle;
use Tests\TestCase;

class OwnerAuthStartTest extends TestCase
{
    use CreatesProposals, FakesGoogle, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google' => [
            'client_id' => 'cid.apps.googleusercontent.com',
            'client_secret' => 'test-client-secret',
            'redirect' => 'http://localhost:8000/auth/google/callback',
        ]]);
    }

    public function test_start_redirects_to_google_with_pkce(): void
    {
        $key = $this->createProposal();
        $loc = $this->get(route('owner.login', ['p' => $key]))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(GoogleOAuth::AUTHORIZE_URL.'?', $loc);
        parse_str(parse_url($loc, PHP_URL_QUERY), $q);
        $pending = session(OwnerSession::PENDING_KEY);
        $this->assertSame($pending['state'], $q['state']);
        $this->assertSame(Pkce::challenge($pending['verifier']), $q['code_challenge']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame('select_account', $q['prompt']);
        $this->assertSame($key, $pending['key']);
        $this->assertStringNotContainsString($pending['verifier'], $loc);
        $this->assertCount(0, Http::recorded());
    }

    public function test_start_stores_state_only_in_session(): void
    {
        $key = $this->createProposal();
        $res = $this->get(route('owner.login', ['p' => $key]))->assertRedirect();
        $pending = session(OwnerSession::PENDING_KEY);
        $cookies = implode("\n", $res->headers->all('set-cookie'));
        foreach ([$pending['state'], $pending['verifier']] as $secret) {
            $this->assertStringNotContainsString($secret, $cookies);
            $this->assertStringNotContainsString($secret, (string) $res->getContent());
        }
    }

    public function test_starting_again_replaces_pending_login(): void
    {
        $key = $this->createProposal();
        $this->get(route('owner.login', ['p' => $key]));
        $first = session(OwnerSession::PENDING_KEY)['state'];
        $this->get(route('owner.login', ['p' => $key]));
        $this->assertNotSame($first, session(OwnerSession::PENDING_KEY)['state']);
    }

    public function test_redirect_uri_ignores_forged_host(): void
    {
        $key = $this->createProposal();
        $loc = $this->withHeader('Host', 'evil.example')->get('/auth/google?p='.$key)->headers->get('Location');
        parse_str(parse_url($loc, PHP_URL_QUERY), $q);
        $this->assertSame('http://localhost:8000/auth/google/callback', $q['redirect_uri']);
    }

    public function test_external_return_url_is_ignored(): void
    {
        $key = $this->createProposal();
        $loc = $this->get('/auth/google?p='.$key.'&return=https://evil.example/&redirect=//evil.example')->headers->get('Location');
        $this->assertStringNotContainsString('evil.example', $loc);
        $this->assertSame(['state', 'verifier', 'key', 'issued_at'], array_keys(session(OwnerSession::PENDING_KEY)));
        $this->assertSame($key, session(OwnerSession::PENDING_KEY)['key']);
    }

    public function test_unknown_expired_or_malformed_key_is_404(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $expired = $this->createProposal(['expires_at' => now()]);
        foreach ([
            '/auth/google',
            '/auth/google?p=',
            '/auth/google?p[]=x',
            '/auth/google?p='.ProposalKey::generate(),
            '/auth/google?p='.$expired,
            '/auth/google?p='.str_repeat('a', 42).'!',
        ] as $url) {
            $res = $this->get($url)->assertNotFound();
            $this->assertNull($res->headers->get('Location'), $url);
            $this->assertFalse(session()->has(OwnerSession::PENDING_KEY), $url);
        }
        $this->assertCount(0, Http::recorded());
    }

    public function test_login_start_is_503_when_not_configured(): void
    {
        config(['services.google.client_id' => '']);
        $key = $this->createProposal();
        $this->get('/auth/google?p='.$key)->assertStatus(503)->assertSee('しばらくしてからお試しください');
        $this->assertFalse(session()->has(OwnerSession::PENDING_KEY));
    }

    public function test_start_rejects_non_navigation_fetch(): void
    {
        $key = $this->createProposal();
        $this->withHeaders(['Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty'])
            ->get('/auth/google?p='.$key)->assertStatus(400);
        $this->assertFalse(session()->has(OwnerSession::PENDING_KEY));
    }

    public function test_oauth_routes_are_rate_limited(): void
    {
        $key = $this->createProposal();
        for ($i = 0; $i < 10; $i++) {
            $this->get('/auth/google?p='.$key)->assertRedirect();
        }
        $this->get('/auth/google/callback?state=x&code=y')->assertStatus(429); // 開始とコールバックで共通の 10 回/分
        $this->get('/auth/google?p='.$key)->assertStatus(429);
    }

    public function test_callback_is_get_only(): void
    {
        $this->post('/auth/google/callback')->assertStatus(405);
        $this->post('/auth/google')->assertStatus(405);
    }
}
