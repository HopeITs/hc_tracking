<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Project;
use App\Support\Locations\LocationPeriods;
use App\Support\Locations\RowsOfProject;
use Carbon\CarbonImmutable;

/**
 * The one place a project's dates grew (contracts §3, §5, W-08).
 *
 * Y-17 slice 1: the row is the writer, so this widens each row the project
 * carries through LocationPeriods and writes nothing on the project. It no
 * longer dispatches ProjectPeriodChanged — LocationPeriodChanged replaces it
 * (§5).
 *
 * @deprecated Y-17 slice 3 removes it. Call LocationPeriods::widen() on the row.
 */
final class ProjectPeriods
{
    /**
     * Widen the project's approved or active rows to cover $begin–$end.
     * Returns whether any row's dates changed.
     */
    public static function widen(Project $project, CarbonImmutable $begin, CarbonImmutable $end): bool
    {
        $changed = false;

        foreach (RowsOfProject::of($project) as $row) {
            $changed = LocationPeriods::widen($row, $begin, $end) || $changed;
        }

        return $changed;
    }
}
