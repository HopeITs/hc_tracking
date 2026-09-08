<?php

namespace Database\Factories;

use App\Models\Donor;
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
     * `currency` is left to the column default so the agreement is priced in
     * USD unless a caller says otherwise.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'donor_id' => Donor::factory(),
            'proposal_id' => Proposal::factory(),
            'budget' => fake()->randomFloat(2, 50000, 2000000),
        ];
    }
}
