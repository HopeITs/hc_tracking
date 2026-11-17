<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Programs;

use App\Enums\ResultType;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * The program page's Results tab: filter cards over the chain's indicators,
 * a search, a result-level switch, and result cards that fold open to show
 * their indicators a page at a time.
 *
 * Reads the page's chain() (ReadsProgramDelivery, App\Support\ResultsChain)
 * and reshapes it in memory: it never adds a query, so the chain keeps its
 * flat query count however many results the program holds.
 *
 * @property-read array<string, mixed> $chain
 */
trait ManagesProgramResults
{
    /** The indicator filters, in the order the stat cards show them; the first is the default. */
    public const RESULT_FILTERS = ['all', 'achieved', 'in_progress', 'not_started', 'no_target'];

    /** Indicators a result card lists before "Show more". */
    public const RESULT_INDICATOR_PAGE = 5;

    /** Indicators each "Show more" adds. */
    public const RESULT_INDICATOR_STEP = 10;

    #[Url(as: 'indicators', except: 'all')]
    public string $resultFilter = 'all';

    /** One result level shown as a flat list, or '' for the whole chain as a tree. */
    #[Url(as: 'level', except: '')]
    public string $resultLevel = '';

    public string $resultSearch = '';

    /**
     * How many indicators each opened card lists, keyed by result id.
     *
     * @var array<int, int>
     */
    public array $resultIndicatorLimits = [];

    /**
     * Pick an indicator filter, or go back to every indicator when the
     * picked one is already on.
     */
    public function pickResultFilter(string $filter): void
    {
        $filter = in_array($filter, self::RESULT_FILTERS, true) ? $filter : 'all';
        $this->resultFilter = $this->resultFilter === $filter ? 'all' : $filter;
        $this->resultIndicatorLimits = [];
    }

    public function pickResultLevel(string $level): void
    {
        $level = ResultType::tryFrom($level)?->value ?? '';
        $this->resultLevel = $this->resultLevel === $level ? '' : $level;
        $this->resultIndicatorLimits = [];
    }

    public function updatedResultFilter(): void
    {
        $this->resultFilter = in_array($this->resultFilter, self::RESULT_FILTERS, true) ? $this->resultFilter : 'all';
    }

    public function updatedResultLevel(): void
    {
        $this->resultLevel = ResultType::tryFrom($this->resultLevel)?->value ?? '';
    }

    public function updatedResultSearch(): void
    {
        $this->resultIndicatorLimits = [];
    }

    public function showMoreResultIndicators(int $resultId): void
    {
        $this->resultIndicatorLimits[$resultId] = $this->resultIndicatorLimit($resultId) + self::RESULT_INDICATOR_STEP;
    }

    public function showFewerResultIndicators(int $resultId): void
    {
        unset($this->resultIndicatorLimits[$resultId]);
    }

    public function resultIndicatorLimit(int $resultId): int
    {
        return max(self::RESULT_INDICATOR_PAGE, (int) ($this->resultIndicatorLimits[$resultId] ?? 0));
    }

    /**
     * The tab's whole picture: filter-card counts over every indicator in the
     * chain, the per-level counts, the cards to draw (a tree, or one level as
     * a flat list) and the chain-wide gaps.
     *
     * @return array{counts: array<string, int>, levels: array<string, int>, cards: list<array<string, mixed>>, narrowed: bool, searching: bool, notLinked: int}
     */
    #[Computed]
    public function resultsBoard(): array
    {
        $chain = $this->chain;
        $search = trim($this->resultSearch);
        $filter = in_array($this->resultFilter, self::RESULT_FILTERS, true) ? $this->resultFilter : 'all';
        $narrowed = $search !== '' || $filter !== 'all';

        $counts = array_fill_keys(self::RESULT_FILTERS, 0);
        $levels = array_fill_keys(array_map(fn (ResultType $type): string => $type->value, ResultType::cases()), 0);
        $notLinked = 0;

        $tally = function (array $node) use (&$tally, &$counts, &$levels, &$notLinked): void {
            $levels[$node['result']->type->value]++;

            foreach ($node['indicators'] as $row) {
                $counts['all']++;
                $counts[$this->indicatorState($row)]++;
                $notLinked += $row['linked'] ? 0 : 1;
            }

            foreach ([...$node['children'], ...$node['contributions']] as $child) {
                $tally($child);
            }
        };

        $roots = [...$chain['tree'], ...$chain['crossCutting']];

        foreach ($roots as $root) {
            $tally($root);
        }

        $cards = $this->resultCards($roots, $search, $filter, []);

        if ($this->resultLevel !== '') {
            $cards = $this->flattenToLevel($cards, $this->resultLevel);
        }

        return [
            'counts' => $counts,
            'levels' => $levels,
            'cards' => $cards,
            'narrowed' => $narrowed,
            'searching' => $search !== '',
            'notLinked' => $notLinked,
        ];
    }

    /**
     * Reshape chain nodes into cards: each card's indicators narrowed to the
     * search and filter, its average achievement, how many issues it and its
     * branch hold, and its children. Siblings with issues come first; a card
     * the search and filter leave empty (and its branch) is dropped.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<string>  $path  the statements above these nodes
     * @return list<array<string, mixed>>
     */
    protected function resultCards(array $nodes, string $search, string $filter, array $path): array
    {
        $cards = [];

        foreach ($nodes as $position => $node) {
            $result = $node['result'];
            $statementMatches = $search !== '' && Str::contains($result->statement, $search, ignoreCase: true);
            $below = [...$path, $result->statement];

            $children = $this->resultCards($node['children'], $search, $filter, $below);
            $contributions = $this->resultCards($node['contributions'], $search, $filter, $below);

            $rows = array_map(fn (array $row): array => $this->indicatorCardRow($row), $node['indicators']);
            $shown = array_values(array_filter($rows, fn (array $row): bool => ($filter === 'all' || $row['state'] === $filter)
                && ($search === '' || $statementMatches || $this->indicatorMatches($row, $search))));

            $keeps = match (true) {
                $search === '' && $filter === 'all' => true,
                $shown !== [] => true,
                $statementMatches && $filter === 'all' => true,
                default => $children !== [] || $contributions !== [],
            };

            if (! $keeps) {
                continue;
            }

            $issues = count($node['gaps'])
                + count(array_filter($rows, fn (array $row): bool => in_array($row['state'], ['no_target', 'not_started'], true)));
            $subtreeIssues = $issues
                + array_sum(array_column($children, 'subtreeIssues'))
                + array_sum(array_column($contributions, 'subtreeIssues'));
            $percents = array_values(array_filter(array_column($rows, 'progress'), fn (?int $value): bool => $value !== null));

            $cards[] = [
                'node' => $node,
                'depth' => $node['depth'],
                'position' => $position,
                'indicators' => $shown,
                'indicatorTotal' => count($rows),
                'progress' => $percents === [] ? null : (int) round(array_sum($percents) / count($percents)),
                'issues' => $issues,
                'subtreeIssues' => $subtreeIssues,
                'path' => $path,
                'children' => $children,
                'contributions' => $contributions,
            ];
        }

        usort($cards, fn (array $a, array $b): int => [$b['subtreeIssues'] > 0, $a['position']] <=> [$a['subtreeIssues'] > 0, $b['position']]);

        return $cards;
    }

    /**
     * Every card of one level, pulled out of the tree in order, without its
     * children (each still carries the statements above it).
     *
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    protected function flattenToLevel(array $cards, string $level): array
    {
        $flat = [];

        foreach ($cards as $card) {
            if ($card['node']['result']->type->value === $level) {
                $flat[] = [...$card, 'children' => [], 'contributions' => []];
            }

            array_push($flat, ...$this->flattenToLevel([...$card['children'], ...$card['contributions']], $level));
        }

        return $flat;
    }

    /**
     * A chain indicator row plus its state, average achievement over its
     * targets (each capped at 100%) and how many centers it is targeted in.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function indicatorCardRow(array $row): array
    {
        $percents = array_values(array_filter(array_column($row['targets'], 'percent'), fn (?float $value): bool => $value !== null));

        return [
            ...$row,
            'state' => $this->indicatorState($row),
            'progress' => $percents === [] ? null : (int) round(array_sum(array_map(fn (float $value): float => min(100.0, $value), $percents)) / count($percents)),
            'centers' => count(array_unique(array_map(fn (array $target): string => (string) $target['centerId'], $row['targets']))),
        ];
    }

    /**
     * An indicator's state across its targets: no target, every target
     * achieved, none started, or somewhere in between.
     *
     * @param  array<string, mixed>  $row
     */
    protected function indicatorState(array $row): string
    {
        $statuses = array_unique(array_column($row['targets'], 'status'));

        return match (true) {
            $statuses === [] => 'no_target',
            $statuses === ['achieved'] => 'achieved',
            $statuses === ['not_started'] => 'not_started',
            default => 'in_progress',
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function indicatorMatches(array $row, string $search): bool
    {
        return Str::contains($row['name'], $search, ignoreCase: true)
            || ($row['code'] !== null && Str::contains((string) $row['code'], $search, ignoreCase: true));
    }
}
