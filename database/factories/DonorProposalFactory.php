<?php

namespace Database\Factories;

use App\Models\DonorProposal;
use App\Models\Proposal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonorProposal>
 */
class DonorProposalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The donor is the proposal's: `proposal_id` is listed first so it is
     * resolved before `donor_id` reads it. A caller passing only a donor gets a
     * proposal funded by that donor. A caller passing both with a mismatch
     * fails at the composite foreign key, on purpose.
     *
     * `currency` is left to the column default so the agreement is priced in
     * USD unless a caller says otherwise.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proposal_id' => fn (array $attributes): int => Proposal::factory()
                ->create(is_numeric($attributes['donor_id'] ?? null) ? ['donor_id' => $attributes['donor_id']] : [])->id,
            'donor_id' => fn (array $attributes): int => Proposal::query()->findOrFail($attributes['proposal_id'])->donor_id,
            'budget' => fake()->randomFloat(2, 50000, 2000000),
        ];
    }
}
