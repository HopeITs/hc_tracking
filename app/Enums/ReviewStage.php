<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The internal review a proposal goes through before it reaches the donor
 * (contracts.md §9). Each stage has one review status; its reviewers never
 * count for the other stage.
 */
enum ReviewStage: string
{
    use HasTranslatedLabel;

    case ConceptNote = 'concept_note';
    case Proposal = 'proposal';

    /**
     * The status during which this stage's reviewers read and comment.
     */
    public function reviewStatus(): ProposalStatus
    {
        return match ($this) {
            self::ConceptNote => ProposalStatus::ConceptNoteReview,
            self::Proposal => ProposalStatus::ProposalReview,
        };
    }

    /**
     * The status this stage's review leads to when the proposal goes to the donor.
     */
    public function submissionStatus(): ProposalStatus
    {
        return match ($this) {
            self::ConceptNote => ProposalStatus::ConceptNoteSubmitted,
            self::Proposal => ProposalStatus::Submitted,
        };
    }

    /**
     * The stage a review status opens, or null for any other status.
     */
    public static function forReviewStatus(ProposalStatus $status): ?self
    {
        return array_find(self::cases(), fn (self $stage): bool => $stage->reviewStatus() === $status);
    }

    /**
     * The stage whose reviewers a sending move warns about (D-15), or null.
     */
    public static function forSubmission(ProposalStatus $to): ?self
    {
        return array_find(self::cases(), fn (self $stage): bool => $stage->submissionStatus() === $to);
    }

    /**
     * The stage a reviewer added now joins, derived from the proposal's
     * status: a draft or its review. Any other status takes no reviewers.
     */
    public static function forAddingIn(ProposalStatus $status): ?self
    {
        return match ($status) {
            ProposalStatus::ConceptNote, ProposalStatus::ConceptNoteReview => self::ConceptNote,
            ProposalStatus::ProposalDraft, ProposalStatus::ProposalReview => self::Proposal,
            ProposalStatus::ConceptNoteSubmitted, ProposalStatus::Submitted, ProposalStatus::OnHold, ProposalStatus::Approved,
            ProposalStatus::Active, ProposalStatus::Completed, ProposalStatus::Rejected, ProposalStatus::FinalReports => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
