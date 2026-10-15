<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\MilestoneStatus;
use App\Exceptions\InvalidParentException;
use App\Models\Milestone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps the milestone tree well formed.
 *
 * Neither guard can be expressed in the schema: milestones.project_id,
 * proposal_id and parent_milestone_id are independent foreign keys, so the
 * database is happy to nest a milestone under one in another project or
 * proposal, and InnoDB will not notice a row pointing at its own descendant
 * either.
 */
final class MilestoneObserver
{
    /**
     * Reject a parent that would close a loop or cross a project or proposal
     * boundary, then place the milestone at the end of its siblings.
     *
     * @throws InvalidParentException
     */
    public function saving(Milestone $milestone): void
    {
        $parentKey = $milestone->parent_milestone_id;

        if ($parentKey !== null && $milestone->isDirty('parent_milestone_id')) {
            if ($milestone->wouldCycleUnder($parentKey)) {
                throw InvalidParentException::cycle('milestone');
            }

            $parent = Milestone::query()->find($parentKey);

            if ($parent !== null && ! $this->canNestUnder($milestone, $parent)) {
                throw InvalidParentException::crossProject();
            }
        }

        $this->assignSequence($milestone);
        $this->stampActualDates($milestone);
    }

    /**
     * A child shares its parent's proposal (none for a plain project
     * milestone) and, when the parent belongs to a project, that project too:
     * a center-specific milestone may sit under a proposal-level one, never
     * under another center's (contracts.md §12.2).
     */
    private function canNestUnder(Milestone $milestone, Milestone $parent): bool
    {
        return $this->sameKey($parent->proposal_id, $milestone->proposal_id)
            && ($parent->project_id === null || $this->sameKey($parent->project_id, $milestone->project_id));
    }

    /**
     * Compare two nullable foreign keys, whether they came from the database
     * as integers or from a form as numeric strings.
     */
    private function sameKey(mixed $first, mixed $second): bool
    {
        return $first === null || $second === null
            ? $first === $second
            : (int) $first === (int) $second;
    }

    /**
     * Places a milestone after its last sibling under the same parent within
     * the same project (for a proposal-level milestone with no project, the
     * same proposal), so nobody has to pick a sequence number by hand.
     *
     * Fires when the milestone is new, and again whenever an existing one is
     * re-nested under a different parent — the two moments its position in
     * the tree is not yet decided. It steps aside whenever the caller set
     * sequence explicitly (factories and seeders that build an exact order).
     */
    private function assignSequence(Milestone $milestone): void
    {
        $needsSequence = $milestone->exists
            ? $milestone->isDirty('parent_milestone_id') && ! $milestone->isDirty('sequence')
            : ! array_key_exists('sequence', $milestone->getAttributes());

        if (! $needsSequence) {
            return;
        }

        $lastSiblingSequence = Milestone::query()
            ->when(
                $milestone->project_id === null,
                fn (Builder $query) => $query->whereNull('project_id')->where('proposal_id', $milestone->proposal_id),
                fn (Builder $query) => $query->where('project_id', $milestone->project_id),
            )
            ->where('parent_milestone_id', $milestone->parent_milestone_id)
            ->max('sequence');

        $milestone->sequence = $lastSiblingSequence === null ? 1 : (int) $lastSiblingSequence + 1;
    }

    /**
     * actual_start_date and actual_end_date are never entered by hand — they
     * are stamped with today the moment status crosses into Ongoing or
     * Reached, and never again, so re-saving an already-ongoing milestone
     * cannot push its start date forward.
     *
     * A milestone that jumps straight to Reached without ever passing through
     * Ongoing still gets an actual_start_date, because it did start, even
     * though nobody recorded the moment separately.
     */
    private function stampActualDates(Milestone $milestone): void
    {
        if (! $milestone->isDirty('status')) {
            return;
        }

        $today = CarbonImmutable::today();

        if ($milestone->status === MilestoneStatus::Ongoing && $milestone->actual_start_date === null) {
            $milestone->actual_start_date = $today;
        }

        if ($milestone->status === MilestoneStatus::Reached) {
            if ($milestone->actual_start_date === null) {
                $milestone->actual_start_date = $today;
            }

            if ($milestone->actual_end_date === null) {
                $milestone->actual_end_date = $today;
            }
        }
    }
}
