<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Proposal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The Monday weeks an annual plan runs on (2026-11-11 agreement): from the
 * Monday of the week the proposal starts in to the week it ends in. A week
 * belongs to the month of its Monday; its number inside that month (1–5) is
 * its place among that month's Mondays, which is how activity_schedules
 * stores weeks.
 *
 * The period is the proposal's actual one once both actual dates are set,
 * else the planned one (the same rule as ProposalFramework::scheduleWindow).
 */
final class PlanCalendar
{
    /** @var list<CarbonImmutable> */
    private array $mondays;

    public function __construct(public readonly CarbonImmutable $begin, public readonly CarbonImmutable $end)
    {
        $this->mondays = [];

        for ($week = $begin->startOfWeek(CarbonInterface::MONDAY); $week->lte($end); $week = $week->addWeek()) {
            $this->mondays[] = $week;
        }
    }

    public static function forProposal(Proposal $proposal): self
    {
        $isActual = $proposal->actual_date_begin !== null && $proposal->actual_date_end !== null;
        $begin = $isActual ? $proposal->actual_date_begin : $proposal->planned_date_begin;
        $end = $isActual ? $proposal->effectiveDateEnd() : $proposal->planned_date_end;

        return new self($begin->startOfDay(), $end->startOfDay());
    }

    /**
     * Every Monday of the plan, ascending.
     *
     * @return list<CarbonImmutable>
     */
    public function mondays(): array
    {
        return $this->mondays;
    }

    public function weekCount(): int
    {
        return count($this->mondays);
    }

    public function firstMonday(): CarbonImmutable
    {
        return $this->mondays[0];
    }

    public function lastMonday(): CarbonImmutable
    {
        return $this->mondays[count($this->mondays) - 1];
    }

    /**
     * Whether a date is one of the plan's Mondays.
     */
    public function contains(CarbonImmutable $monday): bool
    {
        return $monday->isMonday() && $monday->gte($this->firstMonday()) && $monday->lte($this->lastMonday());
    }

    /**
     * The 0-based position of a Monday in the plan.
     */
    public function indexOf(CarbonImmutable $monday): int
    {
        return intdiv((int) $this->firstMonday()->diffInDays($monday->startOfDay()), 7);
    }

    /**
     * The months the plan covers, as their first days, ascending.
     *
     * @return list<CarbonImmutable>
     */
    public function months(): array
    {
        $months = [];

        foreach ($this->mondays as $monday) {
            $months[$monday->format('Y-m')] = $monday->startOfMonth();
        }

        return array_values($months);
    }

    /**
     * The plan's Mondays that belong to a month.
     *
     * @return list<CarbonImmutable>
     */
    public function mondaysOf(CarbonImmutable $month): array
    {
        $key = $month->format('Y-m');

        return array_values(array_filter($this->mondays, fn (CarbonImmutable $monday): bool => $monday->format('Y-m') === $key));
    }

    /**
     * A Monday's number among all Mondays of its calendar month (1–5).
     */
    public static function weekOfMonth(CarbonImmutable $monday): int
    {
        return intdiv($monday->day - 1, 7) + 1;
    }

    /**
     * Whether the month closes a quarter of the plan: every third month
     * counted from the plan's first month (user decision 2026-11-12).
     */
    public function isQuarterEnd(CarbonImmutable $month): bool
    {
        $first = $this->months()[0];
        $offset = ($month->year - $first->year) * 12 + $month->month - $first->month;

        return $offset >= 0 && ($offset + 1) % 3 === 0;
    }

    /**
     * When a month's update falls due: the 3rd working day after the month
     * ends, Friday and Saturday off (user decision 2026-11-12).
     */
    public static function dueDateOf(CarbonImmutable $month): CarbonImmutable
    {
        $day = $month->startOfMonth()->addMonth();
        $count = 0;

        while (true) {
            if (! in_array($day->dayOfWeek, [CarbonInterface::FRIDAY, CarbonInterface::SATURDAY], true)) {
                $count++;

                if ($count === 3) {
                    return $day;
                }
            }

            $day = $day->addDay();
        }
    }
}
