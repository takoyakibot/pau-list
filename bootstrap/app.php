<?php

use App\Http\Middleware\LimitRequestBody;
use App\Http\Middleware\RejectCrossSiteRequest;
use App\Http\Middleware\RequireSameOriginFetch;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ProposalFormRedirect;
use App\Support\RateLimitLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Spec 8.8: stack traces in logs must not carry argument values (a proposal key, a token, a contact
// message passed to a method). Applies to web requests and artisan commands alike.
ini_set('zend.exception_ignore_args', '1');

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
        // Reject cross-site lookups and login starts before any rate limiter counts them.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: RequireSameOriginFetch::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: RejectCrossSiteRequest::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Spec 8.8: unhandled database / HTTP client errors are logged without their message, which carries
        // SQL bindings (e.g. key_hash) or the request URL with its query. `return false` stops the default log.
        $exceptions->report(function (QueryException $e) {
            Log::error('Database error', ['sqlstate' => (string) $e->getCode()]);

            return false;
        });
        $exceptions->report(function (RequestException|ConnectionException $e) {
            Log::error('HTTP client error', [
                'exception' => $e::class,
                'status' => $e instanceof RequestException ? $e->response->status() : null,
            ]);

            return false;
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->attributes->get(RateLimitLog::LOGGED, false)) {
                RateLimitLog::exceeded('route:'.($request->route()?->getName() ?? 'none'));
            }
            if ($request->expectsJson()) {
                return response()->json(
                    ['message' => 'アクセスが集中しています。しばらく待ってから再度お試しください'],
                    429,
                    $e->getHeaders(),
                );
            }

            return ProposalFormRedirect::for($request, '保存の操作が集中しています。しばらく待ってから再度お試しください（入力内容は残っています）');
        });
        // The handler maps TokenMismatchException to a 419 HttpException before render callbacks run.
        $exceptions->render(fn (HttpException $e, Request $request) => $e->getPrevious() instanceof TokenMismatchException
            ? ProposalFormRedirect::for(
                $request,
                'ページの有効期限が切れたため保存できませんでした。入力内容は残っているので、もう一度保存してください',
            )
            : null);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
