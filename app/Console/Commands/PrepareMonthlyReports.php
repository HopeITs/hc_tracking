<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MonthlyReportStatus;
use App\Enums\ReportTemplateStatus;
use App\Models\MonthlyReport;
use App\Models\ReportTemplate;
use App\Notifications\Alerts\WorkingCalendar;
use App\Support\MonthlyReportLineBuilder;
use App\Support\MonthlyReportPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Runs daily at 05:00 (routes/console.php). On the first working day of the
 * month it builds the lines of that month's draft reports of the confirmed
 * report templates (contracts §13 "Commands", §13.1 item 5, Y-03). The day
 * is counted on the template's working days
 * (MonthlyReportPeriods::workingDaysOf()), or on the global days when it has
 * none. Lines and shares only: no mirror, no actor. Idempotent; --dry-run writes nothing.
 */
class PrepareMonthlyReports extends Command
{
    protected $signature = 'monthly-reports:prepare
        {--dry-run : Count the lines without writing anything}';

    protected $description = 'Build the lines of the current month\'s monthly reports on the first working day';

    public function handle(MonthlyReportLineBuilder $builder, WorkingCalendar $calendar): int
    {
        $today = CarbonImmutable::today();
        $month = $today->startOfMonth();
        $dryRun = (bool) $this->option('dry-run');

        $reports = MonthlyReport::query()
            ->whereIn('report_template_id', ReportTemplate::query()->select('id')->where('status', ReportTemplateStatus::Confirmed))
            ->where('period', $month->toDateString())
            ->where('status', MonthlyReportStatus::Draft)
            ->with([
                'lines:id,monthly_report_id,library_indicator_id',
                'template',
                'template.program:id,donor_attribution',
                'template.proposals:proposals.id,proposals.name',
            ])
            ->orderBy('report_template_id')
            ->get();

        $workingDays = MonthlyReportPeriods::workingDaysOf($reports->pluck('template')->unique('id')->values());

        $reports = $reports->filter(fn (MonthlyReport $report): bool => $calendar
            ->forDays($workingDays[$report->report_template_id] ?? null)
            ->firstWorkingDayOnOrAfter($month)->isSameDay($today));

        foreach ($reports as $report) {
            $template = $report->template;

            $lines = $dryRun
                ? $builder->missingLineCount($template, $builder->templateLines($template), $report->period, $report)
                : $builder->build($report)['lines'];

            $this->line(__('monthly_reports.console.prepare_template_row', ['template' => self::templateLabel($template), 'lines' => $lines]));
        }

        if ($dryRun) {
            $this->info(__('monthly_reports.console.dry_run'));
        }

        return self::SUCCESS;
    }

    /**
     * The template's id and the names of its proposals, joined with " · ".
     * Load `proposals` (id, name) first.
     */
    public static function templateLabel(ReportTemplate $template): string
    {
        return implode(' · ', [(string) $template->id, ...$template->proposals->sortBy('id')->pluck('name')->map(fn (mixed $name): string => (string) $name)->all()]);
    }
}
