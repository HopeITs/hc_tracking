<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Counts in the admin-configured working days. Every deadline, overdue,
 * escalation and stall offset goes through here.
 */
final class WorkingCalendar
{
    public function __construct(private NotificationSettings $settings) {}

    public function isWorkingDay(CarbonInterface $day): bool
    {
        return in_array($day->dayOfWeekIso, $this->workingDays(), true);
    }

    /**
     * Working days d with $from < d <= $to, by whole calendar dates.
     */
    public function workingDaysBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        if ($end->lte($start)) {
            return 0;
        }

        $calendarDays = (int) $start->diffInDays($end);
        $fullWeeks = intdiv($calendarDays, 7);
        $count = $fullWeeks * count($this->workingDays());

        for ($day = $start->addDays($fullWeeks * 7 + 1); $day->lte($end); $day = $day->addDay()) {
            if ($this->isWorkingDay($day)) {
                $count++;
            }
        }

        return $count;
    }

    public function lastWorkingDayOnOrBefore(CarbonInterface $day): CarbonImmutable
    {
        $candidate = CarbonImmutable::parse($day)->startOfDay();

        for ($step = 0; $step < 7 && ! $this->isWorkingDay($candidate); $step++) {
            $candidate = $candidate->subDay();
        }

        return $candidate;
    }

    public function firstWorkingDayOnOrAfter(CarbonInterface $day): CarbonImmutable
    {
        $candidate = CarbonImmutable::parse($day)->startOfDay();

        for ($step = 0; $step < 7 && ! $this->isWorkingDay($candidate); $step++) {
            $candidate = $candidate->addDay();
        }

        return $candidate;
    }

    public function addWorkingDays(CarbonInterface $from, int $days): CarbonImmutable
    {
        $day = CarbonImmutable::parse($from)->startOfDay();

        while ($days > 0) {
            $day = $day->addDay();

            if ($this->isWorkingDay($day)) {
                $days--;
            }
        }

        return $day;
    }

    /**
     * Weeks always start on Monday here (ISO), whatever the reader's locale:
     * Carbon's locale-dependent default starts an Arabic week on Saturday.
     */
    public function firstWorkingDayOfNextWeek(CarbonInterface $now): CarbonImmutable
    {
        return $this->firstWorkingDayOnOrAfter($this->startOfIsoWeek($now)->addWeek());
    }

    /**
     * The Monday that starts the ISO week containing the day.
     */
    public function startOfIsoWeek(CarbonInterface $day): CarbonImmutable
    {
        return CarbonImmutable::parse($day)->startOfWeek(CarbonInterface::MONDAY);
    }

    /**
     * @return list<int> ISO weekday numbers; all seven when misconfigured empty
     */
    private function workingDays(): array
    {
        $days = array_values(array_map('intval', (array) $this->settings->get('working_days')));

        return $days === [] ? [1, 2, 3, 4, 5, 6, 7] : $days;
    }
}
