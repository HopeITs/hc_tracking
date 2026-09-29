<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Fortify\TrustedDevices;
use App\Models\HrUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

/**
 * Recovery for someone who lost both their authenticator app and their
 * recovery codes: clears the app so they can set it up again, ends the trust
 * of every browser and signs the account out everywhere.
 *
 * Deliberately command-line only. A button in the web app would let whoever
 * takes over an admin account strip other users' second factor. Fortify's
 * base action is constructed directly rather than resolved from the
 * container, because the bound DisableTwoFactorAuthenticationUnlessRequired
 * refuses for required roles without an email, and an admin reset must
 * always work. Emailed sign-in codes and passkeys are left as they are.
 *
 * The email is the HR account's (tracking users hold only hr_user_id), so the
 * user is found through HR, as user:create does.
 */
class ResetTwoFactorCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'user:reset-two-factor
        {--email= : The email of the tracking user whose authenticator app is lost}';

    /**
     * @var string
     */
    protected $description = 'Clear a user\'s authenticator app so they can set it up again';

    /**
     * Find the user, confirm, and reset their authenticator app.
     */
    public function handle(): int
    {
        $interactive = $this->input->isInteractive();

        $email = $this->option('email') ?? ($interactive ? text('User email', required: true) : null);

        if (! is_string($email) || $email === '') {
            $this->components->error('An email is required.');

            return self::FAILURE;
        }

        $hrUserId = HrUser::query()->where('email', $email)->value('id');

        $user = $hrUserId === null ? null : User::query()->where('hr_user_id', $hrUserId)->first();

        if ($user === null) {
            $this->components->error("No user signs in with {$email}.");

            return self::FAILURE;
        }

        if ($interactive && ! confirm("Clear the authenticator app of {$email} and sign them out everywhere?", default: false)) {
            $this->components->error('Nothing was changed.');

            return self::FAILURE;
        }

        (new DisableTwoFactorAuthentication)($user);

        TrustedDevices::forgetAll($user);

        $this->signOutEverywhere($user);

        Log::channel('auth')->info('two_factor_reset_by_admin', [
            'user_id' => $user->getKey(),
            'email' => $user->email,
        ]);

        $this->components->info("Cleared the authenticator app of {$email}. They will set up two-factor again from Settings → Security.");

        return self::SUCCESS;
    }

    /**
     * End every session and remember-me cookie the account holds.
     */
    private function signOutEverywhere(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table'))
                ->where('user_id', $user->getKey())
                ->delete();
        }

        $user->setRememberToken(Str::random(60));
        $user->save();
    }
}
