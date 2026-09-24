<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * `name` and `email` are not tracking columns: they describe the HR
     * account the user is linked to (see newModel()).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Build the user linked to an HR account carrying the given name and email.
     *
     * Name and email live in HR only, so they are taken out of the tracking
     * attributes and written to HR instead: an existing HR account with that
     * email is reused, otherwise an HR employee and account are created. Only
     * works against the test suite's in-memory HR database; the real `hr`
     * connection is READ ONLY.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): User
    {
        $name = (string) Arr::pull($attributes, 'name', fake()->name());
        $email = (string) Arr::pull($attributes, 'email', fake()->unique()->safeEmail());

        if (! isset($attributes['hr_user_id'])) {
            $account = DB::connection('hr')->table('users')->where('email', $email)->first(['id', 'employee_id'])
                ?? $this->createHrAccount($name, $email);

            $attributes['hr_user_id'] = $account->id;
            if (! array_key_exists('employee_id', $attributes)) {
                $attributes['employee_id'] = $account->employee_id;
            }
        }

        return parent::newModel($attributes);
    }

    /**
     * Set the password of the user's HR account, which is what login checks.
     */
    public function withHrAccount(string $password = 'password'): static
    {
        return $this->afterCreating(function (User $user) use ($password): void {
            DB::connection('hr')->table('users')->where('id', $user->hr_user_id)->update([
                'password' => Hash::make($password),
            ]);
        });
    }

    /**
     * Insert an HR employee and its login account, and return the account.
     */
    protected function createHrAccount(string $name, string $email): object
    {
        [$firstName, $lastName] = array_pad(explode(' ', trim($name), 2), 2, '');

        $employeeId = DB::connection('hr')->table('employees')->insertGetId([
            'first_name_en' => $firstName,
            'last_name_en' => $lastName,
            'first_name_ar' => $firstName,
            'last_name_ar' => $lastName,
            'username' => fake()->unique()->userName(),
        ]);

        $id = DB::connection('hr')->table('users')->insertGetId([
            'employee_id' => $employeeId,
            'email' => $email,
            'password' => static::$password ??= Hash::make('password'),
        ]);

        return (object) ['id' => $id, 'employee_id' => $employeeId];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Assign the user an existing (or newly-created) application role.
     */
    public function role(string $roleName): static
    {
        return $this->afterCreating(function (User $user) use ($roleName): void {
            $user->assignRole(Role::findOrCreate($roleName, 'web'));
        });
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
