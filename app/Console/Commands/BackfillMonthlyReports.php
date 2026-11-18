<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MonthlyReportStatus;
use App\Enums\ReportTemplateStatus;
use App\Models\MonthlyReport;
use App\Models\ReportTemplate;
use App\Support\MonthlyReportGenerator;
use App\Support\MonthlyReportLineBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Launch backfill (contracts §13 "Commands", D-29, Y-03): generates the
 * monthly reports of the confirmed report templates, from --from (default
 * 2026-10) and never before the start of the template's window
 * (MonthlyReportGenerator::windowOf()), to its end, then builds the lines of
 * the drafts whose month has started. A template with no proposal has no
 * window and is left out. Prints one row per template: created, already
 * there (skipped) and lines built on the template's confirmed lines.
 * Idempotent; --dry-run computes the same counts and writes nothing.
 */
class BackfillMonthlyReports extends Command
{
    protected $signature = 'monthly-reports:backfill
        {--from=2026-10 : The first month to generate, as YYYY-MM}
        {--dry-run : Count what would be created without writing anything}';

    protected $description = 'Generate the monthly reports of the confirmed report templates';

    public function handle(MonthlyReportGenerator $generator, MonthlyReportLineBuilder $builder): int
    {
        $from = $this->parseMonth((string) $this->option('from'));

        if ($from === null) {
            $this->error(__('monthly_reports.console.invalid_from'));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $templates = ReportTemplate::query()
            ->where('status', ReportTemplateStatus::Confirmed)
            ->with(['program:id,donor_attribution', 'proposals' => fn ($query) => $query->select([...MonthlyReportGenerator::WindowColumns, 'proposals.name'])])
            ->orderBy('id')
            ->get();

        foreach ($templates as $template) {
            $window = $generator->windowOf($template);

            if ($window === null) {
                continue;
            }

            [$start, $end] = $window;
            $first = $start->startOfMonth()->max($from);
            $months = MonthlyReportGenerator::monthsBetween($first, $end);
            $label = PrepareMonthlyReports::templateLabel($template);

            $counts = $dryRun
                ? $this->preview($generator, $builder, $template, $first, $end, $months)
                : $this->apply($generator, $builder, $template, $first, $end);

            $this->line(__('monthly_reports.console.backfill_template_row', [
                'template' => $label,
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
    private function apply(MonthlyReportGenerator $generator, MonthlyReportLineBuilder $builder, ReportTemplate $template, CarbonImmutable $first, CarbonImmutable $end): array
    {
        $created = $generator->generate($template, $first, $end);
        $lines = 0;

        foreach ($this->startedReports($template, $first, $end) as $report) {
            if ($report->status === MonthlyReportStatus::Draft) {
                $lines += $builder->build($report)['lines'];
            }
        }

        return ['created' => count($created), 'lines' => $lines];
    }

    /**
     * The same counts as apply(), read-only. A month not generated yet
     * counts every line the template plans for it.
     *
     * @param  list<CarbonImmutable>  $months
     * @return array{created: int, lines: int}
     */
    private function preview(MonthlyReportGenerator $generator, MonthlyReportLineBuilder $builder, ReportTemplate $template, CarbonImmutable $first, CarbonImmutable $end, array $months): array
    {
        $existing = $this->startedReports($template, $first, $end)->keyBy(fn (MonthlyReport $report): string => $report->period->toDateString());
        $templateLines = $builder->templateLines($template);
        $lines = 0;

        foreach ($months as $month) {
            if (self::hasStarted($month)) {
                $lines += $builder->missingLineCount($template, $templateLines, $month, $existing->get($month->toDateString()));
            }
        }

        return ['created' => count($generator->missingMonths($template, $first, $end)), 'lines' => $lines];
    }

    /**
     * The template's reports in the window whose month has started, with
     * their lines' library ids.
     *
     * @return Collection<int, MonthlyReport>
     */
    private function startedReports(ReportTemplate $template, CarbonImmutable $first, CarbonImmutable $end): Collection
    {
        return MonthlyReport::query()
            ->where('report_template_id', $template->id)
            ->whereBetween('period', [$first->toDateString(), $end->startOfMonth()->toDateString()])
            ->with('lines:id,monthly_report_id,library_indicator_id')
            ->orderBy('period')
            ->get()
            ->filter(fn (MonthlyReport $report): bool => self::hasStarted($report->period));
    }

    /**
     * Whether the month has begun (as MonthlyReportPeriods::hasStarted()).
     */
    private static function hasStarted(CarbonImmutable $month): bool
    {
        return $month->startOfMonth()->lessThanOrEqualTo(CarbonImmutable::today());
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
