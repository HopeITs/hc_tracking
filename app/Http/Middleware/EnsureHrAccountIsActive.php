<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\HrUser;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Signs out a person whose HR account has been deactivated or removed, or
 * whose HR password has changed since this session signed in.
 *
 * Login already refuses inactive HR accounts, but an open session or a remember
 * cookie would otherwise outlive the deactivation. Likewise a session opened
 * with an old HR password (a stolen one, say) must end once HR changes it; the
 * session guard stores a fingerprint of the HR password hash at login (see
 * User::getAuthPassword()), which is compared with HR's current one. HR is
 * asked at most once a minute per user; an answer that would sign someone out
 * is confirmed against HR first, so a login right after a password change is
 * never refused on a stale answer. An unreachable HR database is reported and
 * the request goes on, so an HR outage never signs everyone out; a user HR had
 * already taken access from, by the last answer, is still signed out.
 */
class EnsureHrAccountIsActive
{
    /**
     * The session key SessionGuard::login() stores the password fingerprint
     * under for the web guard.
     */
    private const PasswordFingerprintSessionKey = 'password_hash_web';

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

        if ($user instanceof User && ! $this->maySessionContinue($request, $user)) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException(redirectTo: route('login'));
        }

        return $next($request);
    }

    /**
     * Whether this user's HR account still backs the session.
     *
     * The password is only held against a session the guard logged this user
     * into (by password, passkey or remember cookie); a user set on the guard
     * directly, as tests do, has no fingerprint to compare. A logged-in session
     * from before fingerprints were checked has none yet either: it is given
     * the current one instead of being signed out.
     *
     * When HR cannot be reached to confirm an answer, the cached answer about
     * access still stands; only the password comparison is skipped.
     */
    private function maySessionContinue(Request $request, User $user): bool
    {
        $session = $request->session();
        $isGuardLogin = (string) $session->get($this->guard()->getName()) === (string) $user->getAuthIdentifier();
        $storedFingerprint = $session->get(self::PasswordFingerprintSessionKey);

        $sessionFingerprint = $isGuardLogin && is_string($storedFingerprint) ? $storedFingerprint : null;
        $needsFingerprint = $isGuardLogin && $sessionFingerprint === null;

        $hrAccount = $this->cachedHrAccount($user);

        if ($hrAccount === null) {
            return true;
        }

        if ($needsFingerprint || ! $this->backsSession($hrAccount, $sessionFingerprint)) {
            $freshHrAccount = $this->freshHrAccount($user);

            if ($freshHrAccount === null) {
                return ! $hrAccount['lost_access'];
            }

            $hrAccount = $freshHrAccount;
        }

        if ($needsFingerprint && ! $hrAccount['lost_access']) {
            $session->put(self::PasswordFingerprintSessionKey, $hrAccount['password_fingerprint']);

            return true;
        }

        return $this->backsSession($hrAccount, $sessionFingerprint);
    }

    /**
     * Whether the HR account is active and, when the session holds a password
     * fingerprint, still has that password.
     *
     * @param  array{lost_access: bool, password_fingerprint: string}  $hrAccount
     */
    private function backsSession(array $hrAccount, ?string $sessionFingerprint): bool
    {
        return ! $hrAccount['lost_access']
            && ($sessionFingerprint === null || hash_equals($hrAccount['password_fingerprint'], $sessionFingerprint));
    }

    /**
     * What HR said about this user's account within the last minute, or null
     * when nothing is cached and HR cannot be reached.
     *
     * A cache store that fails is reported and never trusted as an answer: HR is
     * asked directly instead, so a deactivation is still noticed while the cache
     * is down.
     *
     * @return array{lost_access: bool, password_fingerprint: string}|null
     */
    private function cachedHrAccount(User $user): ?array
    {
        try {
            $cached = Cache::get(self::cacheKey($user));
        } catch (Throwable $exception) {
            report($exception);

            return $this->lookUpHrAccountOrNull($user);
        }

        return is_array($cached) ? $cached : $this->freshHrAccount($user);
    }

    /**
     * What HR says about this user's account right now (and cache it), or null
     * when HR cannot be reached, leaving the cached answer as it was.
     *
     * A failure while caching is reported, but HR's answer is still returned.
     *
     * @return array{lost_access: bool, password_fingerprint: string}|null
     */
    private function freshHrAccount(User $user): ?array
    {
        $hrAccount = $this->lookUpHrAccountOrNull($user);

        if ($hrAccount === null) {
            return null;
        }

        try {
            Cache::put(self::cacheKey($user), $hrAccount, 60);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $hrAccount;
    }

    /**
     * What HR says about this user's account right now, or null (reported)
     * when HR cannot be reached.
     *
     * @return array{lost_access: bool, password_fingerprint: string}|null
     */
    private function lookUpHrAccountOrNull(User $user): ?array
    {
        try {
            return $this->lookUpHrAccount($user);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Ask HR whether the account is still active (as User::hasLostHrAccess()
     * does) and fingerprint its password hash from the same row, the way the
     * session guard does at login (see User::getAuthPassword()).
     *
     * Only this keyed fingerprint is cached, never the hash.
     *
     * @return array{lost_access: bool, password_fingerprint: string}
     */
    private function lookUpHrAccount(User $user): array
    {
        $hrUser = HrUser::query()->with('employee')->find($user->hr_user_id);

        if ($hrUser === null || ! $hrUser->isActive()) {
            return ['lost_access' => true, 'password_fingerprint' => ''];
        }

        return [
            'lost_access' => false,
            'password_fingerprint' => $this->guard()->hashPasswordForCookie((string) $hrUser->password),
        ];
    }

    private function guard(): SessionGuard
    {
        /** @var SessionGuard */
        return Auth::guard('web');
    }

    /**
     * The cache key holding one user's last HR account check.
     */
    public static function cacheKey(User $user): string
    {
        return 'hr-account-state:'.$user->id;
    }
}
