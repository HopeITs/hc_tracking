<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `status`, `priority` and `progress_percentage` are left to the column
     * defaults so a new task starts as an untouched medium-priority todo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proposal_id' => Proposal::factory(),
            'title' => fake()->randomElement(['Draft the distribution plan', 'Verify beneficiary list', 'Procure water pumps', 'Run the post-distribution monitoring']),
            'description' => fake()->sentence(),
            'assigned_to' => User::factory(),
            'created_by' => User::factory(),
        ];
    }

    /**
     * Indicate that the task is finished.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TaskStatus::Done,
            'progress_percentage' => 100,
            'completed_date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
        ]);
    }
}
