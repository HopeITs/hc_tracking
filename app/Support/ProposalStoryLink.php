<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Proposal;
use App\Models\User;

/**
 * Where a reviewer's link opens a proposal: its own page (user decision
 * 2026-11-19, which replaced the Tracking Desk stage 4 redirect to the
 * project Story), on the Status tab, at their own review row or at the
 * Reviewers list.
 */
final class ProposalStoryLink
{
    /**
     * The id of the viewer's own row in an open review stage
     * (pages/proposals/_show-reviewers), where the note and Mark as seen are.
     */
    public const MyReview = 'my-review';

    /** The id of the Reviewers section on the proposal's Status tab. */
    public const Reviewers = 'reviewers';

    /**
     * The proposal opened on its Status tab for one of its reviewers: on
     * their own row while their review still waits on them, else on the
     * proposal. Falls back to the standalone page when the Story is closed to
     * them.
     */
    public static function review(User $viewer, Proposal $proposal, bool $waiting = true): string
    {
        $fragment = $waiting ? self::MyReview : 'proposal';

        return route('proposals.show', ['proposal' => $proposal, 'ptab' => 'status']).'#'.$fragment;
    }

    /**
     * The proposal opened on its Reviewers list, for whoever waits on other
     * people's reviews (My reviews → Asked by me).
     */
    public static function reviewers(User $viewer, Proposal $proposal): string
    {
        return route('proposals.show', ['proposal' => $proposal, 'ptab' => 'status']).'#'.self::Reviewers;
    }
}
