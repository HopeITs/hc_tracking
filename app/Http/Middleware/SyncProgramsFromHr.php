<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Jobs\CheckProgramLogframe;
use App\Models\Program;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Keeps the local program mirror in step with the HR database.
 *
 * Runs at most once a minute across all requests. An unreachable HR database
 * is reported rather than raised, so tracking still loads with the last copy.
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
                $newProgramIds = Program::syncFromHr();

                if ($newProgramIds !== []) {
                    CheckProgramLogframe::dispatch($newProgramIds);
                }
            } catch (Throwable $exception) {
                Cache::forget(self::CacheKey);
                report($exception);
            }
        }

        return $next($request);
    }
}
