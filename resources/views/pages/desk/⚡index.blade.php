<?php

use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Dashboard\ReadsDashboardWork;
use App\Livewire\Concerns\Dashboard\ReadsDeskDecisions;
use App\Livewire\Concerns\Desk\ArrangesPanels;
use App\Support\Desk\Panels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
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

<div data-desk x-data="{ removed: null, timer: null, editing: false }" x-bind:class="{ 'dk-desk-editing': editing }" x-on:keydown.escape.window="editing = false" x-on:dk-panel-removed="removed = $event.detail; clearTimeout(timer); timer = setTimeout(() => removed = null, 6000)">
    <section class="dk-hello" data-desk-hello>
        <div class="dk-hello-main">
            <p class="dk-eyebrow">{{ now()->translatedFormat(__('formats.date_long')) }} · {{ auth()->user()->name }}</p>
            {{-- The briefing: the counts link down to the Needs you panel when it is on the desk. --}}
            <h1>{{ rtrim($this->greeting(), '.!') }}{{ __('desk.sentence_end') }}
                @if ($n === 0)
                    {{ __('desk.hello_none') }}
                @elseif ($needsPlaced)
                    <a href="#dk-panel-needs" class="dk-hello-link" title="{{ __('desk-cards.hero.needs_link') }}">{!! trans_choice('desk.hello_count', $n, ['count' => $n]) !!}</a>
                @else
                    {!! trans_choice('desk.hello_count', $n, ['count' => $n]) !!}
                @endif
            </h1>
            <p class="dk-lede">
                @if ($n === 0)
                    {{ __('desk.lede_done') }}
                @elseif ($needs['late'] > 0 && $needsPlaced)
                    <a href="#dk-panel-needs" class="dk-hello-link dk-late" title="{{ __('desk-cards.hero.late_link') }}">{{ trans_choice('desk.lede_late', $needs['late'], ['count' => $needs['late']]) }}</a>
                @elseif ($needs['late'] > 0)
                    {{ trans_choice('desk.lede_late', $needs['late'], ['count' => $needs['late']]) }}
                @else
                    {{ __('desk.lede_none_late') }}
                @endif
            </p>
        </div>

        {{-- The command strip: the quick actions, and Edit desk (shows the grid cells and every handle). --}}
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
            <button type="button" class="dk-edit-toggle" x-on:click="editing = ! editing" x-bind:aria-pressed="editing.toString()" aria-pressed="false" data-desk-edit>
                <flux:icon name="adjustments-horizontal" variant="outline" class="dk-start-ic" />
                <span x-show="! editing">{{ __('desk-cards.edit.start') }}</span>
                <span x-show="editing" x-cloak>{{ __('desk-cards.edit.done') }}</span>
            </button>
        </div>
    </section>

    {{-- First visit: one gentle hint, dismissed for good (kept in this browser). --}}
    <div class="dk-hint" x-data="deskHint" x-show="shown" x-cloak x-transition.opacity role="note" data-desk-hint>
        <span class="dk-live" aria-hidden="true"></span>
        <p>{{ __('desk-cards.edit.hint') }}</p>
        <button type="button" class="dk-btn dk-sm" x-on:click="dismiss()">{{ __('desk-cards.edit.got_it') }}</button>
    </div>

    <p class="dk-edit-bar" x-show="editing" x-cloak x-transition.opacity role="status">{{ __('desk-cards.edit.bar') }}</p>

    @foreach ($layout as $entry)
        @if (isset($entry['col']))
            <style>#dk-panel-{{ $entry['panel'] }} { --col: {{ $entry['col'] }}; }</style>
        @endif
    @endforeach
    <div class="dk-grid" id="dk-grid" data-desk-grid x-bind:class="{ 'dk-editing': editing }">
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

    {{-- Undo for a removed panel, for ~6 seconds (undoRemove puts it back where it was). --}}
    <div class="dk-undo" role="status" aria-live="polite" x-show="removed" x-cloak x-transition.opacity data-desk-undo>
        <span x-text="removed ? {{ \Illuminate\Support\Js::from(__('desk.panel_removed', ['panel' => '__P__'])) }}.replace('__P__', removed.title) : ''"></span>
        <button type="button" class="dk-btn dk-sm" x-on:click="$wire.undoRemove(removed.panel, removed.position, removed.size, removed.cols ?? null, removed.rows ?? null); removed = null"><x-desk.icon name="undo" />{{ __('desk.undo') }}</button>
        <button type="button" class="dk-iconbtn dk-sm" x-on:click="removed = null" aria-label="{{ __('common.close') }}"><x-desk.icon name="x" /></button>
    </div>
</div>
