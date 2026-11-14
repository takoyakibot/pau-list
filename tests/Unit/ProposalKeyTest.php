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

    public function test_from_input_accepts_key_and_urls(): void
    {
        $k = str_repeat('A', 40).'b-_';
        $this->assertSame($k, ProposalKey::fromInput("  $k "));
        $this->assertSame($k, ProposalKey::fromInput("https://list-maker.alpacasandbag.jp/p/$k"));
        $this->assertSame($k, ProposalKey::fromInput("https://list-maker.alpacasandbag.jp/p/$k/edit?x=1"));
        $this->assertSame($k, ProposalKey::fromInput("http://localhost:8000/p/$k#top"));
        $this->assertSame($k, ProposalKey::fromInput("https://list-maker.alpacasandbag.jp/p/$k/creation"));
        $this->assertSame($k, ProposalKey::fromInput("https://list-maker.alpacasandbag.jp/p/$k/shared"));
        $this->assertSame($k, ProposalKey::fromInput("https://list-maker.alpacasandbag.jp/p/$k?x=1"));
        $this->assertNull(ProposalKey::fromInput(substr($k, 1)));            // 42 文字
        $this->assertNull(ProposalKey::fromInput("https://example.com/p/{$k}x")); // 44 文字目が続く
        $this->assertNull(ProposalKey::fromInput("https://example.com/p/$k/other"));
        $this->assertNull(ProposalKey::fromInput("{$k}x"));
        $this->assertNull(ProposalKey::fromInput("$k\nx"));
        $this->assertNull(ProposalKey::fromInput(''));
    }
}
