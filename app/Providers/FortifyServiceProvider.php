<?php

namespace App\Providers;

use App\Actions\Fortify\AuthenticateWithHrAccount;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\RedirectIfTwoFactorRequired;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        // After the password: the authenticator app challenge, or an emailed code.
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorRequired::class);

        // Passwords live in the HR system: log in and confirm against HR.
        Fortify::authenticateUsing(new AuthenticateWithHrAccount);
        Fortify::confirmPasswordsUsing(
            fn (User $user, ?string $password): bool => AuthenticateWithHrAccount::confirmsPassword($user, $password),
        );

        // The HR password typed at login also counts as a password confirmation.
        Event::listen(fn (Login $event) => AuthenticateWithHrAccount::confirmPasswordCheckedAtLogin($event));

        // A passkey skips the HR password check, so ask HR whether the account is still active.
        Passkeys::authorizeLoginUsing(fn (Request $request, User $user): bool => ! $user->hasLostHrAccess());
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::registerView(fn () => view('pages::auth.register'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     *
     * The `two-factor` limiter guards both second steps: Fortify's POST
     * /two-factor-challenge (authenticator or recovery code) and the app's
     * POST email-two-factor-challenge. Whoever reaches either already knows
     * the account's HR password, so a per-minute limit alone still allowed
     * about 7,200 guesses a day; the hourly and daily caps bring that down
     * to 30. Each limit needs its own `by` key: ThrottleRequests keys a
     * limit by limiter name plus `by` value, not by its decay, so limits
     * sharing a key would share one counter.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            $loginId = $request->session()->get('login.id');

            return [
                Limit::perMinute(5)->by('minute:'.$loginId),
                Limit::perHour(15)->by('hour:'.$loginId),
                Limit::perDay(30)->by('day:'.$loginId),
            ];
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
