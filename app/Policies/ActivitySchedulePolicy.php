<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Proposal;
use App\Models\User;

/**
 * Who edits a proposal's activity calendar (contracts §11.2): MEAL and the
 * proposal editor before the donor approves; MEAL alone afterwards.
 *
 * "MEAL" is the holder of `targets.update`, the logframe permission only MEAL
 * holds (D-23); `results.update` is not it, since Managers hold that too.
 */
class ActivitySchedulePolicy
{
    /**
     * Determine whether the user can change the calendar of the proposal's
     * activities.
     */
    public function update(User $user, Proposal $proposal): bool
    {
        if ($user->can('targets.update')) {
            return true;
        }

        return ! $proposal->status->isApprovedByDonor() && $user->can('update', $proposal);
    }
}
