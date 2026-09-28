<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
use Throwable;

/**
 * Writes one line to the `auth` log channel (storage/logs/auth-*.log) for every
 * sign-in, failure, lockout, sign-out, two-factor and passkey event.
 *
 * Each line carries the event name, user id and email when known, IP, user
 * agent and guard. Passwords, session ids, remember tokens, two-factor
 * secrets and recovery codes are never written: a failed attempt logs only
 * the username field of its credentials. A logging failure is reported and
 * never breaks the sign-in it describes.
 */
final class LogAuthenticationEvent
{
    public function __construct(private Request $request) {}

    public function handle(
        Login|Failed|Lockout|Logout|OtherDeviceLogout|CurrentDeviceLogout
        |TwoFactorAuthenticationEnabled|TwoFactorAuthenticationConfirmed|TwoFactorAuthenticationDisabled
        |TwoFactorAuthenticationChallenged|TwoFactorAuthenticationFailed|ValidTwoFactorAuthenticationCodeProvided
        |RecoveryCodesGenerated|RecoveryCodeReplaced
        |PasskeyRegistered|PasskeyDeleted|PasskeyVerified $event,
    ): void {
        try {
            Log::channel('auth')->info($this->name($event), $this->context($event));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function name(object $event): string
    {
        return match ($event::class) {
            Login::class => 'login',
            Failed::class => 'login_failed',
            Lockout::class => 'lockout',
            Logout::class => 'logout',
            OtherDeviceLogout::class => 'other_device_logout',
            CurrentDeviceLogout::class => 'current_device_logout',
            TwoFactorAuthenticationEnabled::class => 'two_factor_enabled',
            TwoFactorAuthenticationConfirmed::class => 'two_factor_confirmed',
            TwoFactorAuthenticationDisabled::class => 'two_factor_disabled',
            TwoFactorAuthenticationChallenged::class => 'two_factor_challenged',
            TwoFactorAuthenticationFailed::class => 'two_factor_failed',
            ValidTwoFactorAuthenticationCodeProvided::class => 'two_factor_passed',
            RecoveryCodesGenerated::class => 'recovery_codes_generated',
            RecoveryCodeReplaced::class => 'recovery_code_used',
            PasskeyRegistered::class => 'passkey_registered',
            PasskeyDeleted::class => 'passkey_deleted',
            PasskeyVerified::class => 'passkey_verified',
            default => $event::class,
        };
    }

    /**
     * @return array{user_id: int|string|null, email: string|null, ip: string|null, user_agent: string|null, guard: string|null, passkey_id?: int|string|null}
     */
    private function context(object $event): array
    {
        $request = $event instanceof Lockout ? $event->request : $this->request;
        $user = $event instanceof Lockout ? $request->user() : $event->user;
        $userAgent = $request->userAgent();

        $context = [
            'user_id' => $user instanceof Authenticatable ? $user->getAuthIdentifier() : null,
            'email' => $this->email($event, $user, $request),
            'ip' => $request->ip(),
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            'guard' => property_exists($event, 'guard') ? $event->guard : config('fortify.guard'),
        ];

        if ($event instanceof PasskeyRegistered || $event instanceof PasskeyDeleted || $event instanceof PasskeyVerified) {
            $context['passkey_id'] = $event->passkey->getKey();
        }

        return $context;
    }

    /**
     * The account's HR email for a known user (for a lockout, whoever is
     * signed in, e.g. a throttled password confirmation), otherwise the
     * username typed into the login form. Never anything else from the
     * credentials.
     */
    private function email(object $event, mixed $user, Request $request): ?string
    {
        if ($user instanceof User) {
            try {
                $email = $user->email;
            } catch (Throwable $exception) {
                report($exception);

                return null;
            }

            return $email === '' ? null : $email;
        }

        $username = match (true) {
            $event instanceof Failed => $event->credentials[Fortify::username()] ?? null,
            $event instanceof Lockout => $request->input(Fortify::username()),
            default => null,
        };

        return is_string($username) && $username !== '' ? mb_substr($username, 0, 255) : null;
    }
}
