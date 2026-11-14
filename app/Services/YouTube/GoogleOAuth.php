<?php

namespace App\Services\YouTube;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google OAuth 2.0 authorization code + PKCE over plain HTTP (spec 6.1, 6.7).
 * Endpoints are constants; client id, secret and redirect URI come only from the services.google config.
 * No retries, no redirects followed, and nothing but status and error codes is ever logged.
 */
class GoogleOAuth
{
    public const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public const SCOPE = 'https://www.googleapis.com/auth/youtube';

    public const ISSUER = 'https://accounts.google.com';

    public const MAX_EXPIRES_IN = 3600;

    public const EXPIRY_MARGIN = 60;

    private const LOGGABLE_CODE = '/^[A-Za-z0-9_.-]{1,64}$/D';

    public function isConfigured(): bool
    {
        $redirect = $this->redirectUri();
        if (! filled($this->clientId()) || ! filled($this->clientSecret()) || ! filled($redirect)) {
            return false;
        }

        return ! app()->isProduction() || str_starts_with($redirect, 'https://');
    }

    public function authorizationUrl(#[\SensitiveParameter] string $state, string $codeChallenge): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'online',
            'include_granted_scopes' => 'false',
            'prompt' => 'select_account',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @throws OAuthFailed */
    public function exchangeCode(#[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier): OAuthToken
    {
        try {
            $response = $this->client()->post(self::TOKEN_URL, [
                'code' => $code,
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri' => $this->redirectUri(),
                'grant_type' => 'authorization_code',
                'code_verifier' => $verifier,
            ]);
        } catch (ConnectionException) {
            throw $this->tokenFailure(OAuthFailed::CONNECTION, null);
        }

        $status = $response->status();
        if ($response->redirect()) {
            throw $this->tokenFailure(OAuthFailed::REDIRECT, $status);
        }
        if (! $response->successful()) {
            throw $this->tokenFailure(OAuthFailed::HTTP_ERROR, $status, self::errorCode($response));
        }

        $token = $status === 200 ? self::parseToken($response->json()) : null;
        if ($token === null) {
            throw $this->tokenFailure(OAuthFailed::MALFORMED, $status);
        }

        return $token;
    }

    /** Best effort: never throws. Logout continues whatever happens here (spec 6.6, 6.7 F3). */
    public function revoke(#[\SensitiveParameter] string $token): bool
    {
        try {
            $response = $this->client()->post(self::REVOKE_URL, ['token' => $token]);
        } catch (ConnectionException) {
            Log::warning('google oauth revoke failed', ['status' => null]);

            return false;
        }

        if ($response->status() !== 200) {
            Log::warning('google oauth revoke failed', ['status' => $response->status()]);

            return false;
        }

        return true;
    }

    private function client(): PendingRequest
    {
        return Http::asForm()
            ->connectTimeout(5)
            ->timeout(15)
            ->withoutRedirecting();
    }

    private static function parseToken(mixed $json): ?OAuthToken
    {
        if (! is_array($json)) {
            return null;
        }
        $accessToken = $json['access_token'] ?? null;
        $tokenType = $json['token_type'] ?? null;
        $expiresIn = self::positiveInt($json['expires_in'] ?? null);
        if (! is_string($accessToken) || $accessToken === ''
            || ! is_string($tokenType) || strcasecmp($tokenType, 'Bearer') !== 0
            || $expiresIn === null) {
            return null;
        }

        $scope = $json['scope'] ?? null;
        $scopes = is_string($scope) ? preg_split('/\s+/', $scope, -1, PREG_SPLIT_NO_EMPTY) : [];

        return new OAuthToken(
            $accessToken,
            now()->toImmutable()->addSeconds(min($expiresIn, self::MAX_EXPIRES_IN) - self::EXPIRY_MARGIN),
            $scopes,
        );
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^[0-9]{1,10}$/D', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }

    private static function errorCode(Response $response): ?string
    {
        $json = rescue(fn () => $response->json(), null, false);
        $code = is_array($json) ? ($json['error'] ?? null) : null;

        return is_string($code) && preg_match(self::LOGGABLE_CODE, $code) === 1 ? $code : null;
    }

    private function tokenFailure(string $reason, ?int $status, ?string $error = null): OAuthFailed
    {
        Log::warning('google oauth token request failed', ['reason' => $reason, 'status' => $status, 'error' => $error]);

        return new OAuthFailed($reason);
    }

    private function clientId(): mixed
    {
        return $this->settings()['client_id'] ?? null;
    }

    private function clientSecret(): mixed
    {
        return $this->settings()['client_secret'] ?? null;
    }

    /** Fixed in config; never derived from the request Host (spec 6.7 C1). */
    private function redirectUri(): mixed
    {
        return $this->settings()['redirect'] ?? null;
    }

    private function settings(): array
    {
        $settings = config('services.google');

        return is_array($settings) ? $settings : [];
    }
}
