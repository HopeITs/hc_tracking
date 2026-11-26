<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Activity;
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
 * It also records a new row's birth (Y-17): a place is an entry on the
 * Projects screen, so it comes into being as `project.created` with the row
 * as subject. That notification is the one its responsible answers with
 * acknowledge or reject (`QuickActions`), and it is the event a hand-made
 * project used to record — nothing creates one now (§8 drops
 * `projects.create`), so the key is the row's alone and §8.5 keeps its name.
 * A deleted row is still history: nothing is recorded for it.
 */
class ProposalLocationObserver
{
    public function created(ProposalLocation $row): void
    {
        if (Activity::isRecording()) {
            $row->recordActivity('project.created');
        }
    }

    public function deleting(ProposalLocation $row): void
    {
        Assignment::query()
            ->where('assignable_type', $row->getMorphClass())
            ->where('assignable_id', $row->getKey())
            ->delete();
    }
}
