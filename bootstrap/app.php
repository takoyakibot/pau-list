<?php

use App\Http\Middleware\LimitRequestBody;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // prepend: 最外周に置き、フレームワークのグローバルミドルウェアが返す応答
        // （413 PostTooLarge、503 メンテナンスなど）にもヘッダーを付ける
        $middleware->prepend(SecurityHeaders::class);
        $middleware->append(LimitRequestBody::class);
        $middleware->appendToGroup('web', 'throttle:global');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(
                    ['message' => 'アクセスが集中しています。しばらく待ってから再度お試しください'],
                    429,
                    $e->getHeaders(),
                );
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
