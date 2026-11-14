<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;

/**
 * The only reader/writer of OAuth state (state, verifier, return key) and the owner login in the session.
 * Every value read back is type-checked before it becomes a DTO; anything malformed is treated as absent.
 */
final class OwnerSession
{
    public const PENDING_KEY = 'oauth_pending';

    public const OWNER_KEY = 'owner';

    public const STATE_TTL_SECONDS = 600;

    /** The owner's unlisted uploads (owner-only data), cached per channel to spare owner_read. */
    public const UNLISTED_KEY = 'owner_unlisted';

    public const UNLISTED_TTL_SECONDS = 600;

    private const RANDOM_PATTERN = '/^[A-Za-z0-9_-]{43}$/D';

    public function __construct(private readonly Session $session) {}

    public function begin(#[\SensitiveParameter] string $proposalKey): PendingLogin
    {
        $pending = new PendingLogin(
            Pkce::base64url(random_bytes(32)),
            Pkce::verifier(),
            $proposalKey,
            now()->getTimestamp(),
        );
        $this->session->put(self::PENDING_KEY, [
            'state' => $pending->state,
            'verifier' => $pending->verifier,
            'key' => $pending->proposalKey,
            'issued_at' => $pending->issuedAt,
        ]);

        return $pending;
    }

    public function pullPending(): ?PendingLogin
    {
        $raw = $this->session->pull(self::PENDING_KEY);
        if (! is_array($raw)
            || ! self::isRandom($raw['state'] ?? null)
            || ! self::isRandom($raw['verifier'] ?? null)
            || ! is_string($raw['key'] ?? null) || ! ProposalKey::isWellFormed($raw['key'])
            || ! is_int($raw['issued_at'] ?? null)) {
            return null;
        }

        return new PendingLogin($raw['state'], $raw['verifier'], $raw['key'], $raw['issued_at']);
    }

    public function login(OwnerLogin $login): void
    {
        $this->session->forget(self::UNLISTED_KEY);
        $this->session->put(self::OWNER_KEY, self::serialize($login));
    }

    /** @param list<array<string, mixed>> $videos */
    public function rememberUnlisted(string $channelId, array $videos): void
    {
        $this->session->put(self::UNLISTED_KEY, [
            'channel_id' => $channelId,
            'fetched_at' => now()->getTimestamp(),
            'videos' => array_values($videos),
        ]);
    }

    /** @return list<array<string, mixed>>|null null when absent, for another channel, expired or malformed */
    public function unlisted(string $channelId): ?array
    {
        $raw = $this->session->get(self::UNLISTED_KEY);
        if (! is_array($raw) || ($raw['channel_id'] ?? null) !== $channelId
            || ! is_int($raw['fetched_at'] ?? null)
            || now()->getTimestamp() - $raw['fetched_at'] >= self::UNLISTED_TTL_SECONDS
            || ! is_array($raw['videos'] ?? null) || ! array_is_list($raw['videos'])) {
            return null;
        }

        return array_values(array_filter($raw['videos'], 'is_array'));
    }

    public function current(): ?OwnerLogin
    {
        $login = self::deserialize($this->session->get(self::OWNER_KEY));
        if ($login === null || $login->isExpired()) {
            $this->session->forget(self::OWNER_KEY);

            return null;
        }

        return $login;
    }

    /** The stored token even when expired, so logout can still revoke it. */
    public function tokenForRevoke(): ?string
    {
        $token = $this->session->get(self::OWNER_KEY)['access_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function forget(): void
    {
        $this->session->forget([self::OWNER_KEY, self::PENDING_KEY, self::UNLISTED_KEY]);
    }

    public function matches(string $channelId): bool
    {
        return $this->current()?->channelId === $channelId;
    }

    /** @return array{access_token: string, expires_at: int, channel_id: string, channel_title: string} */
    public static function serialize(OwnerLogin $l): array
    {
        return [
            'access_token' => $l->accessToken,
            'expires_at' => $l->expiresAt->getTimestamp(),
            'channel_id' => $l->channelId,
            'channel_title' => $l->channelTitle,
        ];
    }

    private static function deserialize(mixed $raw): ?OwnerLogin
    {
        if (! is_array($raw)
            || ! is_string($raw['access_token'] ?? null) || $raw['access_token'] === ''
            || ! is_int($raw['expires_at'] ?? null)
            || ! is_string($raw['channel_id'] ?? null) || preg_match(ChannelInput::ID_PATTERN, $raw['channel_id']) !== 1
            || ! is_string($raw['channel_title'] ?? null)) {
            return null;
        }

        return new OwnerLogin(
            $raw['access_token'],
            CarbonImmutable::createFromTimestamp($raw['expires_at'], config('app.timezone')),
            $raw['channel_id'],
            $raw['channel_title'],
        );
    }

    private static function isRandom(mixed $value): bool
    {
        return is_string($value) && preg_match(self::RANDOM_PATTERN, $value) === 1;
    }
}
