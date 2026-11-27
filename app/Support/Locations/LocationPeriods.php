<?php

declare(strict_types=1);

namespace App\Support\Locations;

use App\Enums\ProjectStatus;
use App\Events\LocationPeriodChanged;
use App\Models\ProposalLocation;
use Carbon\CarbonImmutable;

/**
 * The one place a location row's dates grow (contracts.md §3, §5, Y-17).
 *
 * The twin of the former App\Support\ProjectPeriods, moved onto the row.
 * Used by ApproveLocationFromProposal (the row's proposal was approved
 * again, or its grant moved) and ExtendProposal (the grant was extended).
 * Dates are never shortened: the caller passes the window it wants and the
 * row keeps whichever side is wider.
 */
final class LocationPeriods
{
    /**
     * Widen an approved or active row to cover $begin–$end.
     *
     * Saves the row when a date changed and raises LocationPeriodChanged
     * only when the row is active. The caller holds the row's lock and runs
     * inside its transaction. Returns whether a date changed.
     */
    public static function widen(ProposalLocation $row, CarbonImmutable $begin, CarbonImmutable $end): bool
    {
        if (! in_array($row->status, [ProjectStatus::Approved, ProjectStatus::Active], true)) {
            return false;
        }

        $oldStart = $row->start_date;
        $oldEnd = $row->end_date;

        $newStart = $oldStart === null || $begin->lt($oldStart) ? $begin : $oldStart;
        $newEnd = $oldEnd === null || $end->gt($oldEnd) ? $end : $oldEnd;

        if ($oldStart?->toDateString() === $newStart->toDateString() && $oldEnd?->toDateString() === $newEnd->toDateString()) {
            return false;
        }

        $row->start_date = $newStart;
        $row->end_date = $newEnd;
        $row->save();

        if ($row->status === ProjectStatus::Active && $oldStart !== null && $oldEnd !== null) {
            LocationPeriodChanged::dispatch($row, $oldStart, $oldEnd, $newStart, $newEnd);
        }

        return true;
    }
}
