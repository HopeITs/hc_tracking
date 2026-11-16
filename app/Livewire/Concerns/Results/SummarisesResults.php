<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Results;

use App\Models\Result;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * Headline figures for the visible framework and the attention-chip counts.
 *
 * Used by results/index only. Reads the tree BuildsResultsTree builds, the
 * measurements BuildsResultsFramework loads, and filters through
 * BuildsResultsTree::matchesFilters().
 *
 * @property-read Collection<int, Result> $allResults
 * @property-read array<int, array<string, mixed>> $measurements
 * @property-read list<array{node: array<string, mixed>, program: string}> $visibleNodes
 */
trait SummarisesResults
{
    /**
     * Headline figures for the visible framework.
     *
     * @return array{
     *     statements: int,
     *     measured: int,
     *     indicators: int,
     *     targets: int,
     *     reports: int,
     *     approved: int,
     *     funders: list<array{label: string, type: string}>,
     *     coverage: float|null,
     *     achieved: float|null,
     * }
     */
    #[Computed]
    public function overview(): array
    {
        $statements = 0;
        $measured = 0;
        $indicators = 0;
        $targets = 0;
        $reports = 0;
        $approved = 0;
        $funders = [];
        $achievements = [];

        foreach ($this->visibleNodes as $row) {
            $result = $row['node']['result'];
            $measurement = $row['node']['measurement'];
            $ownIndicators = count($measurement['rows']);

            $statements++;
            $indicators += $ownIndicators;
            $targets += $measurement['targets'];
            $reports += $measurement['reports'];
            $approved += $measurement['approved'];

            foreach ($measurement['funders'] as $funder) {
                $funders[$funder['label']] = $funder;
            }

            if ($ownIndicators > 0) {
                $measured++;
            }

            if ($measurement['achieved'] !== null) {
                $achievements[] = $measurement['achieved'];
            }
        }

        return [
            'statements' => $statements,
            'measured' => $measured,
            'indicators' => $indicators,
            'targets' => $targets,
            'reports' => $reports,
            'approved' => $approved,
            'funders' => array_values($funders),
            'coverage' => $statements === 0 ? null : ($measured / $statements) * 100,
            'achieved' => $achievements === [] ? null : array_sum($achievements) / count($achievements),
        ];
    }

    /**
     * What each attention chip would select, counted under the OTHER filters
     * that are active.
     *
     * Counting across the whole table instead would have a chip read "2" while
     * the program filter leaves only one of them reachable — the number and
     * the page would disagree the moment anything else was narrowed.
     *
     * @return array{unmeasured: int, offtrack: int, waiting: int}
     */
    #[Computed]
    public function focusCounts(): array
    {
        $counts = ['unmeasured' => 0, 'offtrack' => 0, 'waiting' => 0];

        foreach ($this->allResults as $result) {
            // Every filter but the focus itself, so each chip counts what it would select.
            if (! $this->matchesFilters($result, '')) {
                continue;
            }

            $measurement = $this->measurements[$result->id];

            if ($measurement['rows'] === []) {
                $counts['unmeasured']++;
            }

            if ($measurement['status'] === 'behind') {
                $counts['offtrack']++;
            }

            if ($measurement['status'] === 'waiting') {
                $counts['waiting']++;
            }
        }

        return $counts;
    }
}
