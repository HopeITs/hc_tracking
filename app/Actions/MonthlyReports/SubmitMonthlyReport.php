<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Enums\ActivityStatus;
use App\Enums\DonorAttribution;
use App\Enums\IndicatorCollector;
use App\Enums\MonthlyReportEntryKind;
use App\Enums\MonthlyReportStatus;
use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportFile;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineShare;
use App\Models\ReportTemplateLine;
use App\Support\MonthlyReportGuard;
use App\Support\MonthlyReportLineBuilder;
use App\Support\MonthlyReportMirrors;
use App\Support\MonthlyReportPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Submit a draft (contracts §13 Workflow, §13.1 items 2 and 5, D-07, D-08):
 * not before the month's last working day, not while the nearest earlier
 * month with lines is unsubmitted, and only with every manager line filled
 * (MEAL lines are exempt) and the required evidence attached. No partial
 * submit. Mirrors missing a row are
 * created, and every mirror moves to `reviewed`.
 */
final class SubmitMonthlyReport
{
    public function __construct(private MonthlyReportMirrors $mirrors) {}

    public function handle(MonthlyReport $report, MonthlyReportStatus $expectedStatus): MonthlyReport
    {
        return DB::transaction(function () use ($report, $expectedStatus): MonthlyReport {
            $locked = MonthlyReportGuard::lock($report, $expectedStatus, 'submit');
            $reason = MonthlyReportPeriods::for($locked->project)->canSubmit($locked);

            if ($reason !== null) {
                throw ValidationException::withMessages(['status' => $reason]);
            }

            $locked->project->loadMissing('program:id,donor_attribution');
            $attribution = MonthlyReportLineBuilder::attributionOf($locked->project);
            $lines = $locked->lines()->with(['libraryIndicator', 'shares.mirror', 'shares.planIndicator.indicator'])->get();

            $incomplete = $lines->contains(fn (MonthlyReportLine $line): bool => $line->entered_by !== IndicatorCollector::Meal
                && ! self::isFilled($line, $attribution));

            if ($incomplete) {
                throw ValidationException::withMessages(['lines' => __('monthly_reports.value_required')]);
            }

            self::ensureEvidence($locked, $lines);

            $actorId = (int) Auth::id();

            Activity::withoutRecording(fn () => $lines->each(fn (MonthlyReportLine $line) => $this->mirrors->sync($locked, $line->shares, $actorId, createMissing: true)));

            $locked->fill([
                'status' => MonthlyReportStatus::Submitted,
                'submitted_at' => now(),
                'submitted_by' => $actorId,
                'prepared_by' => $locked->prepared_by ?? $actorId,
            ])->save();

            $this->mirrors->moveTo($locked, CarbonImmutable::today());

            return $locked;
        });
    }

    /**
     * Required evidence (D-42, contracts §13): every manager line carries a
     * file of each type its template lines require, and every Completed or
     * Conducted activity entry carries a file. Three queries.
     *
     * @param  Collection<int, MonthlyReportLine>  $lines
     */
    private static function ensureEvidence(MonthlyReport $report, Collection $lines): void
    {
        /** @var Collection<int, MonthlyReportFile> $files */
        $files = $report->files()->get(['id', 'monthly_report_line_id', 'monthly_report_activity_entry_id', 'evidence_type_id']);

        $finishedWithoutFile = $report->activityEntries()
            ->whereIn('status', [ActivityStatus::Completed->value, ActivityStatus::Conducted->value])
            ->whereDoesntHave('files')
            ->exists();

        if ($finishedWithoutFile) {
            throw ValidationException::withMessages(['activities' => __('monthly_reports.activity_evidence_required')]);
        }

        $managerLines = $lines->filter(fn (MonthlyReportLine $line): bool => $line->entered_by !== IndicatorCollector::Meal);
        $targetIds = $managerLines->flatMap(fn (MonthlyReportLine $line) => $line->shares->pluck('plan_indicator_id'))->unique()->values();

        if ($targetIds->isEmpty()) {
            return;
        }

        /** @var Collection<int, list<int>> $requiredByTarget */
        $requiredByTarget = ReportTemplateLine::query()
            ->whereHas('template', fn (Builder $query) => $query->where('project_id', $report->project_id))
            ->whereIn('plan_indicator_id', $targetIds)
            ->with('evidenceTypes:id')
            ->get(['id', 'report_template_id', 'plan_indicator_id'])
            ->mapWithKeys(fn (ReportTemplateLine $templateLine): array => [$templateLine->plan_indicator_id => $templateLine->evidenceTypes->modelKeys()]);

        $missing = $managerLines->contains(function (MonthlyReportLine $line) use ($files, $requiredByTarget): bool {
            $required = $line->shares->flatMap(fn (MonthlyReportLineShare $share): array => $requiredByTarget->get($share->plan_indicator_id, []))->unique();
            $attached = $files->where('monthly_report_line_id', $line->id)->pluck('evidence_type_id');

            return $required->diff($attached)->isNotEmpty();
        });

        if ($missing) {
            throw ValidationException::withMessages(['lines' => __('monthly_reports.evidence_required')]);
        }
    }

    /**
     * Whether a line carries its figures: the line itself in `shared`, every
     * share in `split`. Also used by ValidateMonthlyReport for MEAL lines.
     */
    public static function isFilled(MonthlyReportLine $line, DonorAttribution $attribution): bool
    {
        $kind = MonthlyReportEntryKind::for($line->libraryIndicator);

        if ($attribution === DonorAttribution::Shared) {
            return $kind->isFilled($line->value, $line->numerator, $line->not_measured);
        }

        return $line->shares->every(fn (MonthlyReportLineShare $share): bool => $kind->isFilled($share->value, $share->numerator, $share->not_measured));
    }
}
