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
     * All status values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
