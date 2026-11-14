<?php

namespace App\Providers;

use App\Http\Controllers\OwnerEditController;
use App\Session\PrivacyDatabaseSessionHandler;
use App\Support\OwnerSession;
use App\Support\QuotaPolicy;
use Illuminate\Cache\RateLimiting\Limit;
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
        $this->configureRateLimiting();
        $this->configureSessions();
        $this->warnAboutDebugInProduction();
        $this->warnAboutInsecureSessionCookie();
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
        RateLimiter::for('global', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('video-lookup', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        // Login start and callback share one limit (spec 8.1, 6.7 J2).
        RateLimiter::for('oauth', fn (Request $request) => Limit::perMinute(10)->by('oauth:'.$request->ip()));
        // The owner's unlisted list spends owner_read: 5 per 10 minutes per owner channel (IP when logged out).
        RateLimiter::for('owner-unlisted', fn (Request $request) => Limit::perMinutes(10, 5)
            ->by('owner-unlisted:'.(app(OwnerSession::class)->current()?->channelId ?? 'ip:'.$request->ip()))
            ->response(fn () => response()->json(['message' => OwnerEditController::UNLISTED_THROTTLED], 429)));
        // Creation start and the retry of failed items share one limit: 5 per hour per owner channel (IP when logged out).
        RateLimiter::for('creation-start', fn (Request $request) => Limit::perHour(5)
            ->by('creation-start:'.(app(OwnerSession::class)->current()?->channelId ?? 'ip:'.$request->ip())));
        // S6 steps: 2 per second per session (spec 8.1); the page waits next_delay_ms (1 s) between steps.
        RateLimiter::for('creation-step', fn (Request $request) => Limit::perSecond(2)->by('creation-step:'.$request->session()->getId()));
        // S6 pause / resume / abort / recreate share one limit: 30 per minute per session (each takes row locks).
        RateLimiter::for('creation-operation', fn (Request $request) => Limit::perMinute(30)->by('creation-operation:'.$request->session()->getId()));
        // Every proposal POST, whatever its outcome (#19(1)). Successful saves have their own limits
        // (App\Support\ProposalStoreLimits: 5 per 10 minutes and 30 per day per IP, 500 per day overall).
        RateLimiter::for('proposal-post', fn (Request $request) => Limit::perMinutes(10, 20)->by('proposal-post:'.$request->ip()));
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
