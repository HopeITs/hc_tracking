<?php

namespace Database\Factories;

use App\Enums\FileType;
use App\Models\Task;
use App\Models\TaskAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskAttachment>
 */
class TaskAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `date` is left to the column default so an upload is dated today.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(FileType::cases());

        return [
            'task_id' => Task::factory(),
            'path' => 'task-attachments/'.fake()->uuid().'.'.$type->value,
            'type' => $type,
        ];
    }
}
