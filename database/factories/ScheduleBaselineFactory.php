<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ScheduleBaseline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleBaseline>
 */
class ScheduleBaselineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->randomElement(['Approved plan', 'Inception baseline', 'Revised plan']),
        ];
    }
}
