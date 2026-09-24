<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Signs out a person whose HR account has been deactivated or removed.
 *
 * Login already refuses inactive HR accounts, but an open session or a remember
 * cookie would otherwise outlive the deactivation. HR is asked at most once a
 * minute per user; an unreachable HR database is reported and the request goes
 * on, so an HR outage never signs everyone out.
 */
class EnsureHrAccountIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->hasLostHrAccess($user)) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException(redirectTo: route('login'));
        }

        return $next($request);
    }

    /**
     * Whether HR has taken this user's access away, cached for a minute.
     */
    private function hasLostHrAccess(User $user): bool
    {
        try {
            return Cache::remember(self::cacheKey($user), 60, fn (): bool => $user->hasLostHrAccess());
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * The cache key holding one user's last HR access check.
     */
    public static function cacheKey(User $user): string
    {
        return 'hr-access-lost:'.$user->id;
    }
}
