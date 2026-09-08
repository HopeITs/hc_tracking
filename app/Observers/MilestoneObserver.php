<?php

declare(strict_types=1);

namespace App\Observers;

use App\Exceptions\InvalidParentException;
use App\Models\Milestone;

/**
 * Keeps the milestone tree well formed.
 *
 * Neither guard can be expressed in the schema: milestones.project_id and
 * parent_milestone_id are independent foreign keys, so the database is happy to
 * nest a milestone under one in another project, and InnoDB will not notice a
 * row pointing at its own descendant either.
 */
final class MilestoneObserver
{
    /**
     * Reject a parent that would close a loop or cross a project boundary.
     *
     * @throws InvalidParentException
     */
    public function saving(Milestone $milestone): void
    {
        $parentKey = $milestone->parent_milestone_id;

        if ($parentKey === null || ! $milestone->isDirty('parent_milestone_id')) {
            return;
        }

        if ($milestone->wouldCycleUnder($parentKey)) {
            throw InvalidParentException::cycle('milestone');
        }

        $parent = Milestone::query()->find($parentKey);

        if ($parent !== null && $parent->project_id !== $milestone->project_id) {
            throw InvalidParentException::crossProject();
        }
    }
}
