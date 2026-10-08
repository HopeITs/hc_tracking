<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Results;

use App\Enums\ResultType;
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
     *     rag: array{green: int, amber: int, red: int},
     *     rated: int,
     *     levels: array<string, array{statements: int, measured: int, indicators: int}>,
     * }
     */
    #[Computed]
    public function overview(): array
    {
        $levels = collect(ResultType::cases())
            ->mapWithKeys(fn (ResultType $case): array => [
                $case->value => ['statements' => 0, 'measured' => 0, 'indicators' => 0],
            ])
            ->all();

        $statements = 0;
        $measured = 0;
        $indicators = 0;
        $targets = 0;
        $reports = 0;
        $approved = 0;
        $funders = [];
        $rag = ['green' => 0, 'amber' => 0, 'red' => 0];
        $achievements = [];

        foreach ($this->visibleNodes as $row) {
            $result = $row['node']['result'];
            $measurement = $row['node']['measurement'];
            $ownIndicators = count($measurement['rows']);
            $level = $result->type->value;

            $statements++;
            $indicators += $ownIndicators;
            $targets += $measurement['targets'];
            $reports += $measurement['reports'];
            $approved += $measurement['approved'];
            $levels[$level]['statements']++;
            $levels[$level]['indicators'] += $ownIndicators;

            foreach ($measurement['funders'] as $funder) {
                $funders[$funder['label']] = $funder;
            }

            if ($ownIndicators > 0) {
                $measured++;
                $levels[$level]['measured']++;
            }

            foreach (array_keys($rag) as $band) {
                $rag[$band] += $measurement['rag'][$band];
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
            'rag' => $rag,
            'rated' => $rag['green'] + $rag['amber'] + $rag['red'],
            'levels' => $levels,
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
     * @return array{unmeasured: int, offtrack: int}
     */
    #[Computed]
    public function focusCounts(): array
    {
        $counts = ['unmeasured' => 0, 'offtrack' => 0];

        foreach ($this->allResults as $result) {
            // Every filter but the focus itself, so each chip counts what it would select.
            if (! $this->matchesFilters($result, '')) {
                continue;
            }

            $measurement = $this->measurements[$result->id];

            if ($measurement['rows'] === []) {
                $counts['unmeasured']++;
            }

            if ($measurement['rag']['amber'] + $measurement['rag']['red'] > 0) {
                $counts['offtrack']++;
            }
        }

        return $counts;
    }
}
