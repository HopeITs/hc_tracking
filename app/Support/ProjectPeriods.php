<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ProjectStatus;
use App\Events\ProjectPeriodChanged;
use App\Models\Project;
use Carbon\CarbonImmutable;

/**
 * The one place a project's dates grow (contracts §3, §5, W-08).
 *
 * Used by ApproveProjectFromProposal (another proposal approved) and
 * ExtendProposal (a proposal extended). Dates are never shortened: the caller
 * passes the window it wants and the project keeps whichever side is wider.
 */
final class ProjectPeriods
{
    /**
     * Widen an approved or active project to cover $begin–$end.
     *
     * Saves the project when a date changed and raises ProjectPeriodChanged
     * only when the project is active. The caller holds the project's lock
     * and runs inside its transaction. Returns whether a date changed.
     */
    public static function widen(Project $project, CarbonImmutable $begin, CarbonImmutable $end): bool
    {
        if (! in_array($project->status, [ProjectStatus::Approved, ProjectStatus::Active], true)) {
            return false;
        }

        $oldStart = $project->start_date;
        $oldEnd = $project->end_date;

        $newStart = $oldStart === null || $begin->lt($oldStart) ? $begin : $oldStart;
        $newEnd = $oldEnd === null || $end->gt($oldEnd) ? $end : $oldEnd;

        if ($oldStart?->toDateString() === $newStart->toDateString() && $oldEnd?->toDateString() === $newEnd->toDateString()) {
            return false;
        }

        $project->start_date = $newStart;
        $project->end_date = $newEnd;
        $project->save();

        if ($project->status === ProjectStatus::Active && $oldStart !== null && $oldEnd !== null) {
            ProjectPeriodChanged::dispatch($project->id, $oldStart, $oldEnd, $newStart, $newEnd);
        }

        return true;
    }
}
