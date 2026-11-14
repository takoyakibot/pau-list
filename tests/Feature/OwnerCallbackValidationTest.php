<?php

namespace Tests\Feature;

use App\Services\YouTube\GoogleOAuth;
use App\Support\OwnerSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsNoInlineCode;
use Tests\Support\CreatesProposals;
use Tests\Support\FakesGoogle;
use Tests\TestCase;

class OwnerCallbackValidationTest extends TestCase
{
    use AssertsNoInlineCode, CreatesProposals, FakesGoogle, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google' => [
            'client_id' => 'cid.apps.googleusercontent.com',
            'client_secret' => 'test-client-secret',
            'redirect' => 'http://localhost:8000/auth/google/callback',
        ]]);
    }

    /** Starts a login for the proposal and returns the state sent to Google. */
    private function begin(string $key): string
    {
        $loc = $this->get('/auth/google?p='.$key)->assertRedirect()->headers->get('Location');
        parse_str(parse_url($loc, PHP_URL_QUERY), $q);

        return $q['state'];
    }

    private function hitCallback(array $query): TestResponse
    {
        return $this->get('/auth/google/callback?'.http_build_query($query));
    }

    public static function badStates(): array
    {
        return ['missing' => [null], 'empty' => [''], 'mismatch' => ['WRONG'], 'array' => [['x']]];
    }

    #[DataProvider('badStates')]
    public function test_bad_state_is_400_without_token_request(mixed $state): void
    {
        $key = $this->createProposal();
        $this->begin($key);
        $this->fakeToken();
        $query = ['code' => '4/0Acode'] + ($state === null ? [] : ['state' => $state]);
        $this->hitCallback($query)->assertStatus(400);
        $this->assertFalse(session()->has(OwnerSession::PENDING_KEY));
        $this->assertCount(0, $this->googleRequests(GoogleOAuth::TOKEN_URL));
    }

    public function test_state_differing_only_in_case_or_padding_is_400(): void
    {
        $key = $this->createProposal();
        $state = $this->begin($key);
        $this->hitCallback(['state' => $state.'=', 'code' => 'c'])->assertStatus(400);
        $state = $this->begin($key);
        $this->hitCallback(['state' => strtoupper($state) === $state ? strtolower($state) : strtoupper($state), 'code' => 'c'])->assertStatus(400);
    }

    public function test_replayed_callback_is_400(): void
    {
        $key = $this->createProposal();
        $state = $this->begin($key);
        $this->fakeToken();
        $this->fakeMyChannel($this->channelId);
        $url = '/auth/google/callback?'.http_build_query(['state' => $state, 'code' => '4/0Acode']);
        $this->get($url)->assertRedirect(route('proposals.edit', $key));
        $this->get($url)->assertStatus(400)->assertSee('href="'.route('home').'"', false);
        $this->assertCount(1, $this->googleRequests(GoogleOAuth::TOKEN_URL));
    }

    public function test_state_older_than_10_minutes_is_400(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $key = $this->createProposal();
        $state = $this->begin($key);
        $this->travel(601)->seconds();
        $this->hitCallback(['state' => $state, 'code' => 'c'])->assertStatus(400);
        $this->assertFalse(session()->has(OwnerSession::PENDING_KEY));
    }

    public function test_state_at_exactly_10_minutes_is_accepted(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $key = $this->createProposal();
        $state = $this->begin($key);
        $this->travel(600)->seconds();
        $this->assertNotSame(400, $this->hitCallback(['state' => $state, 'code' => 'c'])->getStatusCode());
    }

    public static function badCodes(): array
    {
        return [
            'space' => [['code' => '4/0A co de']],
            'lt' => [['code' => '4/0A<x']],
            'too long' => [['code' => str_repeat('a', 513)]],
            'array' => [['code' => ['x']]],
            'missing' => [[]],
            'empty' => [['code' => '']],
            'error and code' => [['code' => '4/0Acode', 'error' => 'access_denied']],
            'error array' => [['error' => ['access_denied']]],
            'iss array' => [['code' => '4/0Acode', 'iss' => ['x']]],
        ];
    }

    #[DataProvider('badCodes')]
    public function test_malformed_code_or_error_with_code_is_400(array $extra): void
    {
        $key = $this->createProposal();
        $state = $this->begin($key);
        $this->fakeToken();
        $this->hitCallback(['state' => $state] + $extra)->assertStatus(400);
        $this->assertCount(0, $this->googleRequests(GoogleOAuth::TOKEN_URL));
        $this->assertFalse(session()->has(OwnerSession::PENDING_KEY));
    }

    public function test_code_at_512_chars_with_allowed_symbols_passes_validation(): void
    {
        $key = $this->createProposal();
        $state = $this->begin($key);
        $code = '4/0A-._~+'.str_repeat('a', 503);
        $this->assertSame(512, strlen($code));
        $this->assertNotSame(400, $this->hitCallback(['state' => $state, 'code' => $code])->getStatusCode());
    }

    public function test_wrong_iss_is_400_and_correct_iss_passes(): void
    {
        $key = $this->createProposal();
        $state = $this->begin($key);
        $this->hitCallback(['state' => $state, 'code' => 'c', 'iss' => 'https://evil.example'])->assertStatus(400);
        $state = $this->begin($key);
        $this->assertNotSame(400, $this->hitCallback(['state' => $state, 'code' => 'c', 'iss' => GoogleOAuth::ISSUER])->getStatusCode());
    }

    public function test_access_denied_returns_to_preview_with_fixed_message(): void
    {
        $key = $this->createProposal();
        $state = $this->begin($key);
        $this->hitCallback(['state' => $state, 'error' => 'access_denied'])
            ->assertRedirect(route('proposals.show', $key));
        $this->get(route('proposals.show', $key))
            ->assertSee('ログインがキャンセルされました。作成するには、もう一度ログインして YouTube の管理を許可してください');
        $this->assertCount(0, Http::recorded());
    }

    public function test_error_description_is_never_shown(): void
    {
        $key = $this->createProposal();
        $state = $this->begin($key);
        $this->followingRedirects()->hitCallback(['state' => $state, 'error' => 'server_error', 'error_description' => '<b>FAKE-MESSAGE</b> call 0120'])
            ->assertOk()
            ->assertDontSee('FAKE-MESSAGE')->assertDontSee('0120')->assertDontSee('server_error')
            ->assertSee('ログインできませんでした。もう一度お試しください');
    }

    public function test_error_for_expired_proposal_is_404(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $key = $this->createProposal(['expires_at' => now()->addSeconds(30)]);
        $state = $this->begin($key);
        $this->travel(31)->seconds();
        $this->hitCallback(['state' => $state, 'error' => 'access_denied'])->assertNotFound();
    }

    public function test_superseded_or_replayed_callback_shows_retry_page(): void
    {
        $key = $this->createProposal();
        $first = $this->begin($key);
        $this->begin($key);   // 2 つ目のタブが上書き
        $res = $this->hitCallback(['state' => $first, 'code' => 'c'])->assertStatus(400);
        $res->assertSee('ログインの手続きを確認できませんでした（時間が経った、別のタブでログインを始めた、または同じ画面を再読み込みした可能性があります）。もう一度ログインしてください')
            ->assertSee('href="'.route('proposals.show', $key).'"', false);
        $this->assertNoInlineCode($res->getContent());
    }

    public function test_callback_without_pending_links_to_top(): void
    {
        $res = $this->hitCallback(['state' => str_repeat('a', 43), 'code' => 'c'])->assertStatus(400);
        $res->assertSee('もう一度ログインしてください')->assertSee('href="'.route('home').'"', false);
    }

    public function test_callback_is_503_when_not_configured(): void
    {
        $key = $this->createProposal();
        $state = $this->begin($key);
        config(['services.google.client_secret' => '']);
        $this->fakeToken();
        $this->hitCallback(['state' => $state, 'code' => 'c'])->assertStatus(503)->assertSee('しばらくしてからお試しください');
        $this->assertFalse(session()->has(OwnerSession::PENDING_KEY));
        $this->assertCount(0, $this->googleRequests(GoogleOAuth::TOKEN_URL));
    }
}
