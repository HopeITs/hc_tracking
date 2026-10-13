<?php

namespace Database\Factories;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalStatusNote>
 */
class ProposalStatusNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `date` is left to the column default so a note is stamped today.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proposal_id' => Proposal::factory(),
            'status' => fake()->randomElement(ProposalStatus::live()),
            'context' => fake()->sentence(),
        ];
    }
}
