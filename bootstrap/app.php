<?php

use App\Http\DeniedAccessRecorder;
use App\Http\Middleware\EnsureHrAccountIsActive;
use App\Http\Middleware\EnsureRequestCameThroughTrustedProxy;
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
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(EnsureRequestCameThroughTrustedProxy::class);

        $middleware->web(append: [
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
