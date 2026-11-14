<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

class QuotaBootValidationTest extends TestCase
{
    public function test_console_logs_warning_instead_of_throwing(): void
    {
        config(['youtube.quota.write_limit' => 99]);
        Log::shouldReceive('warning')->once();
        (new AppServiceProvider($this->app))->validateQuotaConfig(true);
    }

    public function test_http_throws_on_invalid_config(): void
    {
        config(['youtube.quota.write_limit' => 99]);
        $this->expectException(InvalidArgumentException::class);
        (new AppServiceProvider($this->app))->validateQuotaConfig(false);
    }
}
