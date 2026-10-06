<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\HrContract;
use App\Models\User;

/**
 * Only the person an assignment was made to decides on it, and only while it
 * is pending. A rejection locks the record (see ProjectPolicy) until the top
 * level unblocks it.
 */
class AssignmentPolicy
{
    /**
     * Determine whether the user may acknowledge or reject the assignment.
     */
    public function respond(User $user, Assignment $assignment): bool
    {
        return $assignment->status === AssignmentStatus::Pending && $assignment->isFor($user);
    }

    /**
     * Only the top level (the CEO) lifts a rejection's block, and only on a
     * rejected assignment.
     */
    public function unblock(User $user, Assignment $assignment): bool
    {
        return $assignment->status === AssignmentStatus::Rejected
            && $user->employee_id !== null
            && in_array((int) $user->employee_id, HrContract::topLevelEmployeeIds(), true);
    }
}
