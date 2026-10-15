<?php

declare(strict_types=1);

namespace App\Actions\Milestones;

use App\Models\Milestone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Link a milestone to the framework activity it delivers, or clear the link
 * (contracts §11.2). The one writer of `milestones.result_id`.
 *
 * The activity must be one Milestone::allowedActivities() offers: an activity
 * of a proposal funding the milestone's project or of the project's program
 * framework; a proposal-level milestone only its own proposal's activities.
 * The activity row is locked until commit, so one deleted meanwhile is
 * refused rather than failing the foreign key.
 *
 * Error key: activity.
 */
final class LinkMilestoneToActivity
{
    /**
     * @throws ValidationException
     */
    public function handle(Milestone $milestone, ?int $activityId): Milestone
    {
        Gate::authorize('update', $milestone);

        return DB::transaction(function () use ($milestone, $activityId): Milestone {
            $locked = Milestone::query()->whereKey($milestone->getKey())->lockForUpdate()->firstOrFail();

            if ($activityId !== null && ! $locked->allowedActivities()->whereKey($activityId)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['activity' => __('milestones.milestone_activity')]);
            }

            if ($locked->result_id !== $activityId) {
                $locked->forceFill(['result_id' => $activityId])->save();
            }

            return $locked;
        });
    }
}
