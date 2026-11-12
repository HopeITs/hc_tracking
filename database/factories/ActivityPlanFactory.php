<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActivityPlanStatus;
use App\Models\ActivityPlan;
use App\Models\Program;
use App\Models\Proposal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityPlan>
 */
class ActivityPlanFactory extends Factory
{
    /**
     * A draft plan of a new program in a new proposal.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proposal_id' => Proposal::factory(),
            'program_id' => Program::factory(),
        ];
    }

    public function inReview(): static
    {
        return $this->state([
            'status' => ActivityPlanStatus::InReview->value,
            'step' => 3,
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state([
            'status' => ActivityPlanStatus::Approved->value,
            'step' => 3,
            'submitted_at' => now()->subDay(),
            'approved_at' => now(),
        ]);
    }
}
