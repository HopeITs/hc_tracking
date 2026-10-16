<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MonthlyReportStatus;
use App\Enums\ProjectStatus;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Notifications\Alerts\WorkingCalendar;
use App\Support\MonthlyReportLineBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Runs daily at 05:00 (routes/console.php). On a project's first working day
 * of the month (its own working days) it builds the lines of that month's
 * draft report from the confirmed template (contracts §13 "Commands",
 * §13.1 item 5). Lines and shares only: no mirror, no actor. Idempotent;
 * --dry-run writes nothing.
 */
class PrepareMonthlyReports extends Command
{
    protected $signature = 'monthly-reports:prepare
        {--dry-run : Count the lines without writing anything}';

    protected $description = 'Build the lines of the current month\'s monthly reports on each project\'s first working day';

    public function handle(MonthlyReportLineBuilder $builder, WorkingCalendar $calendar): int
    {
        $today = CarbonImmutable::today();
        $month = $today->startOfMonth();
        $dryRun = (bool) $this->option('dry-run');

        $projects = Project::query()->with('program:id,donor_attribution')->where('status', ProjectStatus::Active)->orderBy('id')->get()
            ->filter(fn (Project $project): bool => $calendar->forProject($project)->firstWorkingDayOnOrAfter($month)->isSameDay($today));

        $reports = MonthlyReport::query()
            ->whereIn('project_id', $projects->modelKeys())
            ->where('period', $month->toDateString())
            ->where('status', MonthlyReportStatus::Draft)
            ->with('lines:id,monthly_report_id,library_indicator_id')
            ->orderBy('project_id')
            ->get();

        foreach ($reports as $report) {
            /** @var Project $project */
            $project = $projects->find($report->project_id);
            $lines = $dryRun
                ? $builder->missingLineCount($project, $builder->templateLines($project), $report->period, $report)
                : $builder->build($report)['lines'];

            $this->line(__('monthly_reports.console.prepare_row', ['project' => $report->project_id, 'lines' => $lines]));
        }

        if ($dryRun) {
            $this->info(__('monthly_reports.console.dry_run'));
        }

        return self::SUCCESS;
    }
}
