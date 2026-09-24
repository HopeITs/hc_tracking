<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `is_active` is left to the column default so a new account is usable.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $currency = fake()->randomElement(Currency::values());

        return [
            'account_name' => 'Operations '.$currency,
            'bank_name' => fake()->company(),
            'currency' => $currency,
            'account_number' => fake()->numerify('##########'),
            'country' => 'Syria',
            'opened_at' => fake()->dateTimeBetween('-8 years', '-1 year')->format('Y-m-d'),
        ];
    }

    /**
     * Indicate that the account has been closed.
     *
     * `closed_at` and `is_active` overlap, so this state sets both rather than
     * leaving the two to disagree.
     *
     * The derived column is a closure rather than a value read from
     * $attributes directly, so it is resolved after the array passed to
     * create() has been merged and stays consistent with it.
     */
    public function closed(): static
    {
        return $this->state([
            'closed_at' => fn (array $attributes): string => fake()
                ->dateTimeBetween($attributes['opened_at'], 'now')
                ->format('Y-m-d'),
            'is_active' => false,
        ]);
    }
}
