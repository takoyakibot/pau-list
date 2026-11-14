<?php

namespace Tests\Feature;

use App\Support\OwnerLogin;
use App\Support\OwnerSession;
use App\Support\PendingLogin;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class OwnerSessionTest extends TestCase
{
    public function test_begin_generates_fresh_43_char_state(): void
    {
        $s = app(OwnerSession::class);
        $a = $s->begin(str_repeat('k', 43));
        $b = $s->begin(str_repeat('k', 43));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $a->state);
        $this->assertNotSame($a->state, $b->state);
        $this->assertNotSame($a->verifier, $b->verifier);
        $this->assertSame($b->state, session(OwnerSession::PENDING_KEY)['state']); // 後勝ちで上書き
    }

    public function test_pull_pending_is_one_time(): void
    {
        $s = app(OwnerSession::class);
        $p = $s->begin(str_repeat('k', 43));
        $this->assertEquals($p, $s->pullPending());
        $this->assertNull($s->pullPending());
        $this->assertFalse(session()->has(OwnerSession::PENDING_KEY));
    }

    public function test_pending_expires_after_600_seconds(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $p = app(OwnerSession::class)->begin(str_repeat('k', 43));
        $this->travel(600)->seconds();
        $this->assertFalse($p->isExpired());
        $this->travel(1)->seconds();
        $this->assertTrue($p->isExpired());
    }

    public function test_malformed_session_values_are_ignored(): void
    {
        session([OwnerSession::PENDING_KEY => ['state' => ['x']], OwnerSession::OWNER_KEY => 'junk']);
        $s = app(OwnerSession::class);
        $this->assertNull($s->pullPending());
        $this->assertNull($s->current());
        $this->assertFalse(session()->has(OwnerSession::OWNER_KEY));
    }

    public function test_malformed_owner_fields_are_ignored(): void
    {
        $valid = OwnerSession::serialize(new OwnerLogin('tok', now()->toImmutable()->addHour(), 'UC'.str_repeat('a', 22), 'A'));
        $s = app(OwnerSession::class);
        foreach ([
            ['access_token' => ''], ['access_token' => ['x']], ['expires_at' => '123'],
            ['channel_id' => 'UCshort'], ['channel_title' => null],
        ] as $override) {
            session([OwnerSession::OWNER_KEY => array_merge($valid, $override)]);
            $this->assertNull($s->current(), json_encode($override));
            $this->assertFalse(session()->has(OwnerSession::OWNER_KEY));
        }
    }

    public function test_current_is_null_at_expiry_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $s = app(OwnerSession::class);
        $s->login(new OwnerLogin('tok', now()->toImmutable()->addSeconds(10), 'UC'.str_repeat('a', 22), 'A'));
        $this->travel(9)->seconds();
        $this->assertNotNull($s->current());
        $this->travel(1)->seconds();               // now == expires_at
        $this->assertNull($s->current());
        $this->assertFalse(session()->has(OwnerSession::OWNER_KEY));
    }

    public function test_token_for_revoke_survives_expiry_and_forget_clears(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $s = app(OwnerSession::class);
        $this->assertNull($s->tokenForRevoke());
        $s->login(new OwnerLogin('tok', now()->toImmutable()->addSeconds(10), 'UC'.str_repeat('a', 22), 'A'));
        $this->travel(20)->seconds();
        $this->assertSame('tok', $s->tokenForRevoke());
        $s->begin(str_repeat('k', 43));
        $s->forget();
        $this->assertNull($s->tokenForRevoke());
        $this->assertFalse(session()->has(OwnerSession::OWNER_KEY));
        $this->assertFalse(session()->has(OwnerSession::PENDING_KEY));
    }

    public function test_proposal_key_parameters_are_sensitive(): void
    {
        $begin = (new \ReflectionMethod(OwnerSession::class, 'begin'))->getParameters()[0];
        $this->assertNotEmpty($begin->getAttributes(\SensitiveParameter::class));
        foreach ((new \ReflectionMethod(PendingLogin::class, '__construct'))->getParameters() as $p) {
            $this->assertSame(in_array($p->getName(), ['state', 'verifier', 'proposalKey'], true), $p->getAttributes(\SensitiveParameter::class) !== [], $p->getName());
        }
    }

    public function test_matches_compares_channel_id(): void
    {
        $s = app(OwnerSession::class);
        $this->assertFalse($s->matches('UC'.str_repeat('a', 22)));
        $s->login(new OwnerLogin('tok', now()->toImmutable()->addHour(), 'UC'.str_repeat('a', 22), 'A'));
        $this->assertTrue($s->matches('UC'.str_repeat('a', 22)));
        $this->assertFalse($s->matches('UC'.str_repeat('b', 22)));
    }

    public function test_session_cookie_secure_default_assumes_production(): void
    {
        $this->assertStringContainsString("env('APP_ENV', 'production') === 'production'", file_get_contents(config_path('session.php')));
    }
}
