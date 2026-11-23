<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Assignment;
use App\Models\ProposalLocation;

/**
 * Removes a deleted location row's mirrored owner assignments.
 *
 * `assignments.assignable_id` carries no foreign key — a polymorphic column
 * cannot — so nothing at the database level removes these rows, exactly as
 * with `media` (see MediaOwnerObserver). It did not matter while only
 * projects held assignments, because a project is archived and never deleted;
 * a location row **is** deleted (ProposalLocationRows::replace and
 * detachProject), so from Y-11 this observer is what keeps them from
 * outliving their row.
 *
 * The rows are history, not news: nothing is recorded and nobody is notified.
 */
class ProposalLocationObserver
{
    public function deleting(ProposalLocation $row): void
    {
        Assignment::query()
            ->where('assignable_type', $row->getMorphClass())
            ->where('assignable_id', $row->getKey())
            ->delete();
    }
}
