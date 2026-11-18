<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Reports;

use App\Enums\DenominatorBasis;
use App\Enums\DonorAttribution;
use App\Enums\IndicatorMeasure;
use App\Enums\MonthlyReportStatus;
use App\Support\Reporting\ReportExtraction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The by-sex breakdown rows of the Monthly Matrix (T18 slice 2, D-50,
 * contracts §14.1): male, female and their total, each with "of whom persons
 * with disability", read from `monthly_report_line_breakdowns` of validated
 * monthly reports only.
 *
 * Used by BuildsMonthlyMatrix. Two flat reads whatever the row count, both
 * over ReportExtraction's lines and shares (so the same filters and window):
 * the line-level rows (`shared` programs, by place) and the share-level rows
 * (`split` programs, by target and place). A ratio category is Σ numerator ÷ Σ denominator over the
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

        $shared = array_values(array_filter($disaggregated, fn (array $row): bool => $row['attribution'] !== DonorAttribution::Split));
        $split = array_values(array_filter($disaggregated, fn (array $row): bool => $row['attribution'] === DonorAttribution::Split));

        $lineLevel = $shared === [] ? [] : $this->lineLevelBreakdowns($shared);
        $shareLevel = $split === [] ? [] : $this->shareLevelBreakdowns($split);

        foreach ($rows as $index => $row) {
            if (! $row['is_disaggregated']) {
                continue;
            }

            $raw = [];

            if ($row['attribution'] === DonorAttribution::Split) {
                foreach ($isDonorView ? [$row['target_ids'][0]] : $row['target_ids'] as $targetId) {
                    foreach ($shareLevel[$targetId] ?? [] as $placeKey => $byPlace) {
                        if ($isDonorView || $placeKey === $row['place_key']) {
                            $raw = $this->addBreakdownRaw($raw, $byPlace);
                        }
                    }
                }
            } else {
                foreach ($row['place_keys'] as $placeKey) {
                    $raw = $this->addBreakdownRaw($raw, $lineLevel[$placeKey.'|'.$row['library_id']] ?? []);
                }
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
     * The line-level rows (share NULL) of the validated lines the extraction
     * selects, by `placeKey|library` then gender then month.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, array<string, array<string, float>>>>
     */
    private function lineLevelBreakdowns(array $rows): array
    {
        $lines = $this->extraction()->lines()
            ->where('mr.status', MonthlyReportStatus::Validated->value)
            ->whereIn('l.library_indicator_id', array_values(array_unique(array_column($rows, 'library_id'))));

        $breakdowns = DB::table('monthly_report_line_breakdowns as b')
            ->joinSub($lines, 'vl', 'vl.id', '=', 'b.monthly_report_line_id')
            ->whereNull('b.monthly_report_line_share_id')
            ->get(['vl.place_city_id', 'vl.place_program_id', 'vl.place_center_id', 'vl.place_city_wide', 'vl.period', 'vl.library_indicator_id', 'b.gender', ...$this->breakdownColumns()]);

        $result = [];

        foreach ($breakdowns as $line) {
            $this->foldBreakdown($result[$this->breakdownPlaceKey($line).'|'.$line->library_indicator_id], $line);
        }

        return $result;
    }

    /**
     * The share-level rows of the validated shares the extraction selects,
     * by target then place key then gender then month.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array<string, array<string, array<string, array<string, float>>>>>
     */
    private function shareLevelBreakdowns(array $rows): array
    {
        $shares = $this->extraction()->shares()
            ->where('mr.status', MonthlyReportStatus::Validated->value)
            ->whereIn('s.plan_indicator_id', array_values(array_unique(array_merge(...array_column($rows, 'target_ids')))));

        $breakdowns = DB::table('monthly_report_line_breakdowns as b')
            ->joinSub($shares, 'vs', 'vs.share_id', '=', 'b.monthly_report_line_share_id')
            ->get(['vs.plan_indicator_id', 'vs.place_city_id', 'vs.place_program_id', 'vs.place_center_id', 'vs.place_city_wide', 'vs.period', 'b.gender', ...$this->breakdownColumns()]);

        $result = [];

        foreach ($breakdowns as $share) {
            $this->foldBreakdown($result[(int) $share->plan_indicator_id][$this->breakdownPlaceKey($share)], $share);
        }

        return $result;
    }

    private function breakdownPlaceKey(stdClass $row): string
    {
        return ReportExtraction::placeKey((int) $row->place_city_id, (int) $row->place_program_id, $row->place_center_id === null ? null : (int) $row->place_center_id, (bool) $row->place_city_wide);
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
