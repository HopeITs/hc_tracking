<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Laravel\Fortify\Features;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor rules for the roles that must use it
 * (config('access-control.two_factor')).
 *
 * Those roles are always asked for an emailed sign-in code (see
 * App\Actions\Fortify\RedirectIfTwoFactorRequired); the authenticator app is
 * optional and replaces the email code when set up. Only someone with no
 * email address to send a code to must set up the app, and this middleware
 * keeps them on the security settings page until they have.
 *
 * It sits on every signed-in route group except the security page itself.
 * Livewire update requests are checked twice (see
 * AppServiceProvider::configureLivewire()): Livewire re-runs this middleware
 * for components of guarded pages, and every component outside
 * EnrolmentComponents is refused however it was rendered, so a widget shown
 * on the security page (or an old snapshot of one) cannot be driven either.
 *
 * Livewire discards any response a persistent middleware returns unless it
 * is a redirect, so refusals are thrown rather than returned: a JSON request
 * gets a 403, anything else is sent to the security page.
 */
class EnsureTwoFactorIsEnabled
{
    /**
     * The Livewire components someone still enrolling may use: the security
     * page and its two-factor set-up and recovery-code components.
     *
     * @var list<string>
     */
    public const EnrolmentComponents = [
        'pages::settings.security',
        'pages::settings.two-factor-setup-modal',
        'pages::settings.two-factor.recovery-codes',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (self::mustEnrol($request->user())) {
            self::refuse($request);
        }

        return $next($request);
    }

    /**
     * Refuse a Livewire update to a component outside EnrolmentComponents
     * while the signed-in person still has to enrol.
     */
    public static function guardLivewireComponent(Component $component): void
    {
        if (! in_array($component->getName(), self::EnrolmentComponents, true) && self::mustEnrol(auth()->user())) {
            self::refuse(request());
        }
    }

    /**
     * Whether the user's role must use two-factor authentication.
     *
     * Never while enforcement is off, nor while Fortify offers no two-factor
     * set-up, since nobody could then enrol.
     */
    public static function isRequiredFor(?Authenticatable $user): bool
    {
        return Features::canManageTwoFactorAuthentication()
            && self::roleRequiresTwoFactor($user);
    }

    /**
     * Whether the user's role must use two-factor authentication, however it
     * is provided: an emailed code needs no Fortify feature.
     */
    public static function roleRequiresTwoFactor(?Authenticatable $user): bool
    {
        return config('access-control.two_factor.enforced')
            && $user instanceof User
            && $user->hasAnyRole(config('access-control.two_factor.required_roles', []));
    }

    /**
     * Whether the user must give an emailed code at sign-in whatever their own
     * setting says: their role requires two-factor and they have an email.
     */
    public static function emailCodeRequiredFor(?Authenticatable $user): bool
    {
        return $user instanceof User
            && $user->email !== ''
            && self::roleRequiresTwoFactor($user);
    }

    /**
     * Whether the authenticator app is the only second factor the user's role
     * can use (there is no email to send codes to), so it must stay on.
     */
    public static function appIsOnlyFactorFor(?Authenticatable $user): bool
    {
        return $user instanceof User
            && $user->email === ''
            && self::isRequiredFor($user);
    }

    /**
     * Whether the user still has to turn on (and confirm) the authenticator
     * app before using anything but the security page. The columns are read
     * first, so almost everyone costs no role query.
     */
    public static function mustEnrol(?Authenticatable $user): bool
    {
        return $user instanceof User
            && ! $user->hasEnabledTwoFactorAuthentication()
            && self::appIsOnlyFactorFor($user);
    }

    /**
     * Stop the request: a 403 for JSON, otherwise a redirect to the security
     * page carrying the reason.
     */
    private static function refuse(Request $request): never
    {
        $notice = __('settings.two_factor_required_notice');

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, $notice);
        }

        throw new HttpResponseException(redirect()->route('security.edit')->with('status', $notice));
    }
}
