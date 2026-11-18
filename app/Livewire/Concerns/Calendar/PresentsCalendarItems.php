<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Calendar;

use App\Enums\ArStatus;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\Donor;
use App\Models\DonorReport;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Report;
use App\Models\Task;
use Carbon\CarbonImmutable;

/**
 * The status tone (fill and text classes) of each chip on calendar/index's
 * grid. The page passes these to the _item-chips partial as callables.
 *
 * Used by calendar/index only. The page provides nothing. A proposal's tone
 * reads the page's $today, so it stays a closure on the page.
 */
trait PresentsCalendarItems
{
    /** The fill and text of a chip whose date has passed with the work unfinished. */
    private const string LATE_TONE = 'bg-status-critical-soft text-status-critical';

    /**
     * Whether a calendar item's date has passed with its work unfinished,
     * reusing each model's own definition so the calendar never disagrees
     * with the item's page: a task overdue or missed, a report still a draft
     * after its date, a donor follow-up or donor report past due, a project still open after
     * its end date. Anything else (transfers, proposals, review meetings)
     * is never marked late here.
     */
    protected function isLate(object $item): bool
    {
        return match (true) {
            $item instanceof Task => $item->isOverdue() || $item->isMissed(),
            $item instanceof Report => $item->isOverdue(),
            $item instanceof Donor => $item->isFollowUpOverdue(),
            $item instanceof DonorReport => ! $item->isSubmitted() && $item->isOverdue(),
            $item instanceof Project => $item->end_date !== null
                && $item->end_date->lt(CarbonImmutable::today())
                && $item->status->isOpen(),
            default => false,
        };
    }

    /**
     * The tone of a project's end chip: late once an open project has passed
     * its end date, the neutral project tone otherwise. Start chips always
     * keep the neutral tone.
     */
    protected function projectEndTone(Project $project): string
    {
        return $this->isLate($project)
            ? self::LATE_TONE
            : 'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink';
    }

    protected function taskTone(Task $task): string
    {
        if ($this->isLate($task)) {
            return self::LATE_TONE;
        }

        return match ($task->status) {
            TaskStatus::Done => 'bg-status-good-soft text-status-good',
            TaskStatus::InProgress, TaskStatus::UnderReview => 'bg-funding-soft text-funding',
            TaskStatus::Todo => 'bg-white text-secondary-dark ring-1 ring-secondary-soft dark:bg-surface-1 dark:text-ink dark:ring-line',
        };
    }

    protected function reportTone(Report $report): string
    {
        if ($this->isLate($report)) {
            return self::LATE_TONE;
        }

        return match ($report->status) {
            ReportStatus::Approved => 'bg-status-good-soft text-status-good',
            ReportStatus::Reviewed => 'bg-funding-soft text-funding',
            ReportStatus::Draft => 'bg-status-warn-soft text-status-warn',
        };
    }

    /**
     * The tone of a monthly report chip, which sits on its due date: late
     * once a draft (or returned) report is past that date, otherwise by
     * status.
     */
    protected function monthlyReportTone(MonthlyReport $report, CarbonImmutable $dueOn): string
    {
        if ($this->isMonthlyReportLate($report, $dueOn)) {
            return self::LATE_TONE;
        }

        return match ($report->status) {
            MonthlyReportStatus::Validated => 'bg-status-good-soft text-status-good',
            MonthlyReportStatus::Submitted => 'bg-funding-soft text-funding',
            MonthlyReportStatus::Draft => 'bg-status-warn-soft text-status-warn',
        };
    }

    /**
     * Whether a monthly report is late: still a draft (a returned report is
     * a draft again) after its due date, the same rule as the monthly
     * reports list.
     */
    protected function isMonthlyReportLate(MonthlyReport $report, CarbonImmutable $dueOn): bool
    {
        return $report->status === MonthlyReportStatus::Draft && CarbonImmutable::today()->greaterThan($dueOn);
    }

    protected function transferTone(ArStatus $status): string
    {
        return match ($status) {
            ArStatus::Received => 'bg-status-good-soft text-status-good',
            ArStatus::Failed => 'bg-status-critical-soft text-primary dark:text-brand-text',
            ArStatus::Pending => 'bg-status-active-soft text-status-active',
        };
    }

    protected function donorFollowUpTone(Donor $donor): string
    {
        return $donor->isFollowUpOverdue()
            ? 'bg-status-critical-soft text-primary dark:text-brand-text'
            : 'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink';
    }

    protected function donorReportTone(DonorReport $report): string
    {
        if ($report->isSubmitted()) {
            return 'bg-status-good-soft text-status-good';
        }

        return $report->isOverdue()
            ? 'bg-status-critical-soft text-primary dark:text-brand-text'
            : 'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink';
    }
}
