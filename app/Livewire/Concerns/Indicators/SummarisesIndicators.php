<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Indicators;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection as BaseCollection;
use Livewire\Attributes\Computed;

/**
 * The figures above the indicator list: the headline overview and how many
 * indicators each attention chip would show.
 *
 * Used by indicators/index only. The rows and matchesFilters() come from
 * BuildsIndicatorRows.
 *
 * @property-read array<int, array<string, mixed>> $rows  #[Computed] rows()
 * @property-read BaseCollection<int, array<string, mixed>> $filteredRows  #[Computed] filteredRows()
 */
trait SummarisesIndicators
{
    /**
     * The figures above the list: how much is being measured, how recently,
     * how far along it is, and how the ratings split.
     *
     * Counted across every indicator the filters leave, so the headline always
     * describes what is on screen rather than the whole database.
     *
     * Targets are counted achieved or not rather than summed: they span
     * different indicators, units and measures, so adding their values
     * together would produce a number that means nothing.
     *
     * @return array{indicators: int, results: int, readings: int, lastReading: CarbonImmutable|null, achieved: float|null, rated: int, rag: array<string, int>, unrated: int, targets: int, targetsAchieved: int, reports: int, approved: int, overdueReports: int, funders: list<array{label: string, type: string}>, elapsed: float}
     */
    #[Computed]
    public function overview(): array
    {
        $rows = $this->filteredRows;
        $rag = ['green' => 0, 'amber' => 0, 'red' => 0];
        $achievements = [];
        $readings = 0;
        $targets = 0;
        $targetsAchieved = 0;
        $reports = 0;
        $approved = 0;
        $overdueReports = 0;
        $unrated = 0;
        $funders = [];
        $lastReading = null;

        foreach ($rows as $row) {
            $readings += $row['readings'];
            $targets += count($row['targets']);
            $targetsAchieved += $row['targetsAchieved'];
            $reports += $row['reports'];
            $approved += $row['approved'];
            $overdueReports += $row['overdueReports'];

            if ($row['rag'] === null) {
                $unrated++;
            } else {
                $rag[$row['rag']->value]++;
            }

            if ($row['achieved'] !== null) {
                $achievements[] = $row['achieved'];
            }

            if ($row['latestDate'] !== null && ($lastReading === null || $row['latestDate']->greaterThan($lastReading))) {
                $lastReading = $row['latestDate'];
            }

            foreach ($row['funders'] as $funder) {
                $funders[$funder['label']] = $funder;
            }
        }

        $start = now()->startOfYear();
        $end = now()->endOfYear();
        $elapsed = (now()->getTimestamp() - $start->getTimestamp()) / max($end->getTimestamp() - $start->getTimestamp(), 1) * 100;

        return [
            'indicators' => $rows->count(),
            'results' => $rows->pluck('indicator.result_id')->unique()->count(),
            'readings' => $readings,
            'lastReading' => $lastReading,
            'achieved' => $achievements === [] ? null : array_sum($achievements) / count($achievements),
            'rated' => $rag['green'] + $rag['amber'] + $rag['red'],
            'rag' => $rag,
            'unrated' => $unrated,
            'targets' => $targets,
            'targetsAchieved' => $targetsAchieved,
            'reports' => $reports,
            'approved' => $approved,
            'overdueReports' => $overdueReports,
            'funders' => array_values($funders),
            'elapsed' => min(max($elapsed, 0.0), 100.0),
        ];
    }

    /**
     * How many indicators each attention chip would show.
     *
     * Counted with that chip held and the others released, so a chip never
     * promises a number the click cannot deliver.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function focusCounts(): array
    {
        $counts = [];

        foreach (['offtrack', 'stale', 'reporting', 'unmeasured', 'untargeted'] as $focus) {
            $counts[$focus] = collect($this->rows)
                ->filter(fn (array $row): bool => $this->matchesFilters($row, $focus))
                ->count();
        }

        return $counts;
    }
}
