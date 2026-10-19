<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ReportingPeriod;
use App\Models\Proposal;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The window a donor report covers, inside the grant (T19).
 *
 * Fixed periods run 3, 6 or 12 calendar months from the chosen start month
 * and stop at the grant end; custom runs from the start month to the end of
 * the chosen end month. Months are calendar months (addMonthsNoOverflow).
 */
final class DonorReportPeriods
{
    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     *
     * @throws ValidationException period_outside_grant
     */
    public static function window(Proposal $proposal, ReportingPeriod $type, string $startMonth, ?string $endMonth): array
    {
        $grantStart = $proposal->actual_date_begin;
        $grantEnd = $proposal->actual_date_end;
        $start = self::month($startMonth);

        if ($grantStart === null || $grantEnd === null || $start === null
            || $start->lt($grantStart->startOfMonth()) || $start->gt($grantEnd)) {
            throw self::outsideGrant();
        }

        $months = $type->months();

        if ($months !== null) {
            $end = $start->addMonthsNoOverflow($months)->subDay();
        } else {
            $last = $endMonth === null ? null : self::month($endMonth);

            if ($last === null || $last->lt($start) || $last->gt($grantEnd)) {
                throw self::outsideGrant();
            }

            $end = $last->endOfMonth()->startOfDay();
        }

        return ['start' => $start, 'end' => $end->gt($grantEnd) ? $grantEnd->startOfDay() : $end];
    }

    /**
     * The first day of a `Y-m` month, or null when the text is not one.
     */
    private static function month(string $month): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m', $month)?->startOfMonth();
    }

    private static function outsideGrant(): ValidationException
    {
        return ValidationException::withMessages(['startMonth' => __('donor_reports.period_outside_grant')]);
    }
}
