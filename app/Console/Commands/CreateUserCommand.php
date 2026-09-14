<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\HrUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Gives an HR account access to hc-tracking.
 *
 * Self-registration is disabled and passwords live in the HR system, so an
 * account is an existing HR login plus an application role. Name and email are
 * taken from HR; the person logs in with their HR password.
 */
class CreateUserCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'user:create
        {--email= : The email of an existing HR system account}
        {--role= : One of Officer, Manager, MEAL, Admin}
        {--unverified : Leave the email address unverified}';

    /**
     * @var string
     */
    protected $description = 'Give an HR system account access with a role';

    /**
     * Collect the details, validate them, and store the user.
     */
    public function handle(): int
    {
        $interactive = $this->input->isInteractive();

        $email = $this->option('email') ?? ($interactive ? text('HR account email', required: true) : null);
        $role = $this->option('role')
            ?? ($interactive ? select('Role', UserRole::values(), UserRole::Officer->value) : UserRole::Officer->value);

        $validator = Validator::make([
            'email' => $email,
            'role' => $role,
        ], [
            'email' => ['required', 'string', 'email', Rule::unique(User::class)],
            'role' => ['required', Rule::enum(UserRole::class)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $hrUser = HrUser::query()->with('employee')->where('email', $email)->first();

        if ($hrUser === null) {
            $this->components->error("No HR system account uses {$email}.");

            return self::FAILURE;
        }

        $user = User::createFromHrAccount($hrUser, UserRole::from($role), verified: ! $this->option('unverified'));

        $this->components->info("Created {$user->email} as {$user->role->value}.");

        return self::SUCCESS;
    }
}
