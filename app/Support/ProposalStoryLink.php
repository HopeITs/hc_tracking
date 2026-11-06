<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\User;

/**
 * Where a proposal opens: the Story of its first linked project, with the
 * proposal in the Proposal block (Tracking Desk stage 4). The one rule behind
 * RedirectProposalToStory and every link that goes there directly.
 *
 * Links go straight to the Story because wire:navigate drops the #fragment
 * whenever a redirect changes the address, so a redirected link opens the
 * Story at the top instead of on the proposal.
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
     * The Story URL, or null when the viewer may open no project the proposal
     * is linked to (the standalone proposal page serves them then). Reads the
     * proposal's loaded projects relation when there is one.
     */
    public static function story(User $viewer, Proposal $proposal, ?string $tab = null, bool $recordAgreement = false, string $fragment = 'proposal', bool $setGrantDates = false): ?string
    {
        if (! $viewer->can('projects.view')) {
            return null;
        }

        $projects = $proposal->relationLoaded('projects')
            ? $proposal->projects->sortBy('id')
            : $proposal->projects()->select(['projects.id', 'projects.slug'])->orderBy('projects.id')->get();

        $project = $projects->first(fn (Project $project): bool => $viewer->can('view', $project));

        if (! $project instanceof Project) {
            return null;
        }

        return route('projects.show', array_filter([
            'project' => $project,
            'proposal' => $proposal->id,
            'ptab' => $tab,
            'record_agreement' => $recordAgreement ? 1 : null,
            // The donor report builder's "Set the grant dates" link opens that dialog on the Story too.
            'grant_dates' => $setGrantDates ? 1 : null,
        ], fn (mixed $value): bool => $value !== null)).'#'.$fragment;
    }

    /**
     * The proposal opened on its Status tab for one of its reviewers: on
     * their own row while their review still waits on them, else on the
     * proposal. Falls back to the standalone page when the Story is closed to
     * them.
     */
    public static function review(User $viewer, Proposal $proposal, bool $waiting = true): string
    {
        $fragment = $waiting ? self::MyReview : 'proposal';

        return self::story($viewer, $proposal, 'status', fragment: $fragment)
            ?? route('proposals.show', ['proposal' => $proposal, 'ptab' => 'status']).'#'.$fragment;
    }

    /**
     * The proposal opened on its Reviewers list, for whoever waits on other
     * people's reviews (My reviews → Asked by me).
     */
    public static function reviewers(User $viewer, Proposal $proposal): string
    {
        return self::story($viewer, $proposal, 'status', fragment: self::Reviewers)
            ?? route('proposals.show', ['proposal' => $proposal, 'ptab' => 'status']).'#'.self::Reviewers;
    }

    /**
     * Whether the viewer's review of the proposal still waits on them
     * (ProposalReviewer::awaitingReview).
     */
    public static function awaitsReviewBy(User $viewer, Proposal $proposal): bool
    {
        return $viewer->employee_id !== null
            && ProposalReviewer::query()
                ->forEmployee((int) $viewer->employee_id)
                ->where('proposal_id', $proposal->id)
                ->awaitingReview()
                ->exists();
    }
}
