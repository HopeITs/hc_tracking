<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Carbon\CarbonImmutable;

/**
 * Month and day / 4-days / week navigation shared by the calendar, the
 * milestones directory and the tasks directory, so the three grids step
 * through time the same way.
 *
 * The page declares the three properties itself, because each names them
 * differently in the URL (milestones and tasks map viewMode to ?period=).
 *
 * @property string $month 'Y-m', or '' for the current month
 * @property string $anchorDate 'Y-m-d', or '' for today
 * @property string $viewMode 'day', 'four_days', 'week' or 'month'
 */
trait NavigatesCalendarPeriods
{
    public function previousMonth(): void
    {
        $this->month = $this->activeMonth()->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->activeMonth()->addMonthNoOverflow()->format('Y-m');
    }

    public function goToToday(): void
    {
        $this->month = '';
        $this->anchorDate = '';
    }

    /**
     * Switch between the month grid and the ClickUp-style day/4-days/week
     * time-grid views. Only the four values below are valid view modes; any
     * other value (e.g. a stale bookmarked URL) is silently ignored so the
     * component falls back to whatever $viewMode already holds.
     */
    public function setViewMode(string $viewMode): void
    {
        if (! in_array($viewMode, ['day', 'four_days', 'week', 'month'], true)) {
            return;
        }

        $this->viewMode = $viewMode;
    }

    public function previousPeriod(): void
    {
        $this->anchorDate = $this->activeAnchor()->subDays($this->periodLengthInDays())->format('Y-m-d');
    }

    public function nextPeriod(): void
    {
        $this->anchorDate = $this->activeAnchor()->addDays($this->periodLengthInDays())->format('Y-m-d');
    }

    protected function periodLengthInDays(): int
    {
        return match ($this->viewMode) {
            'day' => 1,
            'four_days' => 4,
            'week' => 7,
            default => 1,
        };
    }

    protected function activeAnchor(): CarbonImmutable
    {
        if ($this->anchorDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->anchorDate) === 1) {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $this->anchorDate);

            if ($parsed !== false) {
                return $parsed->startOfDay();
            }
        }

        return CarbonImmutable::now()->startOfDay();
    }

    /**
     * The visible days for the day/4-days/week views. Week always starts on
     * the anchor's Sunday, so navigating never lands mid-week.
     *
     * @return array<int, CarbonImmutable>
     */
    protected function periodDays(): array
    {
        $anchor = $this->viewMode === 'week'
            ? $this->activeAnchor()->startOfWeek(CarbonImmutable::SUNDAY)
            : $this->activeAnchor();

        return array_map(
            fn (int $i): CarbonImmutable => $anchor->addDays($i),
            range(0, $this->periodLengthInDays() - 1),
        );
    }

    protected function activeMonth(): CarbonImmutable
    {
        if ($this->month !== '' && preg_match('/^\d{4}-\d{2}$/', $this->month) === 1) {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01');

            if ($parsed !== false) {
                return $parsed->startOfMonth();
            }
        }

        return CarbonImmutable::now()->startOfMonth();
    }
}
