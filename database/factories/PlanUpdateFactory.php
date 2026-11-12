<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ActivityPlan;
use App\Models\PlanUpdate;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanUpdate>
 */
class PlanUpdateFactory extends Factory
{
    /**
     * A draft update of an approved plan for a new centre.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_plan_id' => ActivityPlan::factory()->approved(),
            'project_id' => Project::factory(),
            'month' => '2027-01-01',
        ];
    }
}
