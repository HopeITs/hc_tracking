<?php

namespace Database\Factories;

use App\Models\Strategy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Strategy>
 */
class StrategyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startYear = fake()->numberBetween(2016, 2026);

        return [
            'name' => fake()->country().' Strategy',
            'start_year' => $startYear,
            'end_year' => $startYear + 4,
        ];
    }
}
