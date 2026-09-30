<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\User;

/**
 * A report belongs to its author, and approving it takes a second person.
 *
 * Only the author changes, submits or deletes their own draft (and may pull a
 * report still under review back to draft). Holders of reports.approve
 * approve or return someone else's report — never their own — and holders of
 * reports.delete may remove anyone's draft. Approved reports are part of the
 * record and are never deleted.
 */
class ReportPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('reports.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Report $report): bool
    {
        return $user->can('reports.view');
    }

    /**
     * Determine whether the user can write a new report.
     */
    public function create(User $user): bool
    {
        return $user->can('reports.create');
    }

    /**
     * Determine whether the user can change a draft's content and files:
     * its author, while they may still write reports.
     */
    public function update(User $user, Report $report): bool
    {
        return $report->isEditable() && $this->isAuthor($user, $report) && $user->can('reports.update');
    }

    /**
     * Determine whether the user can delete the draft: its author, or a
     * holder of reports.delete.
     */
    public function delete(User $user, Report $report): bool
    {
        return $report->isEditable() && ($this->update($user, $report) || $user->can('reports.delete'));
    }

    /**
     * Determine whether the user can submit the draft for review.
     */
    public function submit(User $user, Report $report): bool
    {
        return $report->status === ReportStatus::Draft && $this->update($user, $report);
    }

    /**
     * Determine whether the user can approve the report: a second person.
     */
    public function approve(User $user, Report $report): bool
    {
        return $report->status === ReportStatus::Reviewed
            && $user->can('reports.approve')
            && ! $this->isAuthor($user, $report);
    }

    /**
     * Determine whether the user can send the report back to draft: an
     * approver (never on their own report), or the author pulling back a
     * report that is still under review.
     */
    public function returnToDraft(User $user, Report $report): bool
    {
        $approverReturns = in_array($report->status, [ReportStatus::Reviewed, ReportStatus::Approved], true)
            && $user->can('reports.approve')
            && ! $this->isAuthor($user, $report);

        $authorPullsBack = $report->status === ReportStatus::Reviewed
            && $this->isAuthor($user, $report)
            && $user->can('reports.update');

        return $approverReturns || $authorPullsBack;
    }

    /**
     * Determine whether the user can acknowledge the report for MEAL.
     */
    public function markSeen(User $user, Report $report): bool
    {
        return $user->can('reports.mark-seen');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Report $report): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Report $report): bool
    {
        return false;
    }

    /**
     * Whether the user wrote this report.
     */
    private function isAuthor(User $user, Report $report): bool
    {
        return $report->user_id === $user->id;
    }
}
