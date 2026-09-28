<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/**
 * Fortify's "disable two-factor" action, bound in its place by
 * AppServiceProvider, that will not switch off a working authenticator app
 * when it is the only second factor a required role has (no email to send
 * codes to; see EnsureTwoFactorIsEnabled).
 *
 * A set-up that was started but never finished is still cleared: the security
 * page and Fortify's confirmation checks rely on that to start over.
 */
class DisableTwoFactorAuthenticationUnlessRequired extends DisableTwoFactorAuthentication
{
    /**
     * Disable two factor authentication for the user.
     *
     * @param  mixed  $user
     *
     * @throws ValidationException
     */
    public function __invoke($user)
    {
        if ($user->hasEnabledTwoFactorAuthentication() && EnsureTwoFactorIsEnabled::appIsOnlyFactorFor($user)) {
            throw ValidationException::withMessages([
                'two_factor' => __('settings.two_factor_required_cannot_disable'),
            ]);
        }

        parent::__invoke($user);
    }
}
