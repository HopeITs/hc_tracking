<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Results;

use App\Enums\ResultType;
use App\Models\Result;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Livewire\Attributes\Computed;

/**
 * Filters the loaded framework and builds the visible tree from it: per-node
 * visibility, the hidden lineage of a re-rooted statement, nodes with their
 * rollups, the program groups and the flattened reading order.
 *
 * Used by results/index and the proposal Logical Framework tab (which has no
 * filters and overrides matchesFilters()). Reads the data
 * BuildsResultsFramework loads and the page's #[Url] filter properties.
 *
 * @property string $search
 * @property string $program
 * @property-read Collection<int, Result> $allResults
 * @property-read array<int, Result> $resultsById
 * @property-read array<int, int> $childCounts
 * @property-read array<int, array<string, mixed>> $measurements
 * @property-read BaseCollection<int|string, array<int, array<string, mixed>>> $groupedRoots
 */
trait BuildsResultsTree
{
    /**
     * Whether one statement survives the current filters: the program and
     * the search.
     *
     * The search reaches the indicators too: looking for "people reached"
     * should find the statement that measure hangs from, not nothing.
     */
    protected function matchesFilters(Result $result): bool
    {
        if ($this->program === 'none' && $result->program_id !== null) {
            return false;
        }

        if ($this->program !== '' && $this->program !== 'none' && $result->program_id !== (int) $this->program) {
            return false;
        }

        $search = trim($this->search);

        if ($search === '') {
            return true;
        }

        if (str_contains(mb_strtolower($result->statement), mb_strtolower($search))) {
            return true;
        }

        return $result->indicators->contains(
            fn ($indicator): bool => str_contains(mb_strtolower($indicator->name), mb_strtolower($search))
        );
    }

    /**
     * The statements above this one that the filters hid, outermost first.
     *
     * A statement re-rooted because its parent was filtered out still has to
     * say what it contributes to, or the chain reads as a flat list.
     *
     * @param  array<int, true>  $visibleIds
     * @return list<Result>
     */
    protected function hiddenLineage(Result $result, array $visibleIds): array
    {
        $lineage = [];
        $parentId = $result->parent_id;

        while ($parentId !== null && ! isset($visibleIds[$parentId])) {
            $parent = $this->resultsById[$parentId] ?? null;

            if ($parent === null) {
                break;
            }

            array_unshift($lineage, $parent);
            $parentId = $parent->parent_id;
        }

        return $lineage;
    }

    /**
     * Build one node: the statement, its measurement, its rollup and its
     * visible children.
     *
     * @param  array<int, list<Result>>  $childrenByParent
     * @param  array<int, true>  $visibleIds
     * @return array<string, mixed>
     */
    protected function buildNode(Result $result, array $childrenByParent, array $visibleIds, int $depth): array
    {
        $children = [];

        foreach ($childrenByParent[$result->id] ?? [] as $child) {
            $children[] = $this->buildNode($child, $childrenByParent, $visibleIds, $depth + 1);
        }

        $measurement = $this->measurements[$result->id];
        $ownIndicators = count($measurement['rows']);

        $rollup = [
            'statements' => 1,
            'indicators' => $ownIndicators,
            'targets' => $measurement['targets'],
            'reports' => $measurement['reports'],
            'unmeasured' => $ownIndicators === 0 ? 1 : 0,
        ];

        foreach ($children as $child) {
            foreach (array_keys($rollup) as $key) {
                $rollup[$key] += $child['rollup'][$key];
            }
        }

        return [
            'result' => $result,
            'depth' => $depth,
            'deletable' => ($this->childCounts[$result->id] ?? 0) === 0 && $ownIndicators === 0,
            'measurement' => $measurement,
            'children' => $children,
            'rollup' => $rollup,
            'lineage' => $depth === 0 ? $this->hiddenLineage($result, $visibleIds) : [],
        ];
    }

    /**
     * The visible framework as root nodes grouped by program name (an
     * explicit Unassigned group after the named ones), then one group per
     * proposal framework.
     *
     * @return BaseCollection<int|string, array<int, array<string, mixed>>>
     */
    #[Computed]
    public function groupedRoots(): BaseCollection
    {
        $unassigned = __('common.unassigned');

        /*
         * Program groups first (Unassigned last among them), then one group
         * per proposal framework, each sorted by name.
         */
        $groupRank = fn (array $node): array => match (true) {
            $node['result']->proposal_id !== null => [2, $node['result']->proposal->name],
            $node['result']->program === null => [1, ''],
            default => [0, (string) $node['result']->program->name],
        };

        return collect($this->rootNodes())
            ->sortBy([
                fn (array $left, array $right): int => $groupRank($left)[0] <=> $groupRank($right)[0],
                fn (array $left, array $right): int => strcasecmp($groupRank($left)[1], $groupRank($right)[1]),
            ])
            ->groupBy(fn (array $node): string => match (true) {
                $node['result']->proposal_id !== null => __('results_framework.proposal_group', ['name' => $node['result']->proposal->name]),
                default => $node['result']->program?->name ?? $unassigned,
            })
            ->map(fn (BaseCollection $nodes): array => $nodes->values()->all());
    }

    /**
     * The visible framework as root nodes: the chain, then the free
     * activities (no parent), then the cross-cutting roots.
     *
     * A statement whose parent the filters hid is promoted to a root of its
     * own so nothing disappears silently; it carries the hidden lineage.
     *
     * @return list<array<string, mixed>>
     */
    protected function rootNodes(): array
    {
        $visible = $this->allResults->filter(fn (Result $result): bool => $this->matchesFilters($result));
        $visibleIds = $visible->mapWithKeys(fn (Result $result): array => [$result->id => true])->all();

        $childrenByParent = [];
        $roots = [];

        foreach ($visible as $result) {
            if ($result->parent_id !== null && isset($visibleIds[$result->parent_id])) {
                $childrenByParent[$result->parent_id][] = $result;

                continue;
            }

            $roots[] = $result;
        }

        return array_values(collect($roots)
            ->sortBy(fn (Result $result): int => match ($result->type) {
                ResultType::Activity => 1,
                ResultType::CrossCutting => 2,
                default => 0,
            })
            ->map(fn (Result $result): array => $this->buildNode($result, $childrenByParent, $visibleIds, 0))
            ->all());
    }
}
