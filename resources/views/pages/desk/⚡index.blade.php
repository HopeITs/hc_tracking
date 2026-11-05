<?php

use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Dashboard\ReadsDashboardWork;
use App\Livewire\Concerns\Dashboard\ReadsDeskDecisions;
use App\Livewire\Concerns\Desk\ArrangesPanels;
use App\Support\Desk\Panels;
use App\Support\Desk\ReadyLayoutSignals;
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
 */
new #[Layout('layouts::desk'), Title('titles.dashboard')] class extends Component
{
    use ArrangesPanels;
    use ReadsDashboardAttention;
    use ReadsDashboardWork;
    use ReadsDeskDecisions;

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
     * The "Start something" buttons the viewer may use.
     *
     * @return list<array{key: string, label: string, icon: string, href: string}>
     */
    #[Computed]
    public function quickActions(): array
    {
        return app(Panels::class)->quickActionsFor(auth()->user());
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
     * @param  list<array{panel: string, size: string, cols: int, rows: int}>|null  $layout
     */
    protected function storeLayout(?array $layout): void
    {
        $user = auth()->user();
        $user->desk_layout = $layout === null ? null : array_values($layout);
        $user->save();
        unset($this->layout);
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
    $more = count(array_diff(array_keys($this->library), $placed));
    $needsPlaced = in_array('needs', $placed, true);
@endphp

<div data-desk x-data="{ removed: null, applied: null, timer: null }" x-on:dk-panel-removed="removed = $event.detail; applied = null; clearTimeout(timer); timer = setTimeout(() => removed = null, 6000)" x-on:dk-layout-applied="applied = $event.detail; removed = null; clearTimeout(timer); timer = setTimeout(() => applied = null, 8000)">
    <section class="dk-hello" data-desk-hello>
        <div class="dk-hello-main">
            <p class="dk-eyebrow">{{ now()->translatedFormat(__('formats.date_long')) }}</p>
            <h1 class="page-title">{{ rtrim($this->greeting(), '.!') }}</h1>
        </div>

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

        {{-- The command strip: the quick actions. Customize opens from the top bar's Panels button. --}}
        <div class="dk-start" data-desk-start>
            @if ($this->quickActions !== [])
                <div class="dk-start-row" role="group" aria-label="{{ __('desk.start_title') }}">
                    @foreach ($this->quickActions as $action)
                        <a href="{{ $action['href'] }}" wire:navigate class="dk-start-btn" data-desk-start-action="{{ $action['key'] }}">
                            <flux:icon :name="$action['icon']" variant="outline" class="dk-start-ic" /><span>{{ $action['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
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
    <div class="dk-grid" id="dk-grid" data-desk-grid>
        @foreach ($layout as $i => $entry)
            <livewire:is :component="'desk.panels.'.$entry['panel']" :size="$entry['size']" :grid-cols="$entry['cols'] ?? 0" :grid-rows="$entry['rows'] ?? 0" :index="$i" :total="count($layout)" :wire:key="'panel-'.$entry['panel']" />
        @endforeach
    </div>

    {{-- Outside the dense grid so it can never be backfilled into a gap: always the last card, and only while a panel is left to add. --}}
    @if ($more > 0)
        <div class="dk-grid dk-grid-tail">
            <button type="button" class="dk-addtile" x-on:click="$dispatch('dk-customize-open')" data-desk-add>
                <x-desk.icon name="plus" />
                <span>{{ __('desk.add_panel') }}</span>
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

    {{-- Undo for a removed panel, for ~6 seconds (undoRemove puts it back where it was). --}}
    <div class="dk-undo" role="status" aria-live="polite" x-show="removed" x-cloak x-transition.opacity data-desk-undo>
        <span x-text="removed ? {{ \Illuminate\Support\Js::from(__('desk.panel_removed', ['panel' => '__P__'])) }}.replace('__P__', removed.title) : ''"></span>
        <button type="button" class="dk-btn dk-sm" x-on:click="$wire.undoRemove(removed.panel, removed.position, removed.size, removed.cols ?? null, removed.rows ?? null); removed = null"><x-desk.icon name="undo" />{{ __('desk.undo') }}</button>
        <button type="button" class="dk-iconbtn dk-sm" x-on:click="removed = null" aria-label="{{ __('common.close') }}"><x-desk.icon name="x" /></button>
    </div>
</div>
