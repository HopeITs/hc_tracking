<?php

namespace Database\Factories;

use App\Enums\ProposalStatus;
use App\Models\ProposalPlanShift;
use App\Models\ProposalStatusNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalPlanShift>
 */
class ProposalPlanShiftFactory extends Factory
{
    /**
     * Define the model's default state: a shift answering a fresh approval
     * note of the same proposal.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'approval_note_id' => ProposalStatusNote::factory()->state(['status' => ProposalStatus::Approved]),
            'proposal_id' => fn (array $attributes): int => (int) ProposalStatusNote::query()->whereKey($attributes['approval_note_id'])->value('proposal_id'),
            'delta_days' => fake()->numberBetween(-30, 90),
            'shifted' => fake()->numberBetween(0, 10),
        ];
    }
}
