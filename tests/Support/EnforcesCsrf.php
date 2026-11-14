<?php

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/** Turns CSRF verification back on (it is skipped while running unit tests). */
trait EnforcesCsrf
{
    protected function enforceCsrf(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }
}
