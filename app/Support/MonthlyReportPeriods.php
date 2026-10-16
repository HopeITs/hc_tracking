<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\Alerts\WorkingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The one read helper for a project's monthly report months (contracts §13,
 * §13.1 item 2, D-08): which months exist, which are required (have at
 * least one line), whether a month has started, when it is due and whether
 * a report may be submitted yet. Every listener, command, policy and page
 * reads months and due-ness through here.
 *
 * for() loads the project's months once (one query); everything else
 * answers from memory. Days are counted on the project's own working days
 * (WorkingCalendar::forProject()).
 */
final class MonthlyReportPeriods
{
    /**
     * @param  Collection<int, MonthlyReport>  $reports  the project's reports, oldest first, with `lines_count`
     */
    private function __construct(
        private Collection $reports,
        private WorkingCalendar $calendar,
        private NotificationSettings $settings,
    ) {}

    public static function for(Project $project): self
    {
        $reports = MonthlyReport::query()
            ->where('project_id', $project->id)
            ->withCount('lines')
            ->orderBy('period')
            ->get(['id', 'project_id', 'period', 'status']);

        return new self($reports, app(WorkingCalendar::class)->forProject($project), app(NotificationSettings::class));
    }

    /**
     * Every generated month, oldest first.
     *
     * @return list<CarbonImmutable>
     */
    public function months(): array
    {
        return array_values($this->reports->map(fn (MonthlyReport $report): CarbonImmutable => $report->period)->all());
    }

    /**
     * The generated months with at least one line: the months that must be
     * reported, have deadlines and raise alerts once started.
     *
     * @return list<CarbonImmutable>
     */
    public function requiredMonths(): array
    {
        return array_values($this->reports
            ->filter(fn (MonthlyReport $report): bool => (int) $report->getAttribute('lines_count') > 0)
            ->map(fn (MonthlyReport $report): CarbonImmutable => $report->period)
            ->all());
    }

    /**
     * Whether the month has begun. A month that has not started is never
     * due, overdue or open for entry.
     */
    public function hasStarted(CarbonImmutable $month): bool
    {
        return $month->startOfMonth()->lessThanOrEqualTo(CarbonImmutable::today());
    }

    /**
     * The day the month's report is due: the configured number of working
     * days after the end of the month.
     */
    public function dueDate(CarbonImmutable $month): CarbonImmutable
    {
        return $this->calendar->addWorkingDays($month->endOfMonth(), (int) $this->settings->get('monthly_report_due_working_days'));
    }

    /**
     * Why the report cannot be submitted yet, or null when it may be: not
     * before the last working day of its month, and not while the nearest
     * earlier month with lines is unsubmitted (a line-less month in between
     * cannot hide it). Completeness and evidence are checked on submit.
     */
    public function canSubmit(MonthlyReport $report): ?string
    {
        $month = $report->period->startOfMonth();

        if (CarbonImmutable::today()->lessThan($this->calendar->lastWorkingDayOnOrBefore($month->endOfMonth()))) {
            return __('monthly_reports.submit_too_early');
        }

        $previous = $this->reports
            ->filter(fn (MonthlyReport $earlier): bool => $earlier->period->lessThan($month) && (int) $earlier->getAttribute('lines_count') > 0)
            ->last();

        if ($previous !== null && ! in_array($previous->status, [MonthlyReportStatus::Submitted, MonthlyReportStatus::Validated], true)) {
            return __('monthly_reports.submit_previous_first', ['month' => $previous->period->translatedFormat('F Y')]);
        }

        return null;
    }

    /**
     * Whether the project owner's current HR position is at another center
     * than the project's. A warning only, never a refusal (§13 "Who").
     */
    public static function ownerCenterMismatch(Project $project): bool
    {
        if ($project->center_id === null) {
            return false;
        }

        $centerId = EmployeeProfile::for($project->user_id)['center_id'] ?? null;

        return $centerId !== null && $centerId !== (int) $project->center_id;
    }
}
