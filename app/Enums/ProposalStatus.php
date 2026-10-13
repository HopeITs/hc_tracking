<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The states a proposal moves through, from first concept to archive.
 *
 * The value is what lands in `proposals.status` and in the
 * `proposal_status_notes.status` audit trail, both VARCHAR(50). Values are
 * lower case and casing is part of the value; display capitalisation belongs
 * to the UI, not the database.
 */
enum ProposalStatus: string
{
    use HasTranslatedLabel;

    case ConceptNote = 'concept note';
    case ConceptNoteReview = 'concept note review';
    case ConceptNoteSubmitted = 'concept note submitted';
    case ProposalDraft = 'proposal draft';
    case ProposalReview = 'proposal review';
    case Submitted = 'submitted';
    case OnHold = 'on hold';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Active = 'active';

    /**
     * @deprecated Retired by D-02: the final report is a milestone now. Kept
     *             only so old rows and notes still render; no transition
     *             leads in or out, and T04 moved its proposals to active.
     */
    case FinalReports = 'final reports';
    case Completed = 'completed';

    /**
     * Every status a proposal can hold today, in lifecycle order.
     *
     * Pickers, filters and charts iterate this, never cases(), so the retired
     * FinalReports never reappears as a choice.
     *
     * @return list<self>
     */
    public static function live(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status !== self::FinalReports));
    }

    /**
     * The phase of work this status places the proposal in.
     *
     * The match deliberately has no default arm: a status added without a
     * phase raises UnhandledMatchError rather than silently taking a wrong one.
     */
    public function phase(): ProposalPhase
    {
        return match ($this) {
            self::ConceptNote, self::ConceptNoteReview, self::ConceptNoteSubmitted => ProposalPhase::Ideation,
            self::ProposalDraft, self::ProposalReview, self::Submitted, self::OnHold => ProposalPhase::Planning,
            self::Approved => ProposalPhase::Implementation,
            self::Active => ProposalPhase::Monitoring,
            self::FinalReports => ProposalPhase::Closure,
            self::Completed, self::Rejected => ProposalPhase::Archived,
        };
    }

    /**
     * The stage of the proposal's paperwork: concept_note, proposal or grant.
     *
     * On hold and rejected keep the stage of the status they left, so the
     * caller passes that status as $before (see Proposal::stage()). Without
     * one they fall back to the proposal stage.
     *
     * @return 'concept_note'|'proposal'|'grant'
     */
    public function stage(?self $before = null): string
    {
        return match ($this) {
            self::ConceptNote, self::ConceptNoteReview, self::ConceptNoteSubmitted => 'concept_note',
            self::ProposalDraft, self::ProposalReview, self::Submitted => 'proposal',
            self::Approved, self::Active, self::FinalReports, self::Completed => 'grant',
            self::OnHold, self::Rejected => $before === null || in_array($before, [self::OnHold, self::Rejected], true)
                ? 'proposal'
                : $before->stage(),
        };
    }

    /**
     * The status a proposal normally moves on to, or null when there is none.
     *
     * On hold has no single next step (it depends on the pre-hold status);
     * rejected and completed end the lifecycle, and the retired final reports
     * leads nowhere. ProposalTransitions holds every allowed move.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::ConceptNote => self::ConceptNoteReview,
            self::ConceptNoteReview => self::ConceptNoteSubmitted,
            self::ConceptNoteSubmitted => self::ProposalDraft,
            self::ProposalDraft => self::ProposalReview,
            self::ProposalReview => self::Submitted,
            self::Submitted => self::Approved,
            self::Approved => self::Active,
            self::Active => self::Completed,
            self::OnHold, self::Rejected, self::FinalReports, self::Completed => null,
        };
    }

    /**
     * Whether the proposal ended: rejected or completed. Only a correction
     * moves it again.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Completed], true);
    }

    /**
     * Whether the donor has said yes, so money signed against the proposal is
     * expected. An agreement filed earlier or after a rejection is allowed but
     * warned about.
     */
    public function isApprovedByDonor(): bool
    {
        return in_array($this, [self::Approved, self::Active, self::FinalReports, self::Completed], true);
    }

    /**
     * The main line in order, without the on hold and rejected branches.
     *
     * @return list<self>
     */
    public static function lifecycle(): array
    {
        return [
            self::ConceptNote,
            self::ConceptNoteReview,
            self::ConceptNoteSubmitted,
            self::ProposalDraft,
            self::ProposalReview,
            self::Submitted,
            self::Approved,
            self::Active,
            self::Completed,
        ];
    }

    /**
     * All status values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
