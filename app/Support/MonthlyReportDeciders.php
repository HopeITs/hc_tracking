<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MonthlyReport;
use App\Models\User;

/**
 * Who may decide a reopen request of a report (D-09a), as tracking user ids:
 * the MEAL Manager slot holder; while the slot is vacant, the active Public
 * Relations Managers. Always reports.reopen holders, never the requester or
 * the preparer. Empty → the request stays pending and is escalated to the
 * General Manager. Same rule as MonthlyReportPolicy::decideReopen().
 */
final class MonthlyReportDeciders
{
    /**
     * @return list<int>
     */
    public static function userIdsFor(MonthlyReport $report, int $requesterId): array
    {
        $slotHolder = MealCoverage::mealManagerEmployeeId();

        $candidates = $slotHolder !== null
            ? User::query()->where('employee_id', $slotHolder)->get()
            : User::role('Public Relations Manager')->whereNotNull('employee_id')->get();

        if ($slotHolder === null) {
            $active = MealCoverage::activeEmployeeIds(array_values($candidates->pluck('employee_id')->map(fn (mixed $id): int => (int) $id)->all()));
            $candidates = $candidates->filter(fn (User $user): bool => in_array($user->employee_id, $active, true));
        }

        return array_values($candidates
            ->reject(fn (User $user): bool => in_array($user->id, [$requesterId, $report->prepared_by], true))
            ->filter(fn (User $user): bool => $user->can('reports.reopen'))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }
}
