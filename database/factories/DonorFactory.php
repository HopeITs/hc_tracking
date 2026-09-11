<?php

namespace Database\Factories;

use App\Enums\DonorType;
use App\Models\Donor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Donor>
 */
class DonorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The profile columns are nullable with NO database default, so setting
     * them here undermines nothing the schema tests assert — unlike a
     * defaulted column, there is no default to exercise.
     *
     * `type` is the exception and is left null: a funder recorded from a grant
     * letter alone genuinely has no classification yet, and that state must
     * stay reachable. Use ofType() when a classification matters.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'contact_person' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('+### ## ### ####'),
            'country' => fake()->country(),
            'notes' => fake()->sentence(),
        ];
    }

    /**
     * Classify this donor.
     */
    public function ofType(DonorType $type): static
    {
        return $this->state(['type' => $type]);
    }

    /**
     * Indicate a funder known only by name, with no profile recorded.
     */
    public function unprofiled(): static
    {
        return $this->state([
            'type' => null,
            'contact_person' => null,
            'email' => null,
            'phone' => null,
            'country' => null,
            'notes' => null,
        ]);
    }
}
