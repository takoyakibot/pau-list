<?php

namespace Tests\Unit;

use App\Support\Pkce;
use PHPUnit\Framework\TestCase;

class PkceTest extends TestCase
{
    public function test_verifier_is_43_unreserved_chars_and_random(): void
    {
        $a = Pkce::verifier();
        $b = Pkce::verifier();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $a);
        $this->assertNotSame($a, $b);
    }

    public function test_challenge_matches_rfc7636_appendix_b(): void
    {
        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
    }
}
