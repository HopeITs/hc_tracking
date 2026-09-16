<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\HrUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
 */
class AuthenticateWithHrAccount
{
    /**
     * Resolve the tracking user for valid HR credentials.
     *
     * An unknown email fails onto the email field (Fortify's default
     * behaviour); a known email with the wrong password fails onto the
     * password field instead, so the login form can point at the field that
     * actually needs fixing.
     */
    public function __invoke(Request $request): ?User
    {
        $email = (string) $request->input(Fortify::username());
        $password = (string) $request->input('password');

        if ($email === '' || $password === '') {
            return null;
        }

        $hrUser = self::findHrUserByEmail($email);

        if ($hrUser === null) {
            return null;
        }

        if (! Hash::check($password, $hrUser->password)) {
            app(LoginRateLimiter::class)->increment($request);

            throw ValidationException::withMessages([
                'password' => [trans('auth.failed')],
            ]);
        }

        $user = User::forHrAccount($hrUser);

        $user->linkToHrAccount($hrUser);

        return $user;
    }

    /**
     * The HR account behind this user, when the password is its HR password.
     */
    public static function confirmsPassword(User $user, ?string $password): bool
    {
        return self::hrAccountFor($user->email, (string) $password) !== null;
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

        return $hrUser !== null && Hash::check($password, $hrUser->password) ? $hrUser : null;
    }

    private static function findHrUserByEmail(string $email): ?HrUser
    {
        return HrUser::query()->with('employee')->where('email', $email)->first();
    }
}
