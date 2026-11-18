<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Models\MonthlyReport;
use App\Models\ReportTemplate;
use App\Support\MonthlyReportGenerator;
use App\Support\MonthlyReportLineBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Open (prepare) one month of a template (contracts §13, §13.1 item 6, D-08,
 * D-76): any started month inside the window of the template's proposals.
 * The draft is created when it is missing and its lines are built, so a
 * month the daily prepare has not reached still opens with its lines.
 * Opening does not make the user the preparer; the first save of figures
 * does.
 */
final class OpenMonthlyReport
{
    public function __construct(
        private MonthlyReportGenerator $generator,
        private MonthlyReportLineBuilder $lines,
    ) {}

    public function handle(ReportTemplate $template, CarbonImmutable $month): MonthlyReport
    {
        Gate::authorize('open', [MonthlyReport::class, $template]);

        $month = $month->startOfMonth();
        $refusal = self::refusal($template, $month);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['month' => $refusal]);
        }

        $this->generator->generate($template, $month, $month);

        $report = $template->monthlyReports()->whereDate('period', $month->toDateString())->firstOrFail();
        $this->lines->build($report);

        return $report->refresh();
    }

    /**
     * Why the month cannot be opened, or null when it can: a month not begun
     * yet, a template with no proposal period at all, or a month outside the
     * window of its proposals (MonthlyReportGenerator::windowOf()).
     *
     * The one place this rule lives. The Monthly Reports list asks it before
     * offering Fill, so it never shows a button this action would refuse.
     */
    public static function refusal(ReportTemplate $template, CarbonImmutable $month): ?string
    {
        $month = $month->startOfMonth();

        if ($month->greaterThan(CarbonImmutable::today()->startOfMonth())) {
            return __('monthly_reports.future_month');
        }

        $window = app(MonthlyReportGenerator::class)->windowOf($template);

        if ($window === null) {
            return __('monthly_reports.project_has_no_period');
        }

        if ($month->lessThan($window[0]->startOfMonth()) || $month->greaterThan($window[1]->startOfMonth())) {
            return __('monthly_reports.outside_period');
        }

        return null;
    }
}
