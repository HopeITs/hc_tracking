<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ScheduleDependency;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleDependency>
 */
class ScheduleDependencyFactory extends Factory
{
    /**
     * A finish-to-start link between two tasks of one project. `type` and
     * `lag_days` are left to their column defaults.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'predecessor_type' => 'task',
            'predecessor_id' => fn (array $attributes): int => Task::factory()->create(['source_type' => 'project', 'source_id' => $attributes['project_id']])->id,
            'successor_type' => 'task',
            'successor_id' => fn (array $attributes): int => Task::factory()->create(['source_type' => 'project', 'source_id' => $attributes['project_id']])->id,
        ];
    }
}
