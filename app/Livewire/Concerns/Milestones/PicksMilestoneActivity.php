<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Milestones;

use App\Actions\Milestones\LinkMilestoneToActivity;
use App\Models\Milestone;
use App\Models\Result;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The "Activity in framework" field every milestone form shares: the
 * activities a milestone of a project may deliver, and the link written
 * through LinkMilestoneToActivity with its refusal shown on the form field.
 *
 * Used by milestones/show (edit and related forms), milestones/index and
 * calendar/index. Options come from Milestone::allowedActivitiesFor(), the
 * same rule the action enforces.
 */
trait PicksMilestoneActivity
{
    /**
     * Activities for the picker, grouped by framework in the view. A stored
     * link that no longer fits is kept as an option, so editing other fields
     * never drops it silently.
     *
     * @return Collection<int, Result>
     */
    protected function activityOptionsFor(?int $projectId, ?int $proposalId = null, ?int $currentActivityId = null): Collection
    {
        if ($projectId === null) {
            return new Collection;
        }

        return Result::query()
            ->select(['id', 'statement', 'proposal_id', 'program_id'])
            ->with('proposal:id,name')
            ->where(fn ($query) => $query
                ->whereIn('id', Milestone::allowedActivitiesFor($projectId, $proposalId)->select('id'))
                ->when($currentActivityId !== null, fn ($query) => $query->orWhere('id', $currentActivityId)))
            ->orderByRaw('proposal_id is not null')
            ->orderBy('proposal_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * Write the link when it changed; the action's refusal lands on $field.
     *
     * @throws ValidationException
     */
    protected function linkActivity(Milestone $milestone, string $activityId, string $field): void
    {
        $chosenId = $activityId === '' ? null : (int) $activityId;

        if ($milestone->result_id === $chosenId) {
            return;
        }

        try {
            app(LinkMilestoneToActivity::class)->handle($milestone, $chosenId);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([$field => $exception->errors()['activity'] ?? [__('milestones.milestone_activity')]]);
        }
    }
}
