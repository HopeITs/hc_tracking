<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ActivityPlan;
use App\Models\ActivityPlanMilestone;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityPlanMilestone>
 */
class ActivityPlanMilestoneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_plan_id' => ActivityPlan::factory(),
            'created_by' => User::factory(),
            'title' => fake()->sentence(4),
            'due_date' => now()->addMonth()->toDateString(),
            'measure' => 'checkpoint',
        ];
    }
}
