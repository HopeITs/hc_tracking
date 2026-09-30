<?php

use App\Http\DeniedAccessRecorder;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureHrAccountIsActive;
<<<<<<< HEAD
use App\Http\Middleware\EnsureRequestCameThroughTrustedProxy;
use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Http\Middleware\RedirectToHttps;
=======
use App\Http\Middleware\SecurityHeaders;
>>>>>>> gitlab/security-mai
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SyncProgramsFromHr;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // POST /broadcasting/auth gets the same gate as every app page (signed
    // in, two-factor enrolled where required) plus its own rate limit.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'middleware' => ['web', 'auth', 'throttle:broadcasting', EnsureTwoFactorIsEnabled::class],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([
            AddSecurityHeaders::class,
            EnsureRequestCameThroughTrustedProxy::class,
        ]);

        // Appended, not prepended: it must run after TrustProxies so that
        // isSecure() believes a trusted proxy's X-Forwarded-Proto.
        $middleware->append(RedirectToHttps::class);

        $middleware->web(append: [
            SecurityHeaders::class,
            SetLocale::class,
            SyncProgramsFromHr::class,
            EnsureHrAccountIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every refused request (Gate::authorize, ->can() routes, abort(403))
        // is audited; recording never changes or breaks the 403 response.
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request): null {
            if ($exception->getStatusCode() === 403) {
                rescue(fn () => app(DeniedAccessRecorder::class)->record($request), report: false);
            }

            return null;
        });
    })->create();
