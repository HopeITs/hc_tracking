<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Program;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Keeps every program's manager in step with HR's Program Manager position.
 *
 * Runs at most once a minute across all requests. An unreachable HR database
 * is reported rather than raised, so tracking still loads with the last copy.
 *
 * Since W-34b (D-68) this no longer creates programs — tracking owns them —
 * so the logframe-gap job it used to dispatch for new rows now hangs off
 * ProgramObserver::created instead.
 */
class SyncProgramsFromHr
{
    public const CacheKey = 'hr-programs-synced';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Cache::add(self::CacheKey, true, 60)) {
            try {
                Program::syncFromHr();
            } catch (Throwable $exception) {
                Cache::forget(self::CacheKey);
                report($exception);
            }
        }

        return $next($request);
    }
}
