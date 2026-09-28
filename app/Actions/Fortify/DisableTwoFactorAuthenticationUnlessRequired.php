<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Models\User;
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
 *
 * Switching off an app that was on also forgets every trusted browser
 * (TrustedDevices), so trust earned with the old app cannot keep skipping the
 * code. This lives here rather than in a TwoFactorAuthenticationDisabled
 * listener because the security page runs this same action on every visit to
 * clear an unfinished set-up, which must not end the trust of someone who
 * signs in with emailed codes.
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
        $appWasOn = $user->hasEnabledTwoFactorAuthentication();

        if ($appWasOn && EnsureTwoFactorIsEnabled::appIsOnlyFactorFor($user)) {
            throw ValidationException::withMessages([
                'two_factor' => __('settings.two_factor_required_cannot_disable'),
            ]);
        }

        parent::__invoke($user);

        if ($appWasOn && $user instanceof User) {
            TrustedDevices::forgetAll($user);
        }
    }
}
