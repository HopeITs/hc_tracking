<?php

namespace Database\Factories;

use App\Models\ProposalChangeRequest;
use App\Models\ProposalChangeRequestReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalChangeRequestReview>
 */
class ProposalChangeRequestReviewFactory extends Factory
{
    /**
     * A waiting Finance Manager review.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proposal_change_request_id' => ProposalChangeRequest::factory(),
            'slot' => 'finance_manager',
        ];
    }
}
