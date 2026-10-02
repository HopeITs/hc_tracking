<?php

use App\Http\DeniedAccessRecorder;
use App\Http\Middleware\EnsureHrAccountIsActive;
use App\Http\Middleware\EnsureRequestCameThroughTrustedProxy;
use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Http\Middleware\RedirectToHttps;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SyncProgramsFromHr;
use App\Http\Middleware\ThrottleRequestsWithoutSession;
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
            SecurityHeaders::class,
            EnsureRequestCameThroughTrustedProxy::class,
        ]);

        // Appended, not prepended: it must run after TrustProxies so that
        // isSecure() believes a trusted proxy's X-Forwarded-Proto.
        $middleware->append(RedirectToHttps::class);

        // Global, not in the `web` group: there it would be sorted behind the
        // session on routes that list a throttle first (Livewire's update
        // route), and must see the cookies before they are decrypted. After
        // TrustProxies, so $request->ip() is the visitor behind a proxy.
        $middleware->append(ThrottleRequestsWithoutSession::class);

        // `throttle:guest` limits signed-out requests that do carry a session.
        $middleware->web(append: [
            'throttle:guest',
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
