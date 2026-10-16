<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Models\MonthlyReport;
use App\Models\Project;
use App\Support\MonthlyReportGenerator;
use App\Support\MonthlyReportLineBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Open (prepare) one month of a project (contracts §13, D-08): any started
 * month inside the project period. The draft is created when it is missing
 * and its lines are built, so a month the daily prepare has not reached
 * still opens with its lines. Opening does not make the user the preparer;
 * the first save of figures does.
 */
final class OpenMonthlyReport
{
    public function __construct(
        private MonthlyReportGenerator $generator,
        private MonthlyReportLineBuilder $lines,
    ) {}

    public function handle(Project $project, CarbonImmutable $month): MonthlyReport
    {
        Gate::authorize('open', [MonthlyReport::class, $project]);

        $month = $month->startOfMonth();

        if ($month->greaterThan(CarbonImmutable::today()->startOfMonth())) {
            throw ValidationException::withMessages(['month' => __('monthly_reports.future_month')]);
        }

        if ($project->start_date === null || $project->end_date === null
            || $month->lessThan(CarbonImmutable::parse($project->start_date)->startOfMonth())
            || $month->greaterThan(CarbonImmutable::parse($project->end_date)->startOfMonth())) {
            throw ValidationException::withMessages(['month' => __('monthly_reports.outside_period')]);
        }

        $this->generator->generate($project, $month, $month);

        $report = $project->monthlyReports()->whereDate('period', $month->toDateString())->firstOrFail();
        $this->lines->build($report);

        return $report->refresh();
    }
}
