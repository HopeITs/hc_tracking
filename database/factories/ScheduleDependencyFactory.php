<?php

namespace Database\Factories;

use App\Models\ProposalLocation;
use App\Models\ScheduleDependency;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleDependency>
 */
class ScheduleDependencyFactory extends Factory
{
    /**
     * A finish-to-start link between two tasks of one place (Y-12, D-93).
     * `type` and `lag_days` are left to their column defaults.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proposal_location_id' => ProposalLocation::factory(),
            'predecessor_type' => 'task',
            'predecessor_id' => fn (array $attributes): int => $this->taskAt($attributes['proposal_location_id']),
            'successor_type' => 'task',
            'successor_id' => fn (array $attributes): int => $this->taskAt($attributes['proposal_location_id']),
        ];
    }

    /**
     * A delivery task sourced from the place the link belongs to.
     */
    private function taskAt(mixed $rowId): int
    {
        return Task::factory()->create([
            'source_type' => 'proposal_location',
            'source_id' => $rowId,
        ])->id;
    }
}
