<?php

namespace Tests\Unit;

use App\Support\VideoUrl;
use PHPUnit\Framework\TestCase;

class VideoUrlTest extends TestCase
{
    public function test_extracts_id_from_supported_forms(): void
    {
        foreach (['dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s', 'https://youtu.be/dQw4w9WgXcQ?si=x',
            'youtube.com/shorts/dQw4w9WgXcQ', 'https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'https://music.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ', 'https://www.youtube.com/live/dQw4w9WgXcQ'] as $in) {
            $this->assertSame('dQw4w9WgXcQ', VideoUrl::extractId($in), $in);
        }
    }

    public function test_rejects_other_hosts_and_bad_ids(): void
    {
        foreach (['', 'https://example.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=short', 'dQw4w9WgXc!',
            'https://www.youtube.com/playlist?list=PL1', 'https://www.youtube.com/watch?v[]=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQextra', 'https://www.youtube.com/shorts/'.str_repeat('a', 200)] as $in) {
            $this->assertNull(VideoUrl::extractId($in), $in);
        }
    }

    public function test_rejects_trailing_newline_and_accepts_uppercase_scheme_host(): void
    {
        $this->assertNull(VideoUrl::extractId('https://www.youtube.com/watch?v=dQw4w9WgXcQ%0A'));
        $this->assertNull(VideoUrl::extractId("dQw4w9WgXcQ\n".'x'));
        $this->assertSame('dQw4w9WgXcQ', VideoUrl::extractId('HTTPS://YOUTU.BE/dQw4w9WgXcQ'));
    }
}
