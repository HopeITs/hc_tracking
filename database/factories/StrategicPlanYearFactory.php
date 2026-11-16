<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StrategicPlan;
use App\Models\StrategicPlanYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StrategicPlanYear>
 */
class StrategicPlanYearFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'strategic_plan_id' => StrategicPlan::factory(),
            'year' => (int) now()->year,
            'goals' => fake()->sentence(),
        ];
    }
}
