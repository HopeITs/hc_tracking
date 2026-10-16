<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Enums\IndicatorCollector;
use App\Enums\MonthlyReportStatus;
use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Support\MonthlyReportActivityStatuses;
use App\Support\MonthlyReportGuard;
use App\Support\MonthlyReportLineBuilder;
use App\Support\MonthlyReportMirrors;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validate a submitted report (contracts §13 Workflow, D-07, D-48a): its
 * figures now count. Refused while a MEAL line is neither filled nor marked
 * Not measured. Mirrors move to `approved` and the activity statuses the
 * report confirms are written (slice 4).
 */
final class ValidateMonthlyReport
{
    public function __construct(
        private MonthlyReportMirrors $mirrors,
        private MonthlyReportActivityStatuses $activityStatuses,
    ) {}

    public function handle(MonthlyReport $report, MonthlyReportStatus $expectedStatus): MonthlyReport
    {
        return DB::transaction(function () use ($report, $expectedStatus): MonthlyReport {
            $locked = MonthlyReportGuard::lock($report, $expectedStatus, 'validate');
            $locked->project->loadMissing('program:id,donor_attribution');
            $attribution = MonthlyReportLineBuilder::attributionOf($locked->project);

            $pending = $locked->lines()
                ->where('entered_by', IndicatorCollector::Meal->value)
                ->with(['libraryIndicator', 'shares'])
                ->get()
                ->contains(fn (MonthlyReportLine $line): bool => ! SubmitMonthlyReport::isFilled($line, $attribution));

            if ($pending) {
                throw ValidationException::withMessages(['lines' => __('monthly_reports.meal_lines_pending')]);
            }

            $previous = $this->activityStatuses->confirm($locked);

            $locked->fill([
                'status' => MonthlyReportStatus::Validated,
                'validated_at' => now(),
                'validated_by' => (int) Auth::id(),
            ]);

            Activity::withoutRecording(fn () => $locked->save());
            $locked->recordActivity('monthly_report.validated', ['status' => [MonthlyReportStatus::Submitted->value, MonthlyReportStatus::Validated->value]], ['activity_statuses' => $previous]);

            $this->mirrors->moveTo($locked, $locked->submitted_at === null ? null : CarbonImmutable::parse($locked->submitted_at));

            return $locked;
        });
    }
}
