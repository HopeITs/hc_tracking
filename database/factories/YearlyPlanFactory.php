<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\User;
use App\Models\YearlyPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<YearlyPlan>
 */
class YearlyPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
            'manager_id' => User::factory(),
            'year' => fake()->numberBetween(2021, 2030),
        ];
    }
}
