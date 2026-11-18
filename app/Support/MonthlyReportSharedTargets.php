<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\IndicatorMeasure;
use App\Enums\ReportStatus;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLineShare;
use App\Models\PlanIndicator;
use App\Models\ProposalLocation;
use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Shared targets of a monthly report (D-90): a target of a location row
 * with several centers (`plan_indicators.all_centers`) is a line in each
 * center's report. Each center enters and is validated on its own figure;
 * this read gives what the report shows beside it: the target value, how
 * many centers share it, and what they reached together so far from
 * validated figures only. A center's own figure is never compared alone
 * against the whole target.
 *
 * "Reached together": counts are PlanIndicator::achievedValue() over the
 * approved mirrors of every center. Ratios (percentage, average) are pooled
 * across the centers (Dev 1, 18 Nov 2026): Σ numerators ÷ Σ denominators of
 * the centers' validated lines, never the latest value nor an average.
 * Each center is one term of PlanIndicator::pooledRatio(), so within a
 * center the existing rule holds (latest entered line, or Σ counts over the
 * fixed denominator) and there is no second pooling rule.
 *
 * A fixed number of queries, whatever the number of lines.
 */
final class MonthlyReportSharedTargets
{
    /**
     * The report's shared targets, keyed by plan_indicator id. `reached` is
     * null while no center has a validated figure.
     *
     * @return array<int, array{centers: int, target: float, reached: float|null}>
     */
    public function for(MonthlyReport $report): array
    {
        $targetIds = MonthlyReportLineShare::query()
            ->whereIn('monthly_report_line_id', $report->lines()->select('id'))
            ->pluck('plan_indicator_id')
            ->unique()
            ->values()
            ->all();

        if ($targetIds === []) {
            return [];
        }

        $targets = PlanIndicator::query()
            ->whereKey($targetIds)
            ->where('all_centers', true)
            ->with(['indicator:id,measure,library_indicator_id', 'indicator.libraryIndicator:id,denominator_basis,is_cumulative'])
            ->with(['reports' => fn ($query) => $query->where('status', ReportStatus::Approved->value)])
            ->orderBy('id')
            ->get(['id', 'indicator_id', 'target', 'source_type', 'source_id', 'city_id', 'program_id', 'all_centers']);

        if ($targets->isEmpty()) {
            return [];
        }

        $rows = ProposalLocation::query()
            ->whereIn('proposal_id', $targets->where('source_type', 'proposal')->pluck('source_id')->unique()->values()->all())
            ->withCount('centers')
            ->get(['id', 'proposal_id', 'city_id', 'program_id']);

        $templateOfReport = MonthlyReport::query()
            ->whereKey($targets->flatMap(fn (PlanIndicator $target) => $target->reports->pluck('monthly_report_id'))->filter()->unique()->values()->all())
            ->pluck('report_template_id', 'id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $result = [];

        foreach ($targets as $target) {
            $row = $target->source_type === 'proposal'
                ? $rows->first(fn (ProposalLocation $row): bool => (int) $row->proposal_id === (int) $target->source_id
                    && (int) $row->city_id === (int) $target->city_id
                    && (int) $row->program_id === (int) $target->program_id)
                : null;

            $result[$target->id] = [
                'centers' => (int) ($row?->getAttribute('centers_count') ?? 0),
                'target' => (float) $target->target,
                'reached' => $target->reports->isEmpty() ? null : $this->reached($target, $templateOfReport),
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $templateOfReport  monthly report id => template id
     */
    private function reached(PlanIndicator $target, array $templateOfReport): ?float
    {
        if (! in_array($target->indicator?->measure, [IndicatorMeasure::Percentage, IndicatorMeasure::Average], true)) {
            return $target->achievedValue();
        }

        // One term per center: the mirrors of one template's reports. A mirror without a report stands alone.
        $centers = $target->reports
            ->groupBy(fn (Report $mirror): string => isset($templateOfReport[(int) $mirror->monthly_report_id]) ? 't'.$templateOfReport[(int) $mirror->monthly_report_id] : 'r'.$mirror->id)
            ->map(function (Collection $mirrors) use ($target): PlanIndicator {
                $term = clone $target;

                return $term->setRelation('reports', new EloquentCollection($mirrors->values()->all()));
            });

        $dates = $target->reports->pluck('planned_date');
        $pooled = PlanIndicator::pooledRatio($centers->values(), CarbonImmutable::parse($dates->min()), CarbonImmutable::parse($dates->max()));

        return $pooled['ratio'];
    }
}
