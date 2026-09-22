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
    case ProposalDraft = 'proposal draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Active = 'active';
    case FinalReports = 'final reports';
    case Completed = 'completed';

    /**
     * The phase of work this status places the proposal in.
     *
     * The match deliberately has no default arm: a status added without a
     * phase raises UnhandledMatchError rather than silently taking a wrong one.
     */
    public function phase(): ProposalPhase
    {
        return match ($this) {
            self::ConceptNote, self::ProposalDraft => ProposalPhase::Planning,
            self::Submitted, self::Approved, self::Rejected => ProposalPhase::Implementation,
            self::Active => ProposalPhase::Monitoring,
            self::FinalReports => ProposalPhase::Closure,
            self::Completed => ProposalPhase::Archived,
        };
    }

    /**
     * The stage a proposal normally moves on to, or null when there is none.
     *
     * Rejected is a dead end and Completed ends the lifecycle. Submitted
     * advances to Approved; a rejection is still recorded through the full
     * status change, never proposed as the "next" step.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::ConceptNote => self::ProposalDraft,
            self::ProposalDraft => self::Submitted,
            self::Submitted => self::Approved,
            self::Approved => self::Active,
            self::Active => self::FinalReports,
            self::FinalReports => self::Completed,
            self::Rejected, self::Completed => null,
        };
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
     * The lifecycle in order, without the Rejected branch.
     *
     * @return list<self>
     */
    public static function lifecycle(): array
    {
        return [
            self::ConceptNote,
            self::ProposalDraft,
            self::Submitted,
            self::Approved,
            self::Active,
            self::FinalReports,
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
