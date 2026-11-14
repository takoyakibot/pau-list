<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConfigurationTest extends TestCase
{
    private const SPEC_ENV_KEYS = [
        'APP_NAME', 'APP_ENV', 'APP_KEY', 'APP_DEBUG', 'APP_URL',
        'LOG_CHANNEL', 'LOG_LEVEL', 'LOG_DAILY_DAYS',
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_SOCKET', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        'SESSION_DRIVER', 'SESSION_ENCRYPT', 'SESSION_LIFETIME', 'SESSION_SECURE_COOKIE', 'SESSION_SAME_SITE',
        'CACHE_STORE', 'QUEUE_CONNECTION',
        'YOUTUBE_API_KEY', 'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI',
        'QUOTA_WRITE_LIMIT', 'QUOTA_READ_BUDGET', 'QUOTA_GOOGLE_DAILY',
        'CHANNEL_FETCH_MAX_VIDEOS', 'CHANNEL_CACHE_REUSE_MINUTES', 'CHANNEL_SYNC_KNOWN_RUN',
        'DATA_RETENTION_DAYS', 'CAPTCHA_ENABLED',
        'MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_SCHEME', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
        'CONTACT_TO_ADDRESS',
    ];

    private const DEFAULT_KEYS = [
        'QUOTA_WRITE_LIMIT', 'QUOTA_READ_BUDGET', 'QUOTA_GOOGLE_DAILY',
        'CHANNEL_FETCH_MAX_VIDEOS', 'CHANNEL_CACHE_REUSE_MINUTES', 'CHANNEL_SYNC_KNOWN_RUN',
        'DATA_RETENTION_DAYS', 'CAPTCHA_ENABLED',
    ];

    /** @var array<string, array{env: mixed, server: mixed, getenv: string|false}> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $s) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
            if ($s['env'] !== null) {
                $_ENV[$key] = $s['env'];
            }
            if ($s['server'] !== null) {
                $_SERVER[$key] = $s['server'];
            }
            if ($s['getenv'] !== false) {
                putenv("$key={$s['getenv']}");
            }
        }
        $this->saved = [];

        parent::tearDown();
    }

    public function test_env_example_lists_every_spec_key(): void
    {
        $env = file_get_contents(base_path('.env.example'));
        foreach (self::SPEC_ENV_KEYS as $key) {
            $this->assertMatchesRegularExpression('/^#?\s*'.$key.'=/m', $env, $key);
        }
        $this->assertDoesNotMatchRegularExpression('/PROPOSAL_MAX_ITEMS/', $env);
        $this->assertStringContainsString('APP_NAME="List Maker"', $env);
        $this->assertStringContainsString('GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"', $env);
    }

    public function test_env_example_has_safe_values(): void
    {
        $env = file_get_contents(base_path('.env.example'));
        foreach (['SESSION_DRIVER=database', 'SESSION_ENCRYPT=true', 'SESSION_SAME_SITE=lax', 'CACHE_STORE=database',
            'QUEUE_CONNECTION=sync', 'LOG_CHANNEL=daily', 'LOG_DAILY_DAYS=14', 'CAPTCHA_ENABLED=false', 'TRUSTED_PROXIES='] as $line) {
            $this->assertMatchesRegularExpression('/^'.preg_quote($line, '/').'$/m', $env, $line);
        }
    }

    public function test_readme_documents_operations(): void
    {
        $readme = file_get_contents(base_path('README.md'));
        foreach (['# List Maker', 'LOG_LEVEL=info', 'TRUSTED_PROXIES=', 'CONTACT_TO_ADDRESS=', 'php artisan app:delete-proposal',
            'php artisan app:release-creation-reservation', 'curl -sI https://list-maker.alpacasandbag.jp/ | head -1',
            'https://myaccount.google.com/permissions', '公開前の人手作業'] as $text) {
            $this->assertStringContainsString($text, $readme, $text);
        }
        $this->assertStringNotContainsString('仮称', $readme);
    }

    public function test_env_example_says_production_log_level_is_info(): void
    {
        $env = file_get_contents(base_path('.env.example'));
        $this->assertMatchesRegularExpression('/^#.*本番.*LOG_LEVEL=info.*\nLOG_LEVEL=/m', $env);
    }

    public function test_config_keys_exist_with_expected_types(): void
    {
        foreach (['quota.write_limit', 'quota.read_budget', 'quota.google_daily',
            'channel.fetch_max_videos', 'channel.cache_reuse_minutes', 'channel.sync_known_run'] as $k) {
            $this->assertIsInt(config("youtube.$k"), "youtube.$k");
        }
        $this->assertTrue(array_key_exists('api_key', config('youtube')));
        $this->assertNull(config('youtube.max_items'));
        $this->assertSame(['client_id', 'client_secret', 'redirect'], array_keys(config('services.google')));
        $this->assertIsInt(config('listmaker.retention_days'));
        $this->assertIsBool(config('listmaker.captcha_enabled'));
        $this->assertTrue(array_key_exists('contact_to_address', config('listmaker')));
    }

    private function setEnv(string $key, ?string $value): void
    {
        if (! array_key_exists($key, $this->saved)) {
            $this->saved[$key] = [
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
                'getenv' => getenv($key),
            ];
        }
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        if ($value !== null) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("$key=$value");
        }
    }

    public function test_defaults_match_spec_when_env_is_absent(): void
    {
        foreach (self::DEFAULT_KEYS as $key) {
            $this->setEnv($key, null);
        }

        $youtube = require config_path('youtube.php');
        $listmaker = require config_path('listmaker.php');

        $this->assertSame(5000, $youtube['quota']['write_limit']);
        $this->assertSame(2000, $youtube['quota']['read_budget']);
        $this->assertSame(10000, $youtube['quota']['google_daily']);
        $this->assertSame(1000, $youtube['channel']['fetch_max_videos']);
        $this->assertSame(360, $youtube['channel']['cache_reuse_minutes']);
        $this->assertSame(3, $youtube['channel']['sync_known_run']);
        $this->assertSame(30, $listmaker['retention_days']);
        $this->assertFalse($listmaker['captcha_enabled']);
    }

    /**
     * サーバーの .env に行が抜けていても、セキュリティ・運用上の既定値が安全側になること（spec 3 / 6.5 / 8.6）。
     */
    public function test_security_and_ops_defaults_when_env_is_absent(): void
    {
        foreach (['SESSION_ENCRYPT', 'SESSION_SECURE_COOKIE', 'QUEUE_CONNECTION', 'LOG_CHANNEL'] as $key) {
            $this->setEnv($key, null);
        }

        $this->setEnv('APP_ENV', 'production');
        $session = require config_path('session.php');
        $this->assertTrue($session['encrypt']);
        $this->assertTrue($session['secure']);
        $this->assertSame('sync', (require config_path('queue.php'))['default']);
        $this->assertSame('daily', (require config_path('logging.php'))['default']);

        $this->setEnv('APP_ENV', 'local');
        $session = require config_path('session.php');
        $this->assertTrue($session['encrypt']);
        $this->assertFalse($session['secure']);
    }
}
