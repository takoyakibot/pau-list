<?php

namespace Tests\Unit;

use App\Support\ProposalKey;
use PHPUnit\Framework\TestCase;

class ProposalKeyTest extends TestCase
{
    public function test_generate_is_43_char_base64url_and_unique(): void
    {
        $a = ProposalKey::generate();
        $b = ProposalKey::generate();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $a);
        $this->assertNotSame($a, $b);
        $this->assertTrue(ProposalKey::isWellFormed($a));
        $this->assertFalse(ProposalKey::isWellFormed($a.'='));
        $this->assertFalse(ProposalKey::isWellFormed($a."\n"));
    }

    public function test_hash_is_sha256_hex(): void
    {
        $this->assertSame(hash('sha256', 'k'), ProposalKey::hash('k'));
    }
}
