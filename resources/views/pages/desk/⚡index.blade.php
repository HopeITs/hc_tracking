<?php

use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Dashboard\ReadsDashboardWork;
use App\Livewire\Concerns\Dashboard\ReadsDeskDecisions;
use App\Livewire\Concerns\Desk\ArrangesPanels;
use App\Support\Desk\Panels;
use App\Support\Desk\QuickAccess;
use App\Support\Desk\ReadyLayoutSignals;
use App\Support\Desk\Shortcuts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The Desk: one sentence that says what needs the viewer today, then the
 * panels they arranged (or their role's default) on a twelve-column grid.
 * Every change to the arrangement is saved on users.desk_layout at once.
 *
 * The grid holds two kinds of item: hand-made panels (their own components)
 * and page cards (desk.panels.page). Buttons ("New …" actions and page
 * buttons, Shortcuts) never land on it: however one is put on the Desk, it is
 * kept in Quick access instead (storeLayout). Pages opened from the Desk load
 * in Desk tabs above the grid (pages.desk._tabs).
 */
new #[Layout('layouts::desk'), Title('titles.dashboard')] class extends Component
{
    use ArrangesPanels {
        addItem as protected placeOnDesk;
        dropItem as protected dropOnDesk;
    }
    use ReadsDashboardAttention;
    use ReadsDashboardWork;
    use ReadsDeskDecisions;

    /**
     * A Desk saved while buttons could sit on it hands them to Quick access
     * once, and is saved again without them.
     */
    public function mount(): void
    {
        $user = auth()->user();
        $buttons = is_array($user->desk_layout) ? app(Panels::class)->buttonsIn($user->desk_layout) : [];

        if ($buttons === []) {
            return;
        }

        foreach ($buttons as $button) {
            app(QuickAccess::class)->addItem($user, $button);
        }

        $this->storeLayout($this->layout);
    }

    /**
     * @return list<array{panel: string, size: string, cols: int, rows: int}>
     */
    #[Computed]
    public function layout(): array
    {
        return app(Panels::class)->layoutFor(auth()->user());
    }

    /**
     * @return array<string, array{id: string, title: string, description: string, icon: string, size: string, register: string|null}>
     */
    #[Computed]
    public function library(): array
    {
        return app(Panels::class)->availableTo(auth()->user());
    }

    /**
     * What each sidebar page becomes when it is dragged onto the Desk
     * (resources/js/desk-drop.js): route => the item, its title and the
     * cell it starts at, and whether it is already on the Desk (a button:
     * already in Quick access).
     *
     * @return array<string, array{item: string, title: string, kind: string, cols: int, rows: int, placed: bool}>
     */
    #[Computed]
    public function dropItems(): array
    {
        $panels = app(Panels::class);
        $library = $this->library;
        $placed = array_flip([...array_column($this->layout, 'panel'), ...app(QuickAccess::class)->itemIds(auth()->user())]);
        $items = [];

        foreach ($panels->itemsForRoutes(auth()->user()) as $route => $id) {
            $cell = $panels->sized($id, $library[$id]['size']);
            $items[$route] = ['item' => $id, 'title' => (string) $library[$id]['title'], 'kind' => $library[$id]['kind'], 'cols' => $cell['cols'], 'rows' => $cell['rows'], 'placed' => isset($placed[$id])];
        }

        return $items;
    }

    /**
     * The ready layouts the viewer is offered, for the Customize sheet.
     *
     * @return list<array{key: string, title: string, purpose: string, core: string, entries: list<array{panel: string, size: string, cols: int, rows: int}>}>
     */
    #[Computed]
    public function readyLayouts(): array
    {
        return app(Panels::class)->readyLayoutsFor(auth()->user());
    }

    /**
     * Each offered layout's live signal and the suggestion they lead to, asked
     * for by the Customize sheet when it opens. Never part of a render.
     *
     * @return array{signals: array<string, array{text: string, tone: string, count: int}>, suggestion: array{key: string|null, reason: string|null}}
     */
    #[Renderless]
    public function readySignals(ReadyLayoutSignals $reader): array
    {
        $user = auth()->user();
        $signals = $reader->for($user, $this->needsCount);

        return ['signals' => $signals, 'suggestion' => $reader->suggestionFor($user, $signals)];
    }

    /**
     * How many decisions wait on the viewer, and how many of those are late,
     * for the greeting: the same rows as the Needs you panel's This week view.
     *
     * @return array{total: int, late: int}
     */
    #[Computed]
    public function needsCount(): array
    {
        $week = $this->decisionsIn('week');

        return ['total' => $week->count(), 'late' => $week->where('late', true)->count()];
    }

    /**
     * The pages and actions behind the Quick access button, in the viewer's order.
     *
     * @return list<array{key: string, kind: 'page'|'action', label: string, icon: string, href: string}>
     */
    #[Computed]
    public function quickAccess(): array
    {
        return app(QuickAccess::class)->for(auth()->user());
    }

    /** A page or action dropped on Quick access from the Ask bar (resources/js/desk-drop.js). */
    public function addQuickAccess(string $key): void
    {
        app(QuickAccess::class)->add(auth()->user(), $key);
        unset($this->quickAccess);
    }

    /**
     * Put a button (an `a-…` action or a `g-…` page, Shortcuts) in Quick
     * access: the Customize sheet's Buttons tab, a page dragged in from the
     * sidebar, the Ask bar. True when it is there afterwards.
     */
    public function addQuickItem(string $id): bool
    {
        $quick = app(QuickAccess::class);
        $wasThere = in_array($id, $quick->itemIds(auth()->user()), true);
        $added = $quick->addItem(auth()->user(), $id);
        unset($this->quickAccess);

        if ($added && ! $wasThere) {
            $this->dispatch('dk-item-placed', panel: $id, title: (string) ($this->library[$id]['title'] ?? ''), quick: true);
        }

        return $added;
    }

    /** The Undo of a button put in Quick access. */
    public function removeQuickItem(string $id): void
    {
        app(QuickAccess::class)->removeItem(auth()->user(), $id);
        unset($this->quickAccess);
    }

    /**
     * One item onto the Desk (ArrangesPanels::addItem), except a button,
     * which goes to Quick access.
     */
    public function addItem(string $panel, ?int $position = null): bool
    {
        return Shortcuts::isShortcut($panel) ? $this->addQuickItem($panel) : $this->placeOnDesk($panel, $position);
    }

    /**
     * A sidebar page dropped on the grid (ArrangesPanels::dropItem); a page
     * whose Desk item is a button goes to Quick access instead.
     *
     * @param  array<mixed>  $entries
     */
    public function dropItem(string $panel, array $entries): void
    {
        if (Shortcuts::isShortcut($panel)) {
            $this->addQuickItem($panel);

            return;
        }

        $this->dropOnDesk($panel, $entries);
    }

    public function removeQuickAccess(string $key): void
    {
        app(QuickAccess::class)->remove(auth()->user(), $key);
        unset($this->quickAccess);
    }

    /**
     * @param  array<mixed>  $keys
     */
    public function sortQuickAccess(array $keys): void
    {
        app(QuickAccess::class)->reorder(auth()->user(), $keys);
        unset($this->quickAccess);
    }

    /**
     * Every change to the Desk is saved here, so this is the one gate that
     * keeps buttons off it: any button in the new arrangement (a saved
     * Customize draft, an Undo, a drop) is put in Quick access and the rest
     * close up (Panels::flush).
     *
     * @param  list<array{panel: string, size: string, cols: int, rows: int}>|null  $layout
     */
    protected function storeLayout(?array $layout): void
    {
        $user = auth()->user();
        $panels = app(Panels::class);
        $buttons = $layout === null ? [] : $panels->buttonsIn($layout);

        if ($buttons !== []) {
            foreach ($buttons as $button) {
                app(QuickAccess::class)->addItem($user, $button);
            }

            unset($this->quickAccess);
            $layout = $panels->flush(array_values(array_filter($layout, fn (array $entry): bool => ! Shortcuts::isShortcut($entry['panel']))));
        }

        if ($layout === null) {
            // Back to the role's layout: nothing is pinned or folded any more.
            $user->desk_layout = null;
            $user->desk_panel_flags = null;
        } else {
            $flags = $panels->flagsFor($user);
            $placed = array_column($layout, 'panel');
            $pinned = array_values(array_intersect($flags['pinned'], $placed));
            $folded = array_values(array_intersect($flags['folded'], $placed));

            $user->desk_layout = $panels->pinnedFirst(array_values($layout), $pinned);
            $user->desk_panel_flags = $pinned === [] && $folded === [] ? null : ['pinned' => $pinned, 'folded' => $folded];
        }

        $user->save();
        unset($this->layout, $this->panelFlags);
    }

    /**
     * The panels on the Desk that are pinned to the top (in pin order) and
     * folded into tiles, for the panels' menus and desk-fold.js.
     *
     * @return array{pinned: list<string>, folded: list<string>}
     */
    #[Computed]
    public function panelFlags(): array
    {
        $flags = app(Panels::class)->flagsFor(auth()->user());
        $placed = array_column($this->layout, 'panel');

        return [
            'pinned' => array_values(array_intersect($flags['pinned'], $placed)),
            'folded' => array_values(array_intersect($flags['folded'], $placed)),
        ];
    }

    /**
     * A panel menu's Pin to top / Unpin: a pinned panel goes after the ones
     * pinned before it, ahead of everything else; unpinning lets it fall back
     * behind the pinned ones. Pinning a collapsed panel opens it too, so it
     * sits at the top as a full panel.
     */
    public function pinPanel(string $panel, bool $pinned = true): void
    {
        if ($pinned) {
            $user = auth()->user();
            $flags = app(Panels::class)->flagsFor($user);
            $user->desk_panel_flags = [...$flags, 'folded' => array_values(array_diff($flags['folded'], [$panel]))];
        }

        $this->storeFlag('pinned', $panel, $pinned);
    }

    /** A panel menu's Collapse / Expand: a folded panel shows as a small tile at the end of the Desk. */
    public function foldPanel(string $panel, bool $folded = true): void
    {
        $this->storeFlag('folded', $panel, $folded);
    }

    /**
     * @param  'pinned'|'folded'  $flag
     */
    private function storeFlag(string $flag, string $panel, bool $on): void
    {
        if (! in_array($panel, array_column($this->layout, 'panel'), true)) {
            return;
        }

        $user = auth()->user();
        $flags = app(Panels::class)->flagsFor($user);
        $flags[$flag] = $on
            ? array_values(array_unique([...$flags[$flag], $panel]))
            : array_values(array_diff($flags[$flag], [$panel]));
        $user->desk_panel_flags = $flags;

        $this->storeLayout($this->layout);
    }

    /**
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
};
?>

@php
    $layout = $this->layout;
    $needs = $this->needsCount;
    $n = $needs['total'];
    $placed = array_column($layout, 'panel');
    /* "More to add" counts panels and page cards: buttons go to Quick access, never onto the Desk. */
    $more = count(array_diff(array_keys(array_filter($this->library, fn (array $item): bool => in_array($item['kind'], ['panel', 'card'], true))), $placed));
    $needsPlaced = in_array('needs', $placed, true);
@endphp

<div data-desk x-data="{ removed: null, applied: null, placed: null, timer: null }" x-on:dk-panel-removed="removed = $event.detail; applied = null; placed = null; clearTimeout(timer); timer = setTimeout(() => removed = null, 6000)" x-on:dk-layout-applied="applied = $event.detail; removed = null; placed = null; clearTimeout(timer); timer = setTimeout(() => applied = null, 8000)" x-on:dk-item-placed.window="placed = $event.detail; removed = null; applied = null; clearTimeout(timer); timer = setTimeout(() => placed = null, 5000); $nextTick(() => document.getElementById('dk-panel-' + $event.detail.panel)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }))" x-on:dk-desk-changed.window="$wire.$refresh()">
    {{-- Pages opened from the Desk load as tabs here, the Desk itself staying the first tab. --}}
    @include('pages.desk._tabs')

    <section class="dk-hello" data-desk-hello>
        <div class="dk-hello-main">
            <p class="dk-eyebrow">{{ now()->translatedFormat(__('formats.date_long')) }}</p>
            <h1 class="page-title">{{ rtrim($this->greeting(), '.!') }}</h1>
        </div>

        <div class="dk-hello-end">
            {{-- The day in two figures, across from the greeting; they lead down to the Needs you panel when that is on the desk. --}}
            @if ($n === 0)
                <p class="dk-hello-clear">{{ __('desk.hello_none') }}</p>
            @else
                <{{ $needsPlaced ? 'a href=#dk-panel-needs' : 'div' }} class="dk-hello-figs" @if ($needsPlaced) title="{{ __('desk-cards.hero.needs_link') }}" @endif data-desk-hello-figures>
                    <span class="dk-hello-fig"><b class="dk-num page-title">{{ $n }}</b><span>{{ trans_choice('desk.hero_needs', $n) }}</span></span>
                    @if ($needs['late'] > 0)
                        <span class="dk-hello-fig dk-late"><b class="dk-num page-title text-brand-ink!">{{ $needs['late'] }}</b><span>{{ trans_choice('desk.hero_late', $needs['late']) }}</span></span>
                    @endif
                </{{ $needsPlaced ? 'a' : 'div' }}>
            @endif

            @include('pages.desk._quick-access', ['quick' => $this->quickAccess])
        </div>
    </section>

    {{-- First visit: one gentle hint, dismissed for good (kept in this browser). --}}
    <div class="dk-hint" x-data="deskHint" x-show="shown" x-cloak x-transition.opacity role="note" data-desk-hint>
        <span class="dk-live" aria-hidden="true"></span>
        <p>{{ __('desk-cards.edit.hint') }}</p>
        <button type="button" class="dk-btn dk-sm" x-on:click="dismiss()">{{ __('desk-cards.edit.got_it') }}</button>
    </div>


    {{-- Below 1280px a true row keeps together (desk-grid.css reads --span-r only there). --}}
    @foreach (app(Panels::class)->narrowSpansOf($layout) as $panel => $span)
        <style>#dk-panel-{{ $panel }} { --span-r: {{ $span }}; }</style>
    @endforeach
    {{-- What each sidebar page becomes when dragged onto the Desk (desk-drop.js). --}}
    <script type="application/json" data-desk-routes>@json($this->dropItems)</script>
    {{-- Panels pinned to the top and folded into tiles (resources/js/desk-fold.js). --}}
    <script type="application/json" data-desk-flags>@json($this->panelFlags)</script>
    <div class="dk-grid" id="dk-grid" data-desk-grid data-desk-drop data-drop-text="{{ __('desk-workspace.drop_here', ['item' => '__I__']) }}">
        @foreach ($layout as $i => $entry)
            @php
                $kind = $this->library[$entry['panel']]['kind'] ?? 'panel';
            @endphp
            @if ($kind === 'card')
                <livewire:desk.panels.page :card="$entry['panel']" :size="$entry['size']" :grid-cols="$entry['cols']" :grid-rows="$entry['rows']" :index="$i" :total="count($layout)" :wire:key="'panel-'.$entry['panel']" />
            @else
                <livewire:is :component="'desk.panels.'.$entry['panel']" :size="$entry['size']" :grid-cols="$entry['cols'] ?? 0" :grid-rows="$entry['rows'] ?? 0" :index="$i" :total="count($layout)" :wire:key="'panel-'.$entry['panel']" />
            @endif
        @endforeach
    </div>

    {{-- Outside the dense grid so it can never be backfilled into a gap: always the last card, and only while a panel is left to add. --}}
    @if ($more > 0)
        <div class="dk-grid dk-grid-tail">
            <button type="button" class="dk-addtile" x-on:click="$dispatch('dk-customize-open')" data-desk-add>
                <x-desk.icon name="plus" />
                <span>{{ __('desk.add_item') }}</span>
                <small>{{ trans_choice('desk.more_available', $more, ['count' => $more]) }}</small>
            </button>
        </div>
    @endif

    @include('pages.desk._customize-sheet')

    {{-- A ready layout was put on the desk: Undo saves what was there before, null for someone still on the role's layout. --}}
    <div class="dk-undo" role="status" aria-live="polite" x-show="applied" x-cloak x-transition.opacity data-desk-layout-applied>
        <span x-text="applied ? {{ \Illuminate\Support\Js::from(__('desk.ready.applied', ['layout' => '__L__'])) }}.replace('__L__', applied.title) : ''"></span>
        <button type="button" class="dk-btn dk-sm" x-on:click="$wire.saveLayout(applied.previous); applied = null" data-desk-layout-undo><x-desk.icon name="undo" />{{ __('desk.undo') }}</button>
        <button type="button" class="dk-iconbtn dk-sm" x-on:click="applied = null" aria-label="{{ __('common.close') }}"><x-desk.icon name="x" /></button>
    </div>

    {{-- Something was put on the Desk (dragged in from the sidebar, or added from the Ask bar or a page), or a button into Quick access: say what, with Undo. --}}
    <div class="dk-undo" role="status" aria-live="polite" x-show="placed" x-cloak x-transition.opacity data-desk-item-placed>
        <span x-text="placed ? (placed.quick ? {{ \Illuminate\Support\Js::from(__('desk-workspace.quick.placed', ['item' => '__I__'])) }} : {{ \Illuminate\Support\Js::from(__('desk-workspace.placed', ['item' => '__I__'])) }}).replace('__I__', placed.title) : ''"></span>
        <button type="button" class="dk-btn dk-sm" x-on:click="placed.quick ? $wire.removeQuickItem(placed.panel) : $wire.removePanel(placed.panel); placed = null"><x-desk.icon name="undo" />{{ __('desk.undo') }}</button>
        <button type="button" class="dk-iconbtn dk-sm" x-on:click="placed = null" aria-label="{{ __('common.close') }}"><x-desk.icon name="x" /></button>
    </div>

    {{-- Undo for a removed panel, for ~6 seconds (undoRemove puts it back where it was). --}}
    <div class="dk-undo" role="status" aria-live="polite" x-show="removed" x-cloak x-transition.opacity data-desk-undo>
        <span x-text="removed ? {{ \Illuminate\Support\Js::from(__('desk.panel_removed', ['panel' => '__P__'])) }}.replace('__P__', removed.title) : ''"></span>
        <button type="button" class="dk-btn dk-sm" x-on:click="$wire.undoRemove(removed.panel, removed.position, removed.size, removed.cols ?? null, removed.rows ?? null); removed = null"><x-desk.icon name="undo" />{{ __('desk.undo') }}</button>
        <button type="button" class="dk-iconbtn dk-sm" x-on:click="removed = null" aria-label="{{ __('common.close') }}"><x-desk.icon name="x" /></button>
    </div>
</div>
