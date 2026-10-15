<?php

namespace Database\Factories;

use App\Enums\ReviewerSlot;
use App\Enums\ReviewStage;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use Database\Factories\Concerns\CreatesHrRecords;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalReviewer>
 */
class ProposalReviewerFactory extends Factory
{
    use CreatesHrRecords;

    /**
     * An unseen project-owner reviewer of the concept-note stage. Use seen()
     * and additional() for the other common rows.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proposal_id' => Proposal::factory(),
            'stage' => ReviewStage::ConceptNote,
            'employee_id' => fn (): int => $this->createHrEmployee(),
            'slot' => ReviewerSlot::ProjectOwner,
        ];
    }

    /**
     * The reviewer has seen the proposal.
     */
    public function seen(): static
    {
        return $this->state(['seen_at' => now()]);
    }

    /**
     * Added by hand by a proposals.manage-reviewers holder.
     */
    public function additional(): static
    {
        return $this->state(['slot' => ReviewerSlot::Additional]);
    }
}
