<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\HrUser;
use App\Models\User;
use App\Support\BackoffThrottle;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

/**
 * Logs people in with their HR account.
 *
 * The password is checked against hr_db.hchr_users, never the tracking users
 * table. Every HR account may log in: its tracking user, which holds the role,
 * two-factor settings and passkeys, is created on the first login when it does
 * not exist yet (see User::forHrAccount()).
 *
 * On top of Fortify's own limit (a few attempts a minute per email and IP),
 * failed logins are limited per IP across all emails (password spraying) and
 * per email across all IPs (distributed guessing). Only failures count, so a
 * whole office signing in from one IP is never blocked by its own logins.
 */
class AuthenticateWithHrAccount
{
    /**
     * The session key holding which user's HR password a login just checked,
     * and when, until the login completes.
     */
    private const PasswordCheckedSessionKey = 'auth.hr_password_checked';

    /**
     * Failed logins one IP may make, across all emails, per decay window.
     */
    public const MaxFailedLoginsPerIp = 30;

    /**
     * Failed logins one email may take, across all IPs, per decay window.
     */
    public const MaxFailedLoginsPerEmail = 10;

    /**
     * How long, in seconds, a failed login counts towards the limits.
     */
    public const FailedLoginDecaySeconds = 900;

    /**
     * Failed password confirmations one user may make from one IP per decay
     * window.
     */
    public const MaxFailedConfirmationsPerUserAndIp = 5;

    /**
     * How long, in seconds, a failed confirmation counts towards the limit
     * per user and IP.
     */
    public const FailedConfirmationsPerUserAndIpDecaySeconds = 60;

    /**
     * Failed password confirmations one user may make, across all IPs, per
     * decay window.
     */
    public const MaxFailedConfirmationsPerUser = 10;

    /**
     * How long, in seconds, a failed confirmation counts towards the limit
     * per user.
     */
    public const FailedConfirmationsPerUserDecaySeconds = 900;

    /**
     * Exponential backoff for the per-email and per-user limits: the first
     * time one trips it locks for this many seconds, then double that, and so
     * on. The first lockout equals the decay window, so a single burst of
     * failures behaves as before; repeat bursts are what get longer.
     */
    public const BackoffBaseSeconds = 900;

    /**
     * The longest a lockout can grow to. Kept to hours, not days, because
     * anyone can fail on purpose against someone else's email to lock it.
     */
    public const BackoffCapSeconds = 21600;

    /**
     * Resolve the tracking user for valid HR credentials.
     *
     * An unknown email fails onto the email field (Fortify's default
     * behaviour); a known email with the wrong password fails onto the
     * password field instead, so the login form can point at the field that
     * actually needs fixing.
     *
     * @throws ValidationException
     */
    public function __invoke(Request $request): ?User
    {
        $email = (string) $request->input(Fortify::username());
        $password = (string) $request->input('password');

        if ($email === '' || $password === '') {
            return null;
        }

        $limits = [
            self::failedLoginsByIpKey($request) => [self::MaxFailedLoginsPerIp, self::FailedLoginDecaySeconds],
            self::failedLoginsByEmailKey($email) => [self::MaxFailedLoginsPerEmail, self::FailedLoginDecaySeconds],
        ];

        $backoffKey = self::failedLoginsByEmailKey($email);

        self::ensureIsNotThrottled($request, $limits, Fortify::username(), 'auth.throttle', $backoffKey);

        $hrUser = self::findHrUserByEmail($email);

        if ($hrUser === null || ! $hrUser->isActive()) {
            self::recordFailures($limits, $backoffKey, self::MaxFailedLoginsPerEmail);

            return null;
        }

        if (! Hash::check($password, $hrUser->password)) {
            self::recordFailures($limits, $backoffKey, self::MaxFailedLoginsPerEmail);

            app(LoginRateLimiter::class)->increment($request);

            // Throwing here skips Fortify's own Failed event, so fire it
            // without the password.
            event(new Failed('web', null, [Fortify::username() => $email]));

            throw ValidationException::withMessages([
                'password' => [trans('auth.failed')],
            ]);
        }

        RateLimiter::clear($backoffKey);
        BackoffThrottle::clear($backoffKey);

        $user = User::forHrAccount($hrUser);

        $user->linkToHrAccount($hrUser);

        $request->session()->put(self::PasswordCheckedSessionKey, [
            'user_id' => $user->id,
            'checked_at' => Date::now()->unix(),
        ]);

        return $user;
    }

    /**
     * Count the HR password typed at login as a password confirmation, so the
     * person is not asked for it again straight away (e.g. to turn on
     * two-factor authentication). Listens for the guard's Login event.
     *
     * Only the login that checked the password counts: the check is recorded
     * for that user and taken from the session at the next login, so a
     * two-factor challenge completed later still counts from the moment the
     * password was typed, while a passkey or remember-me login (or another
     * user's) after an unfinished password login confirms nothing.
     */
    public static function confirmPasswordCheckedAtLogin(Login $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $session = app('session.store');
        $passwordCheck = $session->pull(self::PasswordCheckedSessionKey);

        if (is_array($passwordCheck) && (string) ($passwordCheck['user_id'] ?? '') === (string) $event->user->getAuthIdentifier()) {
            $session->put('auth.password_confirmed_at', (int) $passwordCheck['checked_at']);
        }
    }

    /**
     * The HR account behind this user, when the password is its HR password.
     *
     * Every failed check counts towards a limit per user and IP and one per
     * user across all IPs, so this cannot be used to guess the HR password:
     * once too many checks failed, the next one is refused (even with the
     * right password) until the limit lapses. A failed check is reported as a
     * Failed auth event (without the password), so the auth log sees it.
     *
     * @throws ValidationException
     */
    public static function confirmsPassword(User $user, ?string $password): bool
    {
        $request = request();
        $limits = [
            'confirm-password:'.$user->id.'|'.$request->ip() => [self::MaxFailedConfirmationsPerUserAndIp, self::FailedConfirmationsPerUserAndIpDecaySeconds],
            'confirm-password-user:'.$user->id => [self::MaxFailedConfirmationsPerUser, self::FailedConfirmationsPerUserDecaySeconds],
        ];

        $backoffKey = 'confirm-password-user:'.$user->id;

        self::ensureIsNotThrottled($request, $limits, 'password', 'auth.confirm_throttle', $backoffKey);

        if (self::hrAccountFor($user->email, (string) $password) === null) {
            self::recordFailures($limits, $backoffKey, self::MaxFailedConfirmationsPerUser);

            event(new Failed('web', $user, [Fortify::username() => $user->email]));

            return false;
        }

        foreach (array_keys($limits) as $key) {
            RateLimiter::clear($key);
        }

        BackoffThrottle::clear($backoffKey);

        return true;
    }

    /**
     * Refuse the attempt when any of these limits has been reached.
     *
     * @param  array<string, array{int, int}>  $limits  max attempts and decay seconds, by rate limiter key
     * @param  string|null  $backoffKey  a key whose exponential backoff lockout also refuses the attempt
     *
     * @throws ValidationException
     */
    private static function ensureIsNotThrottled(Request $request, array $limits, string $field, string $messageKey, ?string $backoffKey = null): void
    {
        $seconds = $backoffKey === null ? 0 : BackoffThrottle::lockedForSeconds($backoffKey);
        $isThrottled = $seconds > 0;

        foreach ($limits as $key => [$maxAttempts]) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                $isThrottled = true;
                $seconds = max($seconds, RateLimiter::availableIn($key));
            }
        }

        if (! $isThrottled) {
            return;
        }

        event(new Lockout($request));

        throw ValidationException::withMessages([
            $field => [trans($messageKey, [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ])],
        ]);
    }

    /**
     * Count one failed attempt against each of these limits.
     *
     * When the limit under $backoffKey is reached by it, that key is locked
     * out for longer than the last time (exponential backoff).
     *
     * @param  array<string, array{int, int}>  $limits  max attempts and decay seconds, by rate limiter key
     */
    private static function recordFailures(array $limits, string $backoffKey, int $backoffMaxAttempts): void
    {
        foreach ($limits as $key => [, $decaySeconds]) {
            RateLimiter::hit($key, $decaySeconds);
        }

        if (RateLimiter::tooManyAttempts($backoffKey, $backoffMaxAttempts)) {
            BackoffThrottle::escalate($backoffKey, self::BackoffBaseSeconds, self::BackoffCapSeconds);
        }
    }

    private static function failedLoginsByIpKey(Request $request): string
    {
        return 'login-failures-ip:'.$request->ip();
    }

    /**
     * The per-email key holds a hash of the email, so any email fits in the
     * cache key and no address is stored in the cache.
     */
    private static function failedLoginsByEmailKey(string $email): string
    {
        return 'login-failures-email:'.sha1(Str::transliterate(Str::lower(trim($email))));
    }

    /**
     * The HR account with this email, when the password matches it.
     */
    private static function hrAccountFor(string $email, string $password): ?HrUser
    {
        if ($email === '' || $password === '') {
            return null;
        }

        $hrUser = self::findHrUserByEmail($email);

        return $hrUser !== null && $hrUser->isActive() && Hash::check($password, $hrUser->password) ? $hrUser : null;
    }

    private static function findHrUserByEmail(string $email): ?HrUser
    {
        return HrUser::query()->with('employee')->where('email', $email)->first();
    }
}
