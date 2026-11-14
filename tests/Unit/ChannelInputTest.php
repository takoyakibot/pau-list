<?php

namespace Tests\Unit;

use App\Support\ChannelInput;
use App\Support\ChannelRef;
use PHPUnit\Framework\TestCase;

class ChannelInputTest extends TestCase
{
    private static function pair(?ChannelRef $ref): ?array
    {
        return $ref === null ? null : [$ref->type, $ref->value];
    }

    public function test_channel_id_and_url(): void
    {
        $id = 'UC'.str_repeat('a', 22);
        $this->assertSame(['id', $id], self::pair(ChannelInput::parse($id)));
        $this->assertSame(['id', $id], self::pair(ChannelInput::parse("https://www.youtube.com/channel/$id/videos")));
        $this->assertSame(['id', $id], self::pair(ChannelInput::parse("  youtube.com/channel/$id  ")));
    }

    public function test_channel_input_accepts_handle_variants(): void
    {
        $this->assertSame(['handle', 'foo.bar'], self::pair(ChannelInput::parse('@Foo.Bar')));
        $this->assertSame(['handle', 'foo.bar'], self::pair(ChannelInput::parse('https://m.youtube.com/@Foo.Bar/videos?view=0')));
        $this->assertSame(['handle', 'あいうえお'], self::pair(ChannelInput::parse('https://www.youtube.com/@%E3%81%82%E3%81%84%E3%81%86%E3%81%88%E3%81%8A')));
    }

    public function test_rejects_unsupported_input(): void
    {
        $id = 'UC'.str_repeat('a', 22);
        foreach (['', 'foo', '@ab', 'https://example.com/@foo', 'https://www.youtube.com/c/foo', 'https://www.youtube.com/user/foo',
            'UC'.str_repeat('a', 21), '@'.str_repeat('a', 200), 'javascript:alert(1)',
            "https://youtube.com@evil.com/channel/$id", "https://evil.com/?x=youtube.com/channel/$id",
            'https://www.youtube.com/@%FF%FEabc'] as $bad) {
            $this->assertNull(ChannelInput::parse($bad), $bad);
        }
    }
}
