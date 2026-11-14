<?php

namespace App\Providers;

use App\Http\Controllers\OwnerEditController;
use App\Session\PrivacyDatabaseSessionHandler;
use App\Support\ClientKey;
use App\Support\OwnerSession;
use App\Support\QuotaPolicy;
use App\Support\RateLimitLog;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->validateQuotaConfig($this->app->runningInConsole());
        self::trustProxiesFrom(config('listmaker.trusted_proxies'));
        $this->warnAboutTrustedProxies(config('listmaker.trusted_proxies'));
        $this->configureRateLimiting();
        $this->configureSessions();
        $this->warnAboutDebugInProduction();
        $this->warnAboutInsecureSessionCookie();
    }

    /**
     * TRUSTED_PROXIES: comma-separated IPs/CIDRs, or "*". Empty means no proxy is trusted, so the client IP
     * is REMOTE_ADDR and X-Forwarded-* headers are ignored. From a trusted proxy only X-Forwarded-For and
     * X-Forwarded-Proto are used (Host/Port/Prefix are not: links and redirects keep APP_URL's host).
     */
    public static function trustProxiesFrom(mixed $value): void
    {
        if (! filled($value)) {
            return;
        }
        $value = (string) $value;
        TrustProxies::at($value === '*' ? '*' : self::proxyEntries($value));
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
    }

    /**
     * Warns about values that trust more than one known proxy: "*" (production only) and Symfony's keywords, which
     * TrustProxies passes through: REMOTE_ADDR is replaced by the sender itself (trusts every sender, like "*") and
     * PRIVATE_SUBNETS / private_ranges by every private network range. Any other entry that is not an IP address or
     * CIDR range (a typo, a host name) matches no sender, so it trusts nothing; it is warned about too.
     */
    public function warnAboutTrustedProxies(mixed $value): void
    {
        if (! filled($value)) {
            return;
        }
        $value = (string) $value;
        if ($value === '*') {
            if ($this->app->isProduction()) {
                Log::warning('TRUSTED_PROXIES trusts every sender (*)');
            }

            return;
        }
        $entries = self::proxyEntries($value);
        if (in_array('REMOTE_ADDR', $entries, true)) {
            Log::warning('TRUSTED_PROXIES trusts every sender (REMOTE_ADDR)');

            return;
        }
        if (in_array('PRIVATE_SUBNETS', $entries, true) || in_array('private_ranges', $entries, true)) {
            Log::warning('TRUSTED_PROXIES trusts every private network range (PRIVATE_SUBNETS)');

            return;
        }
        foreach ($entries as $entry) {
            if (! self::isIpOrCidr($entry)) {
                Log::warning('TRUSTED_PROXIES has an entry that is not an IP address or CIDR range');

                return;
            }
        }
    }

    /** @return list<string> */
    private static function proxyEntries(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
    }

    private static function isIpOrCidr(string $entry): bool
    {
        $parts = explode('/', $entry);
        if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (count($parts) === 1) {
            return true;
        }
        $max = str_contains($parts[0], ':') ? 128 : 32;

        return preg_match('/^\d{1,3}$/D', $parts[1]) === 1 && (int) $parts[1] <= $max;
    }

    /** Debug pages expose configuration and stack traces (spec 6.7 I2). */
    public function warnAboutDebugInProduction(): void
    {
        if ($this->app->isProduction() && config('app.debug')) {
            Log::warning('APP_DEBUG is enabled in production');
        }
    }

    /**
     * The session carries the owner's access token: in production its cookie must be Secure
     * (SESSION_SECURE_COOKIE=true; config/session.php defaults to true, this catches an explicit false).
     */
    public function warnAboutInsecureSessionCookie(): void
    {
        if ($this->app->isProduction() && config('session.secure') !== true) {
            Log::warning('SESSION_SECURE_COOKIE is not true in production');
        }
    }

    /**
     * Keep the driver name "database" but never store the IP address or user agent.
     */
    private function configureSessions(): void
    {
        Session::extend('database', fn ($app) => new PrivacyDatabaseSessionHandler(
            $app['db']->connection($app['config']->get('session.connection')),
            $app['config']->get('session.table'),
            $app['config']->get('session.lifetime'),
            $app,
        ));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('global', fn (Request $request) => Limit::perMinute(120)->by(ClientKey::for($request)));
        RateLimiter::for('video-lookup', fn (Request $request) => Limit::perMinute(30)->by(ClientKey::for($request)));
        // Login start and callback share one limit (spec 8.1, 6.7 J2).
        RateLimiter::for('oauth', fn (Request $request) => Limit::perMinute(10)->by('oauth:'.ClientKey::for($request)));
        // The owner's unlisted list spends owner_read: 5 per 10 minutes per owner channel (IP when logged out).
        RateLimiter::for('owner-unlisted', fn (Request $request) => Limit::perMinutes(10, 5)
            ->by('owner-unlisted:'.(app(OwnerSession::class)->current()?->channelId ?? 'ip:'.ClientKey::for($request)))
            ->response(function () {
                RateLimitLog::exceeded('owner-unlisted');

                return response()->json(['message' => OwnerEditController::UNLISTED_THROTTLED], 429);
            }));
        // Creation start and the retry of failed items share one limit: 5 per hour per owner channel (IP when logged out).
        RateLimiter::for('creation-start', fn (Request $request) => Limit::perHour(5)
            ->by('creation-start:'.(app(OwnerSession::class)->current()?->channelId ?? 'ip:'.ClientKey::for($request))));
        // S6 steps: 2 per second per session (spec 8.1); the page waits next_delay_ms (1 s) between steps.
        RateLimiter::for('creation-step', fn (Request $request) => Limit::perSecond(2)->by('creation-step:'.$request->session()->getId()));
        // S6 pause / resume / abort / recreate share one limit: 30 per minute per session (each takes row locks).
        RateLimiter::for('creation-operation', fn (Request $request) => Limit::perMinute(30)->by('creation-operation:'.$request->session()->getId()));
        // Every proposal POST, whatever its outcome (#19(1)). Successful saves have their own limits
        // (App\Support\ProposalStoreLimits: 5 per 10 minutes and 30 per day per IP, 500 per day overall).
        RateLimiter::for('proposal-post', fn (Request $request) => Limit::perMinutes(10, 20)->by('proposal-post:'.ClientKey::for($request)));
    }

    /**
     * A bad quota .env must not break artisan/composer/deploy, but must stop HTTP requests.
     */
    public function validateQuotaConfig(bool $console): void
    {
        try {
            QuotaPolicy::validateConfig();
        } catch (InvalidArgumentException $e) {
            if (! $console) {
                throw $e;
            }
            Log::warning('Invalid quota configuration: '.$e->getMessage());
        }
    }
}
