<?php

namespace Database\Factories;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Milestone>
 */
class MilestoneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `status`, `sequence` and `weight_percentage` are left to the column
     * defaults so a new milestone starts pending, first and unweighted.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->randomElement(['Baseline assessment', 'Community mobilisation', 'Works handover', 'Endline survey']),
            'description' => fake()->sentence(),
            'planned_date' => fake()->dateTimeBetween('-1 year', '+1 year')->format('Y-m-d'),
            'responsible_user_id' => User::factory(),
        ];
    }

    /**
     * Indicate that the milestone was reached on its planned date.
     *
     * The derived column is a closure rather than a value read from
     * $attributes directly, so it is resolved after the array passed to
     * create() has been merged and stays consistent with it.
     */
    public function reached(): static
    {
        return $this->state([
            'status' => 'reached',
            'actual_date' => fn (array $attributes): string => $attributes['planned_date'],
        ]);
    }
}
