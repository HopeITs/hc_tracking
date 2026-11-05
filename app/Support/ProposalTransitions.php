<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ProposalStatus;

/**
 * The proposal transition table of contracts.md §2, and nothing else.
 *
 * Only the moves TransitionProposal may make are listed with their
 * requirement keys. `→ approved` belongs to ApproveProposal, `approved →
 * active` to ActivateProject and every move out of a terminal status to
 * CorrectProposalStatus, so none of them appear in map().
 *
 * A hold is not a move (D-58, W-06): the proposal keeps its status and
 * carries `on_hold_since`, so no row leads in or out of `on hold`.
 */
final class ProposalTransitions
{
    /**
     * from => [to => requirement keys].
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array Map = [
        'concept note' => [
            'concept note review' => ['linked_project', 'donor', 'planned_dates'],
            'proposal draft' => ['reason'],
        ],
        'concept note review' => [
            'concept note' => ['reason'],
            'concept note submitted' => ['effective_date'],
        ],
        'concept note submitted' => [
            'proposal draft' => ['effective_date'],
            'rejected' => ['reason_min_10', 'effective_date'],
        ],
        'proposal draft' => [
            'proposal review' => ['budget', 'planned_dates', 'target_beneficiaries'],
        ],
        'proposal review' => [
            'proposal draft' => ['reason'],
            'submitted' => ['effective_date', 'target_beneficiaries'],
        ],
        'submitted' => [
            'proposal draft' => ['reason'],
            'rejected' => ['reason_min_10', 'effective_date'],
        ],
        'active' => [
            'completed' => ['final_report', 'effective_date'],
        ],
    ];

    /**
     * The TransitionProposal table, keyed by status value.
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function map(): array
    {
        return self::Map;
    }

    /**
     * The statuses TransitionProposal may move a proposal to.
     *
     * @return list<ProposalStatus>
     */
    public static function targets(ProposalStatus $from): array
    {
        return array_map(
            fn (string $to): ProposalStatus => ProposalStatus::from($to),
            array_keys(self::Map[$from->value] ?? []),
        );
    }

    /**
     * The requirement keys of one move, or null when TransitionProposal may
     * not make it.
     *
     * @return list<string>|null
     */
    public static function requirements(ProposalStatus $from, ProposalStatus $to): ?array
    {
        return self::Map[$from->value][$to->value] ?? null;
    }

    /**
     * The statuses CorrectProposalStatus may set: from rejected or completed
     * to any other live status except approved.
     *
     * @return list<ProposalStatus>
     */
    public static function correctionTargets(ProposalStatus $from): array
    {
        if (! $from->isTerminal()) {
            return [];
        }

        return array_values(array_filter(
            ProposalStatus::live(),
            fn (ProposalStatus $to): bool => $to !== $from && $to !== ProposalStatus::Approved,
        ));
    }
}
