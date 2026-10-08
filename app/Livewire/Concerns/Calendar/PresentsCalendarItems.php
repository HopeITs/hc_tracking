<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Calendar;

use App\Enums\ArStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\Donor;
use App\Models\DonorReport;
use App\Models\Milestone;

/**
 * The status tone (fill and text classes) of each chip on calendar/index's
 * grid. The page passes these to the _item-chips partial as callables.
 *
 * Used by calendar/index only. The page provides nothing. A proposal's tone
 * reads the page's $today, so it stays a closure on the page.
 */
trait PresentsCalendarItems
{
    protected function markerTone(Milestone $milestone): string
    {
        if ($milestone->isMissed() || $milestone->isOverdue()) {
            return 'bg-status-critical-soft text-primary dark:text-brand-text';
        }

        return match ($milestone->status) {
            MilestoneStatus::Reached => 'bg-status-good-soft text-status-good',
            MilestoneStatus::Ongoing => 'bg-status-warn-soft text-status-warn',
            MilestoneStatus::Postponed, MilestoneStatus::Cancelled => 'bg-secondary-soft text-secondary-medium dark:bg-surface-2 dark:text-ink-muted',
            MilestoneStatus::Planned => 'bg-white text-secondary-dark ring-1 ring-secondary-soft dark:bg-surface-1 dark:text-ink dark:ring-line',
        };
    }

    protected function taskTone(TaskStatus $status): string
    {
        return match ($status) {
            TaskStatus::Done => 'bg-status-good-soft text-status-good',
            TaskStatus::InProgress, TaskStatus::UnderReview => 'bg-funding-soft text-funding',
            TaskStatus::Todo => 'bg-white text-secondary-dark ring-1 ring-secondary-soft dark:bg-surface-1 dark:text-ink dark:ring-line',
        };
    }

    protected function reportTone(ReportStatus $status): string
    {
        return match ($status) {
            ReportStatus::Approved => 'bg-status-good-soft text-status-good',
            ReportStatus::Reviewed => 'bg-funding-soft text-funding',
            ReportStatus::Draft => 'bg-status-warn-soft text-status-warn',
        };
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
