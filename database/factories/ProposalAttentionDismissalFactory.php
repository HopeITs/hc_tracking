<?php

namespace Database\Factories;

use App\Models\Proposal;
use App\Models\ProposalAttentionDismissal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalAttentionDismissal>
 */
class ProposalAttentionDismissalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'proposal_id' => Proposal::factory(),
            'item_key' => 'item-'.fake()->unique()->numberBetween(1, 99999),
        ];
    }
}
