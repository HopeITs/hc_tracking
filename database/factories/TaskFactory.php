<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\User;
use Database\Factories\Concerns\CreatesHrRecords;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    use CreatesHrRecords;

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
            'created_by' => User::factory(),
        ];
    }

    /**
     * A task always has at least one assignee in the app (the drawer
     * requires it), so every factory-made task gets one by default unless
     * the caller sets its own via taskAssignees() afterwards.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Task $task): void {
            if (! $task->taskAssignees()->exists()) {
                $task->taskAssignees()->create(['employee_id' => $this->createHrEmployee()]);
            }
        });
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

    /**
     * Indicate that the task is a subtask of the given parent.
     */
    public function subtaskOf(Task $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'parent_task_id' => $parent->id,
            'proposal_id' => $parent->proposal_id,
            'milestone_id' => $parent->milestone_id,
        ]);
    }
}
