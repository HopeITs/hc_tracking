<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MonthlyReportStatus;
use App\Enums\ProjectStatus;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Support\MonthlyReportGenerator;
use App\Support\MonthlyReportLineBuilder;
use App\Support\MonthlyReportPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Launch backfill (contracts §13 "Commands", D-29): generates the monthly
 * reports of projects that were already active at deploy, from --from
 * (default 2026-10) and never before the project's start, to its end, then
 * builds the lines of the drafts whose month has started. Prints one row per
 * project: created, already there (skipped) and lines built. Idempotent;
 * --dry-run computes the same counts and writes nothing.
 */
class BackfillMonthlyReports extends Command
{
    protected $signature = 'monthly-reports:backfill
        {--from=2026-10 : The first month to generate, as YYYY-MM}
        {--dry-run : Count what would be created without writing anything}';

    protected $description = 'Generate the monthly reports of projects already active at deploy';

    public function handle(MonthlyReportGenerator $generator, MonthlyReportLineBuilder $builder): int
    {
        $from = $this->parseMonth((string) $this->option('from'));

        if ($from === null) {
            $this->error(__('monthly_reports.console.invalid_from'));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $projects = Project::query()
            ->with('program:id,donor_attribution')
            ->where('status', ProjectStatus::Active)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->orderBy('id')
            ->get();

        foreach ($projects as $project) {
            /** @var CarbonImmutable $start */
            $start = $project->start_date;
            /** @var CarbonImmutable $end */
            $end = $project->end_date;
            $first = $start->startOfMonth()->max($from);
            $months = MonthlyReportGenerator::monthsBetween($first, $end);

            $counts = $dryRun
                ? $this->preview($generator, $builder, $project, $first, $end, $months)
                : $this->apply($generator, $builder, $project, $first, $end);

            $this->line(__('monthly_reports.console.backfill_row', [
                'project' => $project->id,
                'created' => $counts['created'],
                'skipped' => count($months) - $counts['created'],
                'lines' => $counts['lines'],
            ]));
        }

        if ($dryRun) {
            $this->info(__('monthly_reports.console.dry_run'));
        }

        return self::SUCCESS;
    }

    /**
     * @return array{created: int, lines: int}
     */
    private function apply(MonthlyReportGenerator $generator, MonthlyReportLineBuilder $builder, Project $project, CarbonImmutable $first, CarbonImmutable $end): array
    {
        $created = $generator->generate($project, $first, $end);
        $periods = MonthlyReportPeriods::for($project);
        $lines = 0;

        foreach ($this->startedReports($project, $first, $end, $periods) as $report) {
            if ($report->status === MonthlyReportStatus::Draft) {
                $lines += $builder->build($report)['lines'];
            }
        }

        return ['created' => count($created), 'lines' => $lines];
    }

    /**
     * The same counts as apply(), read-only.
     *
     * @param  list<CarbonImmutable>  $months
     * @return array{created: int, lines: int}
     */
    private function preview(MonthlyReportGenerator $generator, MonthlyReportLineBuilder $builder, Project $project, CarbonImmutable $first, CarbonImmutable $end, array $months): array
    {
        $periods = MonthlyReportPeriods::for($project);
        $existing = $this->startedReports($project, $first, $end, $periods)->keyBy(fn (MonthlyReport $report): string => $report->period->toDateString());
        $templateLines = $builder->templateLines($project);
        $lines = 0;

        foreach ($months as $month) {
            if ($periods->hasStarted($month)) {
                $lines += $builder->missingLineCount($project, $templateLines, $month, $existing->get($month->toDateString()));
            }
        }

        return ['created' => count($generator->missingMonths($project, $first, $end)), 'lines' => $lines];
    }

    /**
     * The project's reports in the window whose month has started, with
     * their lines' library ids.
     *
     * @return Collection<int, MonthlyReport>
     */
    private function startedReports(Project $project, CarbonImmutable $first, CarbonImmutable $end, MonthlyReportPeriods $periods): Collection
    {
        return MonthlyReport::query()
            ->where('project_id', $project->id)
            ->whereBetween('period', [$first->toDateString(), $end->startOfMonth()->toDateString()])
            ->with('lines:id,monthly_report_id,library_indicator_id')
            ->orderBy('period')
            ->get()
            ->filter(fn (MonthlyReport $report): bool => $periods->hasStarted($report->period));
    }

    /**
     * A YYYY-MM month, else null.
     */
    private function parseMonth(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m', $value)?->startOfMonth();
    }
}
