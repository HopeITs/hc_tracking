<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

/**
 * Fortify's login step that stops for a second factor, bound in its place by
 * FortifyServiceProvider, extended with email codes.
 *
 * Someone with a confirmed authenticator app gets Fortify's app challenge as
 * before. Someone who turned on email codes, or whose role requires
 * two-factor, is emailed a one-time code and sent to the email challenge.
 * Everyone else, and any browser trusted with "don't ask again" (see
 * TrustedDevices), signs straight in.
 */
class RedirectIfTwoFactorRequired extends RedirectIfTwoFactorAuthenticatable
{
    /**
     * Session key marking a login that is waiting for its emailed code.
     */
    public const EmailChallengeSessionKey = 'login.email_code';

    /**
     * Handle the incoming request.
     *
     * @param  Request  $request
     * @param  callable  $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        if ($user instanceof User && TrustedDevices::trusts($request, $user)) {
            return $next($request);
        }

        if ($user instanceof User && $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null) {
            return $this->twoFactorChallengeResponse($request, $user);
        }

        if ($user instanceof User && $user->usesEmailTwoFactor()) {
            if (! EmailLoginCodes::send($user)) {
                throw ValidationException::withMessages([
                    Fortify::username() => [__('authentication.email_code_not_sent')],
                ]);
            }

            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => $request->boolean('remember'),
                self::EmailChallengeSessionKey => true,
            ]);

            return $request->wantsJson()
                ? response()->json(['two_factor' => true, 'method' => 'email'])
                : redirect()->route('email-two-factor.login');
        }

        return $next($request);
    }
}
