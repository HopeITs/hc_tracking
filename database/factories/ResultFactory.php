<?php

namespace Database\Factories;

use App\Enums\ResultType;
use App\Models\Result;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Result>
 */
class ResultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `checkout_period` is left to the column default, and the result is global
     * until a caller attaches it to a program.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'statement' => fake()->randomElement([
                'Improved access to safe drinking water.',
                'Increased household resilience to shocks.',
                'Strengthened community health referral pathways.',
            ]),
            'type' => fake()->randomElement(ResultType::cases()),
        ];
    }

    /**
     * Nest the result under an existing one.
     */
    public function childOf(Result $parent): static
    {
        return $this->state([
            'parent_id' => $parent->id,
        ]);
    }

    /**
     * Indicate the logframe level this result sits at.
     */
    public function type(ResultType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
        ]);
    }
}
