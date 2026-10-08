<?php

declare(strict_types=1);

namespace App\Actions\Milestones;

use App\Models\Milestone;
use App\Models\Scopes\TaskVisibilityScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Delete a milestone with no nested milestones and no tasks, holding a row
 * lock so neither can be added underneath it mid-delete.
 */
class DeleteMilestone
{
    /**
     * @return bool false when the milestone is gone or still has nested milestones or tasks
     */
    public function handle(int $milestoneId): bool
    {
        return DB::transaction(function () use ($milestoneId): bool {
            $milestone = Milestone::query()->lockForUpdate()->find($milestoneId);

            if ($milestone === null || $milestone->children()->exists() || $milestone->tasks()->withoutGlobalScope(TaskVisibilityScope::class)->exists()) {
                return false;
            }

            Gate::authorize('delete', $milestone);

            return (bool) $milestone->delete();
        });
    }
}
