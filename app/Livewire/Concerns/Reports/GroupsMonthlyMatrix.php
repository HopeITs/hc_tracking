<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Reports;

use App\Enums\IndicatorMeasure;
use App\Enums\MatrixCellState;
use Carbon\CarbonImmutable;

/**
 * The "group by indicator" mode of the Monthly Matrix (T18 slice 2, D-25,
 * contracts §14.1): the rows of one library entry across centers and donors
 * folded into one row.
 *
 * Used by BuildsMonthlyMatrix. The target, total and achieved % come from
 * ReportExtraction (libraryTotals() in the center view, a shared target
 * counted once; targetLibraryTotals() in the donor view): counts add up,
 * ratios pool (Σ numerator ÷ Σ denominator, lines without a denominator
 * excluded and counted), never an average of percentages. The month cells
 * fold the rows' own figures. A group of ratio rows with different targets
 * has no target (so no achieved %). `centers_count` = distinct places.
 */
trait GroupsMonthlyMatrix
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<CarbonImmutable>  $months
     * @return list<array<string, mixed>>
     */
    protected function groupRows(array $rows, array $months, bool $isDonorView): array
    {
        $groups = [];

        foreach (collect($rows)->groupBy('library_id') as $libraryId => $children) {
            $groups[] = $this->groupOf('group-'.$libraryId, $children->values()->all(), $months);
        }

        usort($groups, fn (array $a, array $b): int => strcmp((string) $a['code'], (string) $b['code']));

        $extraction = $this->extraction();
        $totals = $isDonorView ? $extraction->targetLibraryTotals() : $extraction->libraryTotals();

        foreach ($groups as $index => $group) {
            $figures = $totals[$group['library_id']] ?? null;

            if ($figures === null) {
                continue;
            }

            $groups[$index]['target'] = $figures['target'];
            $groups[$index]['total'] = $figures['total'];
            $groups[$index]['excluded'] = $figures['excluded'];
            $groups[$index]['achieved'] = $figures['achieved'];
        }

        return $groups;
    }

    /**
     * One group row from its (place, library) or target rows.
     *
     * @param  list<array<string, mixed>>  $children
     * @param  list<CarbonImmutable>  $months
     * @return array<string, mixed>
     */
    private function groupOf(string $key, array $children, array $months): array
    {
        $first = $children[0];
        $isNumeric = $first['measure'] === IndicatorMeasure::Numeric;
        $targets = array_column($children, 'target');

        $cells = [];
        foreach ($months as $month) {
            $cells[$month->format('Y-m')] = $this->groupCell(array_map(fn (array $child): array => $child['cells'][$month->format('Y-m')], $children), $first['measure']);
        }

        $raw = [];
        foreach ($children as $child) {
            $raw = $this->addBreakdownRaw($raw, $child['breakdown_raw']);
        }

        $sources = [];
        foreach ($children as $child) {
            foreach ($child['sources'] as $source) {
                $sources[json_encode($source)] = $source;
            }
        }

        return [
            ...$first,
            'key' => $key,
            'place_key' => null,
            'place_keys' => array_values(array_unique(array_merge(...array_column($children, 'place_keys')))),
            'center' => null,
            'city' => null,
            'shared_centers' => null,
            'attribution' => null,
            'is_group' => true,
            'centers_count' => count(array_unique(array_merge(...array_column($children, 'place_keys')))),
            'sources' => array_values($sources),
            'target_ids' => array_values(array_unique(array_merge(...array_column($children, 'target_ids')))),
            'target' => $isNumeric ? array_sum($targets) : (count(array_unique($targets)) === 1 ? $targets[0] : null),
            'cells' => $cells,
            'total' => $isNumeric ? (float) array_sum(array_filter(array_column($children, 'total'), fn (mixed $total): bool => $total !== null)) : null,
            'excluded' => 0,
            'achieved' => null,
            'breakdown_raw' => $raw,
            'breakdown' => $first['is_disaggregated']
                ? $this->finalizeBreakdown($raw, (string) $first['breakdown_kind'], $months, $first['measure'] === IndicatorMeasure::Percentage, $first['is_cumulative'])
                : null,
        ];
    }

    /**
     * One month of a group: the strongest state of its rows, and the figures
     * of the rows in that state added up (ratios from their numerators and
     * denominators).
     *
     * @param  list<array{state: MatrixCellState, value: ?float, numerator: ?float, denominator: ?float, not_measured: bool}>  $cells
     * @return array{state: MatrixCellState, value: ?float, numerator: ?float, denominator: ?float, not_measured: bool}
     */
    private function groupCell(array $cells, IndicatorMeasure $measure): array
    {
        $order = [MatrixCellState::Approved, MatrixCellState::Pending, MatrixCellState::NotSubmitted, MatrixCellState::Upcoming, MatrixCellState::NotDue, MatrixCellState::OutsidePeriod];
        $state = $order[min(array_map(fn (array $cell): int => (int) array_search($cell['state'], $order, true), $cells))];
        $used = array_values(array_filter($cells, fn (array $cell): bool => $cell['state'] === $state));
        $shown = in_array($state, [MatrixCellState::Approved, MatrixCellState::Pending], true);

        if (! $shown) {
            return ['state' => $state, 'value' => null, 'numerator' => null, 'denominator' => null, 'not_measured' => false];
        }

        if ($measure === IndicatorMeasure::Numeric) {
            $values = array_filter(array_column($used, 'value'), fn (mixed $value): bool => $value !== null);

            return [
                'state' => $state,
                'value' => $values === [] ? null : (float) array_sum($values),
                'numerator' => null,
                'denominator' => null,
                'not_measured' => $values === [] && array_filter($used, fn (array $cell): bool => $cell['not_measured']) !== [],
            ];
        }

        $measured = array_filter($used, fn (array $cell): bool => $cell['denominator'] !== null && $cell['denominator'] > 0.0);
        $numerator = array_sum(array_column($measured, 'numerator'));
        $denominator = array_sum(array_column($measured, 'denominator'));

        return [
            'state' => $state,
            'value' => $denominator > 0.0 ? round($numerator / $denominator * ($measure === IndicatorMeasure::Percentage ? 100 : 1), 2) : null,
            'numerator' => $denominator > 0.0 ? (float) $numerator : null,
            'denominator' => $denominator > 0.0 ? (float) $denominator : null,
            'not_measured' => $measured === [] && array_filter($used, fn (array $cell): bool => $cell['not_measured']) !== [],
        ];
    }
}
