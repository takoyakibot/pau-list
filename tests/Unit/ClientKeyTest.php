<?php

namespace Tests\Unit;

use App\Support\ClientKey;
use PHPUnit\Framework\TestCase;

class ClientKeyTest extends TestCase
{
    public function test_ipv4_is_kept_and_mapped_ipv6_equals_ipv4(): void
    {
        $this->assertSame('203.0.113.5', ClientKey::network('203.0.113.5'));
        $this->assertSame('203.0.113.5', ClientKey::network('::ffff:203.0.113.5'));
    }

    public function test_ipv6_is_reduced_to_its_64(): void
    {
        $this->assertSame('2001:db8:1:2::/64', ClientKey::network('2001:db8:1:2:aaaa::1'));
        $this->assertSame(ClientKey::network('2001:db8:1:2:aaaa::1'), ClientKey::network('2001:DB8:1:2:ffff:ffff:ffff:ffff'));
        $this->assertNotSame(ClientKey::network('2001:db8:1:2::1'), ClientKey::network('2001:db8:1:3::1'));
    }

    public function test_missing_or_invalid_ip_is_unknown(): void
    {
        foreach ([null, '', 'not-an-ip', '999.1.1.1'] as $ip) {
            $this->assertSame('unknown', ClientKey::network($ip));
        }
    }
}
