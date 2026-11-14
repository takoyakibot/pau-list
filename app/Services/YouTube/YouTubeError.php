<?php

namespace App\Services\YouTube;

use Illuminate\Http\Client\Response;

final readonly class YouTubeError
{
    public function __construct(
        public YouTubeErrorKind $kind,
        public ?int $status,
        public ?string $reason,
    ) {}

    public static function fromResponse(Response $response): self
    {
        $status = $response->status();
        $reason = null;
        try {
            $candidate = $response->json('error.errors.0.reason');
            $reason = is_string($candidate) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $candidate) === 1 ? $candidate : null;
        } catch (\Throwable) {
            // Non-JSON error body: classify by status alone.
        }

        return new self(self::classify($status, $reason), $status, $reason);
    }

    public static function connection(): self
    {
        return new self(YouTubeErrorKind::Transient, null, null);
    }

    public static function malformed(): self
    {
        return new self(YouTubeErrorKind::Unknown, null, 'malformedResponse');
    }

    private static function classify(int $status, ?string $reason): YouTubeErrorKind
    {
        return match (true) {
            $status >= 500, $status === 409, $status === 429 => YouTubeErrorKind::Transient,
            $status === 403 && in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true) => YouTubeErrorKind::GoogleQuota,
            $status === 403 && in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true) => YouTubeErrorKind::Transient,
            $status === 401 => YouTubeErrorKind::Auth,
            $status === 404, $status === 403 => YouTubeErrorKind::ItemPermanent,
            $status === 400 => YouTubeErrorKind::InvalidInput,
            default => YouTubeErrorKind::Unknown,
        };
    }
}
