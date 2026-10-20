<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Desk;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Reactive;

/**
 * What every Desk panel component shares: where it sits on the Desk (handed
 * down by pages::desk.index, reactive so a resize or move re-renders without
 * remounting), its own page and filter, and the module checks the dashboard
 * query traits (Concerns/Dashboard) expect their host to provide.
 */
trait IsDeskPanel
{
    #[Reactive]
    public string $size = 'M';

    #[Reactive]
    public int $index = 0;

    #[Reactive]
    public int $total = 1;

    public int $page = 0;

    public string $filter = 'all';

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
     * @return array{slice: Collection<int, TItem>, page: int, pages: int, total: int, per: int, from: int, to: int}
     */
    protected function paginate(Collection $items, int $per): array
    {
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
        ];
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
