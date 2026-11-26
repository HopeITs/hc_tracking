<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use App\Models\ReportTemplate;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\Alerts\WorkingCalendar;
use App\Support\Reporting\PlaceBridge;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The one read helper for a template's monthly report months (contracts §13,
 * §13.1 item 2, D-08, D-76): which months exist, which are required (have at
 * least one line), whether a month has started, when it is due and whether
 * a report may be submitted yet. Every listener, command, policy and page
 * reads months and due-ness through here. Sequencing is per template: a
 * month of another template at the same place never blocks this one.
 *
 * for() loads the template's months once; everything else answers from
 * memory. Days are counted on the working days of the template's legacy
 * project (workingDaysOf(), read through PlaceBridge until Stage B copies
 * them to the location row), skipping the HR holidays of its place
 * (HrHolidays::forPlace()).
 */
final class MonthlyReportPeriods
{
    /**
     * Each month's due date once counted, by 'Y-m': counting walks the calendar day by day.
     *
     * @var array<string, CarbonImmutable>
     */
    private array $dueDates = [];

    /**
     * @param  Collection<int, MonthlyReport>  $reports  the template's reports, oldest first, with `lines_count`
     */
    private function __construct(
        private Collection $reports,
        private WorkingCalendar $calendar,
        private NotificationSettings $settings,
    ) {}

    public static function for(ReportTemplate $template): self
    {
        $reports = MonthlyReport::query()
            ->where('report_template_id', $template->id)
            ->withCount('lines')
            ->orderBy('period')
            ->get(['id', 'report_template_id', 'project_id', 'period', 'status', 'revised_due_on']);

        return self::fromLoaded($template, $reports, self::workingDaysOf([$template])[$template->id] ?? null);
    }

    /**
     * The same helper over reports already loaded by the caller (the
     * template's reports, oldest first, with `lines_count`) and the
     * template's working days from workingDaysOf(), for scanners reading
     * many templates at once. No query.
     *
     * @param  Collection<int, MonthlyReport>  $reports
     * @param  list<int>|null  $workingDays  null = the global working days
     */
    public static function fromLoaded(ReportTemplate $template, Collection $reports, ?array $workingDays): self
    {
        // The working days, with the HR holidays of the place's center, city and program skipped too:
        // a holiday never counts as a day to report in, nor as the last working day of a month.
        $calendar = app(WorkingCalendar::class)->forDays($workingDays)->withHolidays(HrHolidays::forPlace($template->place()));

        return new self($reports, $calendar, app(NotificationSettings::class));
    }

    /**
     * The ISO working days of each template, read from its legacy project
     * through PlaceBridge (D-74) in a fixed number of queries; null when it
     * has none (the global days apply). Moves to the location row in Stage B.
     *
     * @param  iterable<ReportTemplate>  $templates
     * @return array<int, list<int>|null> template id => working days
     */
    public static function workingDaysOf(iterable $templates): array
    {
        $bridge = app(PlaceBridge::class);
        $projectIds = $bridge->legacyProjectIdsFor($templates);
        $projects = $bridge->projectsByIds(array_values(array_unique(array_filter($projectIds, fn (?int $id): bool => $id !== null))));
        $result = [];

        foreach ($projectIds as $templateId => $projectId) {
            $days = $projectId === null ? null : $projects->get($projectId)?->getAttribute('working_days');
            $result[$templateId] = is_array($days) && $days !== [] ? array_values($days) : null;
        }

        return $result;
    }

    /**
     * The started required months not yet submitted: the months that have
     * a deadline the alert scanners watch (§13.1 item 2).
     *
     * @return list<MonthlyReport>
     */
    public function openRequiredReports(): array
    {
        return array_values($this->reports
            ->filter(fn (MonthlyReport $report): bool => $report->status === MonthlyReportStatus::Draft
                && (int) $report->getAttribute('lines_count') > 0
                && $this->hasStarted($report->period))
            ->all());
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
        return $this->dueDates[$month->format('Y-m')] ??= $this->calendar->addWorkingDays($month->endOfMonth(), (int) $this->settings->get('monthly_report_due_working_days'));
    }

    /**
     * The deadline the report is held to now: the one set when it was
     * returned after its own deadline (`revised_due_on`), or else the
     * month's own deadline. Everything that says "overdue" reads this, so a
     * returned report is late only once its new deadline has passed.
     */
    public function effectiveDueDate(MonthlyReport $report): CarbonImmutable
    {
        return $report->revised_due_on ?? $this->dueDate($report->period);
    }

    /**
     * The deadline for a report sent back to the preparer on the given day:
     * returned by its validator, or reopened by a granted reopen request
     * (Dynamic Deadline and 1-Business-Day rules, Managers UAT 2026-11-10).
     *
     * Sent back on or before its own deadline, the report keeps that deadline
     * (null: nothing to store). Sent back after it, the preparer gets until
     * the end of the next working day, on the template's working days with
     * official holidays skipped too.
     */
    public function revisedDueDateWhenSentBack(MonthlyReport $report, CarbonImmutable $sentBackOn): ?CarbonImmutable
    {
        if (! $sentBackOn->startOfDay()->greaterThan($this->dueDate($report->period))) {
            return null;
        }

        return $this->calendar->nextWorkingDayAfter($sentBackOn);
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
}
