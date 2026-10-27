<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Desk;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Livewire\Attributes\Reactive;

/**
 * What every Desk panel component shares: where it sits on the Desk (handed
 * down by pages::desk.index, reactive so a resize or move re-renders without
 * remounting), its own page and filter, and the module checks the dashboard
 * query traits (Concerns/Dashboard) expect their host to provide.
 */
trait IsDeskPanel
{
    /**
     * Rows per panel page: a panel shows three to four rows and pages the rest, never scrolls.
     */
    public const int PerPage = 4;

    #[Reactive]
    public string $size = 'M';

    #[Reactive]
    public int $index = 0;

    #[Reactive]
    public int $total = 1;

    public int $page = 0;

    public string $filter = 'all';

    /**
     * Dropdown filters chosen in the panel's filter popup, facet => value.
     *
     * @var array<string, string>
     */
    public array $facets = [];

    public function updatedFacets(): void
    {
        $this->page = 0;
    }

    /**
     * "Done" in the filter popup: the draft choices arrive with this call, so
     * the list starts again from its first page.
     */
    public function applyFilters(): void
    {
        $this->page = 0;
    }

    public function clearFilters(): void
    {
        $this->facets = [];
        $this->filter = 'all';
        $this->page = 0;
    }

    public function previousPage(): void
    {
        $this->page = max(0, $this->page - 1);
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->page = 0;
    }

    /**
     * One page of the list, clamping the page to the last one that exists.
     *
     * @template TItem
     *
     * @param  Collection<int, TItem>  $items
     * @return array{slice: Collection<int, TItem>, page: int, pages: int, total: int, per: int, from: int, to: int, facets: array<string, array{label: string, options: list<string>, value: string}>}
     */
    protected function paginate(Collection $items, int $per): array
    {
        $facets = $this->facetOptions($items);
        $items = $this->matchingFacets($items);
        $total = $items->count();
        $pages = max(1, (int) ceil($total / $per));
        $this->page = min($this->page, $pages - 1);
        $from = $this->page * $per;

        return [
            'slice' => $items->slice($from, $per)->values(),
            'page' => $this->page,
            'pages' => $pages,
            'total' => $total,
            'per' => $per,
            'from' => $from + 1,
            'to' => min($total, $from + $per),
            'facets' => $facets,
        ];
    }

    /**
     * What a row can be filtered by, read from the row itself: a model's
     * loaded parent records by name (project, donor, program…) and its enum
     * columns by label (status, kind…); an array row's group/type/currency.
     * Panels may override this for sharper facets.
     *
     * @return array<string, string>
     */
    protected function facetsOf(mixed $row): array
    {
        $facets = [];

        if ($row instanceof Model) {
            foreach ($row->getRelations() as $relation => $related) {
                $name = $related instanceof Model ? ($related->getAttributes()['name'] ?? null) : null;

                if (is_string($name) && $name !== '') {
                    $facets[$relation] = $name;
                }
            }

            foreach (array_intersect_key($row->getCasts(), $row->getAttributes()) as $attribute => $cast) {
                $value = $row->getAttribute($attribute);

                if ($value instanceof \BackedEnum) {
                    $facets[$attribute] = method_exists($value, 'label') ? (string) $value->label() : (string) $value->value;
                }
            }

            return $facets;
        }

        if (is_array($row)) {
            foreach (['group', 'type', 'kind', 'currency', 'module', 'tone'] as $key) {
                if (isset($row[$key]) && is_string($row[$key]) && $row[$key] !== '') {
                    $facets[$key] = $row[$key];
                }
            }

            if (isset($row['key']) && is_string($row['key']) && str_contains($row['key'], '-')) {
                $facets['type'] ??= Str::before($row['key'], '-');
            }

            // Codes (task, delivery, funding…) read as words.
            foreach ($facets as $facet => $value) {
                $facets[$facet] = Lang::has("desk.facet_value.{$value}") ? __("desk.facet_value.{$value}") : $value;
            }
        }

        return $facets;
    }

    /**
     * Each facet with two or more distinct values among the rows, and those values.
     *
     * @param  Collection<int, mixed>  $items
     * @return array<string, array{label: string, options: list<string>, value: string}>
     */
    private function facetOptions(Collection $items): array
    {
        $values = [];

        foreach ($items as $row) {
            foreach ($this->facetsOf($row) as $facet => $value) {
                $values[$facet][$value] = true;
            }
        }

        $options = [];

        foreach ($values as $facet => $seen) {
            if (count($seen) < 2 && ! isset($this->facets[$facet])) {
                continue;
            }

            $list = array_map('strval', array_keys($seen));
            sort($list, SORT_NATURAL | SORT_FLAG_CASE);
            $options[$facet] = [
                'label' => Lang::has('desk.facet.'.$facet) ? __('desk.facet.'.$facet) : Str::headline($facet),
                'options' => $list,
                'value' => (string) ($this->facets[$facet] ?? ''),
            ];
        }

        return $options;
    }

    /**
     * @template TItem
     *
     * @param  Collection<int, TItem>  $items
     * @return Collection<int, TItem>
     */
    private function matchingFacets(Collection $items): Collection
    {
        $chosen = array_filter($this->facets, fn (mixed $value): bool => is_string($value) && $value !== '');

        if ($chosen === []) {
            return $items;
        }

        return $items->filter(function (mixed $row) use ($chosen): bool {
            $facets = $this->facetsOf($row);

            foreach ($chosen as $facet => $value) {
                if (($facets[$facet] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * Keep a query empty unless the viewer may view its module.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function visible(Builder $query, string $module): Builder
    {
        return $this->canView($module) ? $query : $query->whereRaw('1 = 0');
    }

    protected function canView(string $module): bool
    {
        return auth()->user()?->can("{$module}.view") === true;
    }

    protected function canViewFundingPosition(): bool
    {
        return $this->canView('funding-agreements') && $this->canView('transfers');
    }
}
