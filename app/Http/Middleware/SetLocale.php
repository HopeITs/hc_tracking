<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Locale;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the interface language for the request.
 *
 * A signed-in person's saved choice wins, then the choice stored in the session
 * (guests on the login page), then the application default.
 */
class SetLocale
{
    public const SessionKey = 'locale';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Locale::tryFrom((string) $request->user()?->locale)
            ?? Locale::tryFrom((string) $request->session()->get(self::SessionKey))
            ?? Locale::tryFrom((string) config('app.locale'))
            ?? Locale::English;

        app()->setLocale($locale->value);
        Carbon::setLocale($locale->carbonLocale());
        CarbonImmutable::setLocale($locale->carbonLocale());

        return $next($request);
    }
}
