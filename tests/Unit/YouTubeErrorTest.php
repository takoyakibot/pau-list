<?php

namespace Tests\Unit;

use App\Services\YouTube\YouTubeError;
use App\Services\YouTube\YouTubeErrorKind;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class YouTubeErrorTest extends TestCase
{
    public static function cases(): array
    {
        return [
            [500, null, YouTubeErrorKind::Transient],
            [503, null, YouTubeErrorKind::Transient],
            [409, null, YouTubeErrorKind::Transient],
            [429, null, YouTubeErrorKind::Transient],
            [403, 'quotaExceeded', YouTubeErrorKind::GoogleQuota],
            [403, 'dailyLimitExceeded', YouTubeErrorKind::GoogleQuota],
            [403, 'rateLimitExceeded', YouTubeErrorKind::Transient],
            [403, 'userRateLimitExceeded', YouTubeErrorKind::Transient],
            [401, null, YouTubeErrorKind::Auth],
            [404, 'videoNotFound', YouTubeErrorKind::ItemPermanent],
            [403, 'forbidden', YouTubeErrorKind::ItemPermanent],
            [400, 'invalidValue', YouTubeErrorKind::InvalidInput],
            [418, null, YouTubeErrorKind::Unknown],
        ];
    }

    #[DataProvider('cases')]
    public function test_classification(int $status, ?string $reason, YouTubeErrorKind $kind): void
    {
        $body = $reason === null ? '{}' : json_encode(['error' => ['errors' => [['reason' => $reason]]]]);
        $error = YouTubeError::fromResponse(new Response(new PsrResponse($status, [], $body)));

        $this->assertSame($kind, $error->kind);
        $this->assertSame($status, $error->status);
        $this->assertSame($reason, $error->reason);
    }

    public function test_non_json_body_has_no_reason(): void
    {
        $error = YouTubeError::fromResponse(new Response(new PsrResponse(403, [], '<html>')));
        $this->assertNull($error->reason);
        $this->assertSame(YouTubeErrorKind::ItemPermanent, $error->kind);
    }

    public function test_connection_is_transient(): void
    {
        $error = YouTubeError::connection();
        $this->assertSame(YouTubeErrorKind::Transient, $error->kind);
        $this->assertNull($error->status);
    }

    public function test_kind_values_are_snake_case(): void
    {
        $this->assertSame('google_quota', YouTubeErrorKind::GoogleQuota->value);
        $this->assertSame('item_permanent', YouTubeErrorKind::ItemPermanent->value);
        $this->assertSame('invalid_input', YouTubeErrorKind::InvalidInput->value);
    }
}
