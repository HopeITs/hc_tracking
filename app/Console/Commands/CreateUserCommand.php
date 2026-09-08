<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Creates a user account.
 *
 * Self-registration is disabled, so this is the only way an account comes into
 * existence until an admin UI exists.
 */
class CreateUserCommand extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * @var string
     */
    protected $signature = 'user:create
        {--name= : The full name}
        {--email= : The email address}
        {--role= : One of Officer, Manager, MEAL, Admin}
        {--password= : Plain-text password, prompted for when omitted}
        {--unverified : Leave the email address unverified}';

    /**
     * @var string
     */
    protected $description = 'Create a user account';

    /**
     * Collect the details, validate them, and store the user.
     */
    public function handle(): int
    {
        $interactive = $this->input->isInteractive();

        $name = $this->option('name') ?? ($interactive ? text('Full name', required: true) : null);
        $email = $this->option('email') ?? ($interactive ? text('Email address', required: true) : null);
        $role = $this->option('role')
            ?? ($interactive ? select('Role', UserRole::values(), UserRole::Officer->value) : UserRole::Officer->value);

        $plainPassword = $this->option('password');
        $confirmation = $plainPassword;

        if ($plainPassword === null && $interactive) {
            $plainPassword = password('Password');
            $confirmation = password('Confirm password');
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'password' => $plainPassword,
            'password_confirmation' => $confirmation,
        ], [
            ...$this->profileRules(),
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $user = new User;

        // `role` is deliberately not fillable, so force it rather than widening
        // mass assignment on the model.
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => $plainPassword,
            'role' => UserRole::from($role),
            'email_verified_at' => $this->option('unverified') ? null : now(),
        ])->save();

        $this->components->info("Created {$user->email} as {$user->role->value}.");

        return self::SUCCESS;
    }
}
