<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Results;

use App\Models\Result;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Livewire\Attributes\Computed;

/**
 * Filters the loaded framework and builds the visible tree from it: per-node
 * visibility, the hidden lineage of a re-rooted statement, nodes with their
 * rollups, the program groups and the flattened reading order.
 *
 * Used by results/index only. Reads the data BuildsResultsFramework loads and
 * the page's #[Url] filter properties; SummarisesResults reads what it builds.
 *
 * @property string $search
 * @property string $program
 * @property string $type
 * @property string $focus
 * @property-read Collection<int, Result> $allResults
 * @property-read array<int, Result> $resultsById
 * @property-read array<int, int> $childCounts
 * @property-read array<int, array<string, mixed>> $measurements
 * @property-read BaseCollection<int|string, array<int, array<string, mixed>>> $groupedRoots
 */
trait BuildsResultsTree
{
    /**
     * Whether one statement survives the current filters.
     *
     * The search reaches the indicators too: looking for "people reached"
     * should find the statement that measure hangs from, not nothing.
     */
    protected function matchesFilters(Result $result, ?string $focus = null): bool
    {
        $focus ??= $this->focus;

        if ($this->program === 'none' && $result->program_id !== null) {
            return false;
        }

        if ($this->program !== '' && $this->program !== 'none' && $result->program_id !== (int) $this->program) {
            return false;
        }

        if ($this->type !== '' && $result->type->value !== $this->type) {
            return false;
        }

        $measurement = $this->measurements[$result->id];

        if ($focus === 'unmeasured' && $measurement['rows'] !== []) {
            return false;
        }

        if ($focus === 'offtrack' && $measurement['rag']['amber'] + $measurement['rag']['red'] === 0) {
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
     * The visible framework as root nodes grouped by program name, with an
     * explicit Unassigned group last.
     *
     * A statement whose parent the filters hid is promoted to a root of its
     * own so nothing disappears silently; it carries the hidden lineage.
     *
     * @return BaseCollection<int|string, array<int, array<string, mixed>>>
     */
    #[Computed]
    public function groupedRoots(): BaseCollection
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

        $unassigned = __('common.unassigned');

        return collect($roots)
            ->map(fn (Result $result): array => $this->buildNode($result, $childrenByParent, $visibleIds, 0))
            ->groupBy(fn (array $node): string => $node['result']->program?->name ?? $unassigned)
            ->map(fn (BaseCollection $nodes): array => $nodes->all())
            ->sortKeysUsing(fn (string $left, string $right): int => match (true) {
                $left === $unassigned => 1,
                $right === $unassigned => -1,
                default => strcasecmp($left, $right),
            });
    }

    /**
     * Every visible node flattened into reading order, each tagged with the
     * program it sits under. Drives the matrix view and every headline figure.
     *
     * @return list<array{node: array<string, mixed>, program: string}>
     */
    #[Computed]
    public function visibleNodes(): array
    {
        $rows = [];

        $walk = function (array $node, string $program) use (&$walk, &$rows): void {
            $rows[] = ['node' => $node, 'program' => $program];

            foreach ($node['children'] as $child) {
                $walk($child, $program);
            }
        };

        foreach ($this->groupedRoots as $program => $nodes) {
            foreach ($nodes as $node) {
                $walk($node, (string) $program);
            }
        }

        return $rows;
    }
}
