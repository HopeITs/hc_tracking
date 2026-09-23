<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HrUser;
use App\Models\Role;
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
 * account is an existing HR login plus an optional application role. Name
 * and email are taken from HR; the person logs in with their HR password.
 */
class CreateUserCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'user:create
        {--email= : The email of an existing HR system account}
        {--role= : An existing role name; omit for no role}
        {--unverified : Leave the email address unverified}';

    /**
     * @var string
     */
    protected $description = 'Give an HR system account access, optionally with a role';

    /**
     * Collect the details, validate them, and store the user.
     */
    public function handle(): int
    {
        $interactive = $this->input->isInteractive();

        $email = $this->option('email') ?? ($interactive ? text('HR account email', required: true) : null);

        $roleNames = Role::query()->orderBy('name')->pluck('name')->all();

        $role = $this->option('role')
            ?? ($interactive ? select('Role', array_merge(['(none)'], $roleNames), '(none)') : null);
        $role = $role === '(none)' ? null : $role;

        $validator = Validator::make([
            'email' => $email,
            'role' => $role,
        ], [
            'email' => ['required', 'string', 'email', Rule::unique(User::class)],
            'role' => ['nullable', 'string', Rule::in($roleNames)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $validated = $validator->validated();

        $hrUser = HrUser::query()->with('employee')->where('email', $email)->first();

        if ($hrUser === null) {
            $this->components->error("No HR system account uses {$email}.");

            return self::FAILURE;
        }

        if (! $hrUser->isActive()) {
            $this->components->error("The HR employee behind {$email} is not active.");

            return self::FAILURE;
        }

        $user = User::createFromHrAccount($hrUser, $validated['role'], verified: ! $this->option('unverified'));

        $this->components->info("Created {$user->email}".($validated['role'] !== null ? " as {$validated['role']}." : ' with no role.'));

        return self::SUCCESS;
    }
}
