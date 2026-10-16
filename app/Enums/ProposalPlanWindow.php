<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who may add or edit a proposal's milestones and department tasks, by the
 * proposal's status (T31). The one definition every plan action, policy and
 * page reads:
 *
 * - Planning (concept note … proposal review): plan rights, i.e.
 *   proposals.update, or milestones.create / tasks.create with sight of the
 *   proposal.
 * - Locked (submitted, on hold): the plan waits for the donor's answer.
 * - Execution (approved, active): normal execution rights,
 *   milestones.create/update and tasks.create/update.
 * - Closed (rejected, completed, the deprecated final reports): read-only.
 */
enum ProposalPlanWindow: string
{
    case Planning = 'planning';
    case Locked = 'locked';
    case Execution = 'execution';
    case Closed = 'closed';

    public static function for(ProposalStatus $status): self
    {
        return match ($status) {
            ProposalStatus::ConceptNote,
            ProposalStatus::ConceptNoteReview,
            ProposalStatus::ConceptNoteSubmitted,
            ProposalStatus::ProposalDraft,
            ProposalStatus::ProposalReview => self::Planning,
            ProposalStatus::Submitted,
            ProposalStatus::OnHold => self::Locked,
            ProposalStatus::Approved,
            ProposalStatus::Active => self::Execution,
            ProposalStatus::Completed,
            ProposalStatus::Rejected,
            ProposalStatus::FinalReports => self::Closed,
        };
    }

    /**
     * Why a plan edit is refused in a shut window; null where the refusal is
     * about rights, so the default "not authorized" message applies.
     */
    public function denialMessage(): ?string
    {
        $message = match ($this) {
            self::Locked => __('milestones.plan_window_locked'),
            self::Closed => __('milestones.plan_window_closed'),
            self::Planning, self::Execution => null,
        };

        return is_string($message) ? $message : null;
    }

    /**
     * Whether milestones and tasks can be added or edited at all.
     */
    public function isEditable(): bool
    {
        return match ($this) {
            self::Planning, self::Execution => true,
            self::Locked, self::Closed => false,
        };
    }
}
