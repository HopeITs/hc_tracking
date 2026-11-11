<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\DonorReportType;
use App\Models\DonorReport;
use App\Models\User;

/**
 * Donor reports (contracts §14.3). The responsible person is the employee
 * named on the report (`responsible_employee_id` = the user's
 * `employee_id`, both known); they can deliver it without any money
 * permission. A submitted report is frozen.
 */
class DonorReportPolicy
{
    /**
     * Open the register of donor reports: anyone who may view one, or the
     * responsible person on at least one.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('funding-agreements.view') || $user->can('reports.aggregate') || $user->hasReportingDuties();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, DonorReport $donorReport): bool
    {
        return $user->can('funding-agreements.view') || $user->can('reports.aggregate') || $donorReport->isResponsible($user);
    }

    /**
     * Mark the report as submitted to its reader.
     */
    public function submit(User $user, DonorReport $donorReport): bool
    {
        return $donorReport->isResponsible($user) || $user->can('funding-agreements.update');
    }

    /**
     * Attach a delivered report file.
     */
    public function uploadFile(User $user, DonorReport $donorReport): bool
    {
        return $this->submit($user, $donorReport);
    }

    /**
     * Remove a delivered report file.
     */
    public function deleteFile(User $user, DonorReport $donorReport): bool
    {
        return $this->submit($user, $donorReport);
    }

    /**
     * Download a delivered report file.
     */
    public function downloadFile(User $user, DonorReport $donorReport): bool
    {
        return $donorReport->isResponsible($user) || $user->can('funding-agreements.view');
    }

    /**
     * Compose a new version from today's validated figures. Only a composed
     * report that has not gone out can be regenerated.
     */
    public function regenerate(User $user, DonorReport $donorReport): bool
    {
        if ($user->cannot('reports.aggregate') || ! $donorReport->isComposed() || $donorReport->isSubmitted()) {
            return false;
        }

        $proposal = $donorReport->donorProposal?->proposal;

        return ($proposal !== null && $user->can('view', $proposal))
            || ($donorReport->type === DonorReportType::Narrative && $donorReport->isResponsible($user));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DonorReport $donorReport): bool
    {
        return $user->can('funding-agreements.update') && ! $donorReport->isSubmitted();
    }
}
