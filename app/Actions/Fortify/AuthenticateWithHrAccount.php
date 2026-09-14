<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\HrUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;

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
     */
    public function __invoke(Request $request): ?User
    {
        $email = (string) $request->input(Fortify::username());
        $hrUser = self::hrAccountFor($email, (string) $request->input('password'));

        if ($hrUser === null) {
            return null;
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

        $hrUser = HrUser::query()->with('employee')->where('email', $email)->first();

        return $hrUser !== null && Hash::check($password, $hrUser->password) ? $hrUser : null;
    }
}
