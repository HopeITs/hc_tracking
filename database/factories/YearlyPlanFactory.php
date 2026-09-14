<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\YearlyPlan;
use Database\Factories\Concerns\CreatesHrRecords;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<YearlyPlan>
 */
class YearlyPlanFactory extends Factory
{
    use CreatesHrRecords;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
            'manager_id' => fn (): int => $this->createHrEmployee(),
            'year' => fake()->numberBetween(2021, 2030),
        ];
    }
}
