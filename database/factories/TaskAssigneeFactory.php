<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskAssignee;
use Database\Factories\Concerns\CreatesHrRecords;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskAssignee>
 */
class TaskAssigneeFactory extends Factory
{
    use CreatesHrRecords;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'employee_id' => fn (): int => $this->createHrEmployee(),
        ];
    }
}
