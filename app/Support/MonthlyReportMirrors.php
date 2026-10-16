<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ReportStatus;
use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLineShare;
use App\Models\Report;
use Carbon\CarbonImmutable;

/**
 * The `reports` rows mirroring a monthly report's shares (contracts §13
 * mirrors, §13.1 item 5), so PlanIndicator::achievedValue() stays the one
 * achievement definition. One mirror per share; its figures are the share's
 * and its status follows the report. Written only from the T17A actions,
 * inside their transaction and without activity: the report's own move is
 * the one activity.
 */
final class MonthlyReportMirrors
{
    /**
     * Create or update the mirrors of the given shares. A share without a
     * mirror gets one only when $createMissing or once it carries figures.
     *
     * Reads each share's loaded `mirror` and `planIndicator.indicator`.
     *
     * @param  iterable<MonthlyReportLineShare>  $shares
     */
    public function sync(MonthlyReport $report, iterable $shares, int $actorId, bool $createMissing = false): void
    {
        Activity::withoutRecording(function () use ($report, $shares, $actorId, $createMissing): void {
            foreach ($shares as $share) {
                $figures = [
                    'value' => $share->value,
                    'numerator' => $share->numerator,
                    'denominator' => $share->denominator,
                ];

                $mirror = $share->mirror;

                if ($mirror !== null) {
                    $mirror->fill($figures);

                    if ($mirror->isDirty()) {
                        $mirror->save();
                    }

                    continue;
                }

                if (! $createMissing && ! $this->hasFigures($share)) {
                    continue;
                }

                $share->setRelation('mirror', $this->create($report, $share, $figures, $actorId));
            }
        });
    }

    /**
     * Move every mirror of the report to the status its report implies.
     * `submit_date` is the submit day when submitted, else NULL.
     */
    public function moveTo(MonthlyReport $report, ?CarbonImmutable $submitDate): void
    {
        Report::query()->where('monthly_report_id', $report->id)->update([
            'status' => $report->status->mirrorStatus()->value,
            'submit_date' => $submitDate?->toDateString(),
            'updated_at' => now(),
        ]);
    }

    private function hasFigures(MonthlyReportLineShare $share): bool
    {
        return $share->value !== null || $share->numerator !== null || $share->denominator !== null || $share->not_measured;
    }

    /**
     * @param  array{value: mixed, numerator: mixed, denominator: mixed}  $figures
     */
    private function create(MonthlyReport $report, MonthlyReportLineShare $share, array $figures, int $actorId): Report
    {
        $month = $report->period->startOfMonth();

        $mirror = new Report([
            'plan_indicator_id' => $share->plan_indicator_id,
            'user_id' => $actorId,
            'status' => $report->status->mirrorStatus(),
            'planned_date' => $month->endOfMonth()->toDateString(),
            'period_start' => $month->toDateString(),
            'period_end' => $month->endOfMonth()->toDateString(),
            'monthly_report_id' => $report->id,
            'monthly_report_line_share_id' => $share->id,
            'submit_date' => $report->status->mirrorStatus() === ReportStatus::Draft ? null : CarbonImmutable::today()->toDateString(),
            ...$figures,
        ]);

        $mirror->setRelation('planIndicator', $share->planIndicator);
        $mirror->save();

        return $mirror;
    }
}
