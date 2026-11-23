<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Project;
use App\Support\Reporting\ReportPlace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The official holidays HR records (`hchr_holidays`) that apply to a project,
 * as a "is this day a holiday?" test for WorkingCalendar::withHolidays().
 *
 * Current HR holidays cover the nation or a program/governorate pair.
 * Personal birthday windows do not close the reporting calendar. Older HR
 * schemas remain supported: a holiday row is attached to one HR record (`holidayable_type` /
 * `holidayable_id`). It applies to a project when it is attached to the
 * project's center, city or program; the type is matched on its class name,
 * so `App\Models\Center` and `Center` both count. A recurring holiday comes
 * back every year on the same month and day range.
 *
 * HR is only read. The table is read once per request.
 */
final class HrHolidays
{
    /**
     * The test for the project's holidays.
     *
     * @return Closure(CarbonInterface): bool
     */
    public static function forProject(Project $project): Closure
    {
        return self::forTargets([
            'center' => $project->center_id,
            'city' => $project->city_id,
            'program' => $project->program_id,
        ]);
    }

    /**
     * The test for the holidays of a place (Y-03): its center (none for a
     * city-wide or unset place), city and program.
     *
     * @return Closure(CarbonInterface): bool
     */
    public static function forPlace(ReportPlace $place): Closure
    {
        return self::forTargets([
            'center' => $place->centerId,
            'city' => $place->cityId,
            'program' => $place->programId,
        ]);
    }

    /**
     * @param  array<string, int|string|null>  $targets  HR record type => id
     * @return Closure(CarbonInterface): bool
     */
    private static function forTargets(array $targets): Closure
    {
        if (once(fn (): bool => Schema::connection('hr')->hasColumn('holidays', 'scope'))) {
            $holidays = array_values(array_filter(self::scopedHolidays(), fn (array $holiday): bool => $holiday['scope'] === 'national'
                || ($holiday['scope'] === 'projects'
                    && ($targets['program'] ?? null) !== null && ($targets['city'] ?? null) !== null
                    && $holiday['program_id'] === (int) $targets['program']
                    && $holiday['city_id'] === (int) $targets['city'])));

            return fn (CarbonInterface $day): bool => self::falls($day, $holidays);
        }

        $targets = array_filter($targets, fn (mixed $id): bool => $id !== null);

        $holidays = array_values(array_filter(
            self::all(),
            fn (array $holiday): bool => ($targets[$holiday['type']] ?? null) !== null && (int) $targets[$holiday['type']] === $holiday['id'],
        ));

        return fn (CarbonInterface $day): bool => self::falls($day, $holidays);
    }

    /**
     * Whether the day is inside one of the holidays.
     *
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, recurring: bool}>  $holidays
     */
    private static function falls(CarbonInterface $day, array $holidays): bool
    {
        $date = CarbonImmutable::parse($day)->startOfDay();

        foreach ($holidays as $holiday) {
            if (! $holiday['recurring']) {
                if ($date->betweenIncluded($holiday['start'], $holiday['end'])) {
                    return true;
                }

                continue;
            }

            // A recurring holiday: the same month and day range in the day's year.
            $start = $holiday['start']->setYear($date->year);
            $end = $holiday['end']->setYear($date->year);

            if ($end->lessThan($start)) {
                $end = $end->addYear();
            }

            if ($date->betweenIncluded($start, $end) || $date->betweenIncluded($start->subYear(), $end->subYear())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Current HR holidays, read once per request without changing HR data.
     *
     * @return list<array{scope: string, program_id: int|null, city_id: int|null, start: CarbonImmutable, end: CarbonImmutable, recurring: bool}>
     */
    private static function scopedHolidays(): array
    {
        return once(fn (): array => array_values(DB::connection('hr')->table('holidays')
            ->leftJoin('holiday_project', 'holidays.id', '=', 'holiday_project.holiday_id')
            ->where('holidays.kind', '!=', 'birthday')
            ->get(['holidays.scope', 'holiday_project.program_id', 'holiday_project.city_id', 'holidays.start_date', 'holidays.end_date', 'holidays.is_recurring'])
            ->map(fn (object $row): array => [
                'scope' => (string) $row->scope,
                'program_id' => $row->program_id === null ? null : (int) $row->program_id,
                'city_id' => $row->city_id === null ? null : (int) $row->city_id,
                'start' => CarbonImmutable::parse($row->start_date)->startOfDay(),
                'end' => CarbonImmutable::parse($row->end_date)->startOfDay(),
                'recurring' => (bool) $row->is_recurring,
            ])->all()));
    }

    /**
     * Every HR holiday, reduced to what the test needs.
     *
     * @return list<array{type: string, id: int, start: CarbonImmutable, end: CarbonImmutable, recurring: bool}>
     */
    private static function all(): array
    {
        return once(fn (): array => array_values(DB::connection('hr')->table('holidays')
            ->get(['holidayable_type', 'holidayable_id', 'start_date', 'end_date', 'is_recurring'])
            ->map(fn (object $row): array => [
                'type' => mb_strtolower(class_basename((string) $row->holidayable_type)),
                'id' => (int) $row->holidayable_id,
                'start' => CarbonImmutable::parse($row->start_date)->startOfDay(),
                'end' => CarbonImmutable::parse($row->end_date)->startOfDay(),
                'recurring' => (bool) $row->is_recurring,
            ])
            ->all()));
    }
}
