<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\User;

/**
 * Only the person an assignment was made to decides on it, and only while it
 * is pending. A rejection is final: it locks the record (see ProjectPolicy).
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
}
