<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Indicator;
use App\Models\StrategicPlanTarget;
use App\Models\StrategicPlanYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StrategicPlanTarget>
 */
class StrategicPlanTargetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'strategic_plan_year_id' => StrategicPlanYear::factory(),
            'indicator_id' => Indicator::factory(),
            'target' => fake()->numberBetween(10, 500),
        ];
    }
}
