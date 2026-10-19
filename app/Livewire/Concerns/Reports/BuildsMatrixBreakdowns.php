<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Reports;

use App\Enums\DenominatorBasis;
use App\Enums\DonorAttribution;
use App\Enums\IndicatorMeasure;
use App\Enums\MonthlyReportStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The by-sex breakdown rows of the Monthly Matrix (T18 slice 2, D-50,
 * contracts §14.1): male, female and their total, each with "of whom persons
 * with disability", read from `monthly_report_line_breakdowns` of validated
 * monthly reports only.
 *
 * Used by BuildsMonthlyMatrix. Two flat reads whatever the row count: the
 * line-level rows (`shared` programs) and the share-level rows (`split`
 * programs). A ratio category is Σ numerator ÷ Σ denominator over the
 * window, never a sum or average of monthly percentages; a fixed-denominator
 * row shows counts only (D-25).
 *
 * `breakdown_raw` keeps the summed figures so the grouped mode can add the
 * rows of a group before the figures are worked out again.
 */
trait BuildsMatrixBreakdowns
{
    /** Breakdown column => the short key its sums are kept under. */
    private const BREAKDOWN_FIELDS = [
        'value' => 'v', 'numerator' => 'n', 'denominator' => 'd',
        'disabled_value' => 'dv', 'disabled_numerator' => 'dn', 'disabled_denominator' => 'dd',
    ];

    /**
     * Adds `breakdown_kind`, `breakdown_raw` and `breakdown` to every
     * disaggregated row.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<CarbonImmutable>  $months
     * @return list<array<string, mixed>>
     */
    protected function withBreakdowns(array $rows, array $months, bool $isDonorView): array
    {
        $disaggregated = array_values(array_filter($rows, fn (array $row): bool => $row['is_disaggregated']));

        if ($disaggregated === []) {
            return $rows;
        }

        $from = $months[0]->toDateString();
        $to = $months[11]->toDateString();
        $shared = array_values(array_filter($disaggregated, fn (array $row): bool => $row['attribution'] !== DonorAttribution::Split));
        $split = array_values(array_filter($disaggregated, fn (array $row): bool => $row['attribution'] === DonorAttribution::Split));

        $lineLevel = $shared === [] ? [] : $this->lineLevelBreakdowns($shared, $from, $to);
        $shareLevel = $split === [] ? [] : $this->shareLevelBreakdowns($split, $from, $to);

        foreach ($rows as $index => $row) {
            if (! $row['is_disaggregated']) {
                continue;
            }

            if ($row['attribution'] === DonorAttribution::Split) {
                $raw = [];
                foreach ($isDonorView ? [$row['target_ids'][0]] : $row['target_ids'] as $targetId) {
                    $raw = $this->addBreakdownRaw($raw, $shareLevel[$targetId] ?? []);
                }
            } else {
                $raw = $lineLevel[$row['project_id'].'|'.$row['library_id']] ?? [];
            }

            $kind = $this->breakdownKind($row);
            $rows[$index]['breakdown_kind'] = $kind;
            $rows[$index]['breakdown_raw'] = $raw;
            $rows[$index]['breakdown'] = $this->finalizeBreakdown($raw, $kind, $months, $row['measure'] === IndicatorMeasure::Percentage, $row['is_cumulative']);
        }

        return $rows;
    }

    /**
     * How a row's breakdown is read: counts, fixed-denominator counts only,
     * or entered ratios.
     *
     * @param  array<string, mixed>  $row
     */
    protected function breakdownKind(array $row): string
    {
        return match (true) {
            $row['measure'] === IndicatorMeasure::Numeric => 'count',
            $row['measure'] === IndicatorMeasure::Percentage && $row['denominator_basis'] === DenominatorBasis::Fixed => 'fixed',
            default => 'ratio',
        };
    }

    /**
     * The line-level rows (share NULL) of the validated reports, by
     * `project|library` then gender then month.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, array<string, array<string, float>>>>
     */
    private function lineLevelBreakdowns(array $rows, string $from, string $to): array
    {
        $result = [];

        $query = DB::table('monthly_report_line_breakdowns as b')
            ->join('monthly_report_lines as l', 'l.id', '=', 'b.monthly_report_line_id')
            ->join('monthly_reports as mr', 'mr.id', '=', 'l.monthly_report_id')
            ->whereNull('b.monthly_report_line_share_id')
            ->where('mr.status', MonthlyReportStatus::Validated->value)
            ->whereBetween('mr.period', [$from, $to])
            ->whereIn('mr.project_id', array_values(array_unique(array_column($rows, 'project_id'))))
            ->whereIn('l.library_indicator_id', array_values(array_unique(array_column($rows, 'library_id'))))
            ->get(['mr.project_id', 'mr.period', 'l.library_indicator_id', 'b.gender', ...$this->breakdownColumns()]);

        foreach ($query as $line) {
            $this->foldBreakdown($result[$line->project_id.'|'.$line->library_indicator_id], $line);
        }

        return $result;
    }

    /**
     * The share-level rows of the validated reports, by target then gender
     * then month.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array<string, array<string, array<string, float>>>>
     */
    private function shareLevelBreakdowns(array $rows, string $from, string $to): array
    {
        $result = [];

        $query = DB::table('monthly_report_line_breakdowns as b')
            ->join('monthly_report_line_shares as s', 's.id', '=', 'b.monthly_report_line_share_id')
            ->join('monthly_report_lines as l', 'l.id', '=', 'b.monthly_report_line_id')
            ->join('monthly_reports as mr', 'mr.id', '=', 'l.monthly_report_id')
            ->where('mr.status', MonthlyReportStatus::Validated->value)
            ->whereBetween('mr.period', [$from, $to])
            ->whereIn('s.plan_indicator_id', array_merge(...array_column($rows, 'target_ids')))
            ->get(['s.plan_indicator_id', 'mr.period', 'b.gender', ...$this->breakdownColumns()]);

        foreach ($query as $share) {
            $this->foldBreakdown($result[(int) $share->plan_indicator_id], $share);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function breakdownColumns(): array
    {
        return array_map(fn (string $column): string => 'b.'.$column, array_keys(self::BREAKDOWN_FIELDS));
    }

    /**
     * Adds one breakdown row to a `gender => month => figures` bucket.
     *
     * @param  array<string, array<string, array<string, float>>>|null  $bucket
     */
    private function foldBreakdown(?array &$bucket, object $row): void
    {
        $month = substr((string) $row->period, 0, 7);

        foreach (self::BREAKDOWN_FIELDS as $column => $key) {
            if ($row->{$column} !== null) {
                $bucket[(string) $row->gender][$month][$key] = ($bucket[(string) $row->gender][$month][$key] ?? 0.0) + (float) $row->{$column};
            }
        }
    }

    /**
     * Two `gender => month => figures` buckets added together.
     *
     * @param  array<string, array<string, array<string, float>>>  $first
     * @param  array<string, array<string, array<string, float>>>  $second
     * @return array<string, array<string, array<string, float>>>
     */
    protected function addBreakdownRaw(array $first, array $second): array
    {
        foreach ($second as $gender => $months) {
            foreach ($months as $month => $figures) {
                foreach ($figures as $key => $amount) {
                    $first[$gender][$month][$key] = ($first[$gender][$month][$key] ?? 0.0) + $amount;
                }
            }
        }

        return $first;
    }

    /**
     * The male, female and total categories as display figures.
     *
     * @param  array<string, array<string, array<string, float>>>  $raw
     * @param  list<CarbonImmutable>  $months
     * @return array<string, array{cells: array<string, ?float>, total: ?float, pwd: array{cells: array<string, ?float>, total: ?float}}>
     */
    protected function finalizeBreakdown(array $raw, string $kind, array $months, bool $isPercentage, bool $isCumulative): array
    {
        $both = $this->addBreakdownRaw(['x' => $raw['male'] ?? []], ['x' => $raw['female'] ?? []])['x'];
        $result = [];

        foreach (['male' => $raw['male'] ?? [], 'female' => $raw['female'] ?? [], 'total' => $both] as $category => $byMonth) {
            $result[$category] = [
                ...$this->breakdownSeries($byMonth, ['v', 'n', 'd'], $kind, $months, $isPercentage, $isCumulative),
                'pwd' => $this->breakdownSeries($byMonth, ['dv', 'dn', 'dd'], $kind, $months, $isPercentage, $isCumulative),
            ];
        }

        return $result;
    }

    /**
     * One category's twelve cells and its total over the window.
     *
     * @param  array<string, array<string, float>>  $byMonth
     * @param  array{0: string, 1: string, 2: string}  $fields  value, numerator, denominator keys
     * @param  list<CarbonImmutable>  $months
     * @return array{cells: array<string, ?float>, total: ?float}
     */
    private function breakdownSeries(array $byMonth, array $fields, string $kind, array $months, bool $isPercentage, bool $isCumulative): array
    {
        [$value, $numerator, $denominator] = $fields;
        $multiplier = $isPercentage ? 100 : 1;
        $cells = [];

        foreach ($months as $month) {
            $figures = $byMonth[$month->format('Y-m')] ?? [];
            $cells[$month->format('Y-m')] = match ($kind) {
                'count' => isset($figures[$value]) ? $figures[$value] : null,
                'fixed' => isset($figures[$numerator]) ? $figures[$numerator] : null,
                default => ($figures[$denominator] ?? 0.0) > 0.0 ? round(($figures[$numerator] ?? 0.0) / $figures[$denominator] * $multiplier, 2) : null,
            };
        }

        if ($kind === 'ratio') {
            $sumNumerator = array_sum(array_column($byMonth, $numerator));
            $sumDenominator = array_sum(array_column($byMonth, $denominator));

            return ['cells' => $cells, 'total' => $sumDenominator > 0.0 ? round($sumNumerator / $sumDenominator * $multiplier, 2) : null];
        }

        $present = array_values(array_filter($cells, fn (?float $cell): bool => $cell !== null));

        return ['cells' => $cells, 'total' => $present === [] ? null : (float) ($isCumulative ? end($present) : array_sum($present))];
    }
}
