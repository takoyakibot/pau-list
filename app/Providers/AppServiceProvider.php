<?php

namespace App\Providers;

use App\Session\PrivacyDatabaseSessionHandler;
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
        RateLimiter::for('proposal-store', fn (Request $request) => [
            Limit::perMinutes(10, 5)->by('m:'.$request->ip()),
            Limit::perDay(30)->by('d:'.$request->ip()),
            Limit::perDay(500)->by('all'),
        ]);
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
