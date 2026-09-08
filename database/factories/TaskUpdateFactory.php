<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskUpdate>
 */
class TaskUpdateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `date` is left to the column default so an update is stamped now.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'user_id' => User::factory(),
            'comment' => fake()->sentence(),
        ];
    }
}
