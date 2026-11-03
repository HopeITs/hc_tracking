<?php

use App\Actions\Tasks\AcceptDepartmentTask;
use App\Enums\DeliveryState;
use App\Livewire\Concerns\Dashboard\ReadsDeskDecisions;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Task;
use Flux\Flux;
use Livewire\Component;

/**
 * Needs you: the decisions waiting on the viewer (ReadsDeskDecisions), with
 * who is waiting and for how long, and the button that settles each one.
 * The quick views are This week (default: late, due by the end of the
 * week, or undated), Late and All. Shown as Focus (a deck: one decision on top,
 * two peeking, a progress ring, ←/→ keys and swipe) or List.
 */
new class extends Component {
    use IsDeskPanel;
    use ReadsDeskDecisions;

    /**
     * Focus: one decision at a time, large. List: the fitted list. The
     * browser remembers the choice (localStorage) and restores it on load.
     */
    public string $view = 'focus';

    public function mount(): void
    {
        $this->filter = 'week';
    }

    public function setView(string $view): void
    {
        $this->view = $view === 'list' ? 'list' : 'focus';
        $this->page = 0;
    }

    /**
     * Accept a delivered deliverable from the row. The row is read back from
     * decisions(), and AcceptDepartmentTask re-checks the policy.
     */
    public function accept(int $taskId, AcceptDepartmentTask $accept): void
    {
        $row = $this->decisions->first(fn (array $item): bool => $item['kind'] === 'accept' && $item['taskId'] === $taskId);

        if ($row === null) {
            return;
        }

        $accept->handle(Task::query()->findOrFail($taskId), DeliveryState::Delivered);
        unset($this->decisions);
        Flux::toast(text: __('desk.decision_done.accept'), variant: 'success');
    }
};
?>

@php
    $all = $this->decisions;
    $list = $this->decisionsIn($filter);
    $focus = $view !== 'list';
    $pg = $this->paginate($list, self::PerPage);
    $slides = $list->take(60)->values();
    $today = \Carbon\CarbonImmutable::today();
@endphp

<x-desk.panel :facets="$pg['facets']" id="needs" class="dk-needs" :size="$size" :cols="$gridCols" :height="$gridRows" :index="$index" :total="$total" :count="$all->count()" :health="$all->isNotEmpty() ? 'brand' : null">
    <x-slot:tools>
        <div class="dk-seg" role="group">
            @foreach (['week' => __('desk.filter_week'), 'late' => __('desk.filter_late'), 'all' => __('desk.filter_all')] as $key => $label)
                <button type="button" wire:click="setFilter('{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="dk-seg dk-needs-view" role="group" aria-label="{{ __('desk-cards.needs.view') }}"
            x-data="{
                pick(view) { try { localStorage.setItem('dk-needs-view', view) } catch (e) {} $wire.setView(view) },
                init() { let saved = null; try { saved = localStorage.getItem('dk-needs-view') } catch (e) {} if (saved && saved !== @js($view)) { $wire.setView(saved) } },
            }">
            <button type="button" x-on:click="pick('focus')" aria-pressed="{{ $focus ? 'true' : 'false' }}">{{ __('desk-cards.needs.focus') }}</button>
            <button type="button" x-on:click="pick('list')" aria-pressed="{{ $focus ? 'false' : 'true' }}">{{ __('desk-cards.needs.list') }}</button>
        </div>
    </x-slot:tools>

    @if ($pg['total'] > 0 && $focus)
        {{-- Focus is a deck: the current decision on top, the next two peeking behind it; arrows, the ←/→ keys and swipe step through it. --}}
        <div class="dk-focus dk-deck" tabindex="0" role="group" aria-roledescription="carousel" aria-label="{{ __('desk.panel.needs.title') }}" title="{{ __('desk-cards.needs.keys') }}"
            wire:key="focus-{{ $filter }}-{{ $slides->count() }}"
            x-data="{
                at: 0,
                count: {{ $slides->count() }},
                startX: null,
                rtl() { return document.documentElement.dir === 'rtl' },
                depth(n) { return (n - this.at + this.count) % this.count },
                state(n) { const d = this.depth(n); if (d === 0) { return 'top' } if (d <= 2) { return 'peek' + d } return (this.count > 3 && d === this.count - 1) ? 'gone' : 'hidden' },
                go(n) { this.at = (n + this.count) % this.count },
                step(dir) { this.go(this.at + dir) },
                key(e, visualLeft) { this.step((visualLeft ? -1 : 1) * (this.rtl() ? -1 : 1)) },
                swipeEnd(e) { if (this.startX === null) { return } const dx = e.changedTouches[0].clientX - this.startX; this.startX = null; if (Math.abs(dx) > 40) { this.key(e, dx > 0) } },
            }"
            x-on:keydown.left.prevent="key($event, true)"
            x-on:keydown.right.prevent="key($event, false)"
            x-on:touchstart.passive="startX = $event.touches[0].clientX"
            x-on:touchend="swipeEnd($event)">
            <ol class="dk-focus-track dk-deck-track">
                @foreach ($slides as $n => $item)
                    @php
                        $waited = $item['since'] === null ? null : (int) $item['since']->startOfDay()->diffInDays($today);
                        $initial = $n === 0 ? 'top' : ($n <= 2 ? 'peek'.$n : 'hidden');
                    @endphp
                    <li wire:key="f-{{ $item['key'] }}" data-decision="{{ $item['kind'] }}" data-state="{{ $initial }}" x-bind:data-state="state({{ $n }})" @if ($n > 0) inert @endif x-bind:inert="depth({{ $n }}) !== 0" role="group" aria-roledescription="slide" aria-label="{{ __('desk-cards.needs.position', ['n' => $n + 1, 'total' => $slides->count()]) }}" @class(['dk-focus-card', 'dk-focus-late' => $item['late']])>
                        <p class="dk-focus-eyebrow">
                            <span @class(['dk-sev', 'dk-bad' => $item['late'], 'dk-warn' => ! $item['late']])></span>
                            {{ \Illuminate\Support\Facades\Lang::has('desk-cards.needs.kind.'.$item['kind']) ? __('desk-cards.needs.kind.'.$item['kind']) : $item['action'] }}
                            @if ($item['late'])<span class="dk-focus-latechip">{{ __('desk-cards.needs.late') }}</span>@endif
                        </p>
                        <h3 class="dk-focus-title" title="{{ $item['title'] }}">{{ $item['title'] }}</h3>
                        <p class="dk-focus-ctx" title="{{ $item['context'] }}">{{ $item['context'] }}</p>
                        <p class="dk-focus-meta">
                            @if ($item['who'] !== '')
                                <span class="dk-who">{{ $waited === null ? $item['who'] : trans_choice('desk.decision_waiting', $waited, ['who' => $item['who'], 'count' => $waited]) }}</span>
                            @elseif ($waited !== null)
                                <span>{{ trans_choice('desk.decision_waited', $waited, ['count' => $waited]) }}</span>
                            @endif
                            @if ($item['due'] !== null)
                                <span @class(['dk-bad' => $item['late']])>{{ __('desk.due_on', ['date' => $item['due']->translatedFormat(__('formats.date'))]) }}</span>
                            @endif
                        </p>
                        <div class="dk-acts dk-focus-acts">
                            @if ($item['kind'] === 'accept')
                                <button type="button" class="dk-btn dk-primary" wire:click="accept({{ $item['taskId'] }})">{{ $item['action'] }}</button>
                                <a href="{{ $item['href'] }}" wire:navigate class="dk-btn">{{ __('desk.open') }}</a>
                            @else
                                <a href="{{ $item['href'] }}" wire:navigate @class(['dk-btn', 'dk-primary' => $item['late']])>{{ $item['action'] }}</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
            @if ($slides->count() > 1)
                @php
                    $progressPhrase = \Illuminate\Support\Js::from(__('desk-cards.deck.progress', ['n' => '__N__', 'total' => $slides->count()]));
                @endphp
                <div class="dk-focus-nav">
                    <button type="button" class="dk-iconbtn dk-sm" x-on:click="step(-1)" aria-label="{{ __('desk-cards.needs.previous') }}"><x-desk.icon name="back" class="dk-flip" /></button>
                    <span class="dk-deck-ring" aria-hidden="true">
                        <svg viewBox="0 0 36 36"><circle cx="18" cy="18" r="15" pathLength="100" class="dk-deck-ring-bg" /><circle cx="18" cy="18" r="15" pathLength="100" class="dk-deck-ring-fg" style="stroke-dashoffset: {{ round(100 - 100 / $slides->count(), 2) }}" x-bind:style="'stroke-dashoffset:' + (100 - (at + 1) / count * 100)" /></svg>
                    </span>
                    <span class="dk-focus-pos dk-num" aria-live="polite"><b x-text="at + 1" aria-hidden="true">1</b><span class="dk-focus-of" aria-hidden="true">/ {{ $slides->count() }}</span><span class="sr-only" x-text="{{ $progressPhrase }}.replace('__N__', at + 1)">{{ __('desk-cards.deck.progress', ['n' => 1, 'total' => $slides->count()]) }}</span></span>
                    <button type="button" class="dk-iconbtn dk-sm" x-on:click="step(1)" aria-label="{{ __('desk-cards.needs.next') }}"><x-desk.icon name="chev" class="dk-flip" /></button>
                </div>
            @endif
        </div>
    @elseif ($pg['total'] > 0)
        <ol class="dk-items" {{ $this->fitAttributes($pg) }}>
            @foreach ($pg['slice'] as $item)
                @php
                    $waited = $item['since'] === null ? null : (int) $item['since']->startOfDay()->diffInDays($today);
                @endphp
                <li wire:key="{{ $item['key'] }}" data-decision="{{ $item['kind'] }}">
                    <span @class(['dk-sev', 'dk-bad' => $item['late'], 'dk-warn' => ! $item['late']])></span>
                    <div class="dk-im">
                        <p class="dk-it" title="{{ $item['title'] }}">{{ $item['title'] }}</p>
                        <p class="dk-is">{{ $item['context'] }}@if ($item['who'] !== '') · <span class="dk-who">{{ $waited === null ? $item['who'] : trans_choice('desk.decision_waiting', $waited, ['who' => $item['who'], 'count' => $waited]) }}</span>@elseif ($waited !== null) · {{ trans_choice('desk.decision_waited', $waited, ['count' => $waited]) }}@endif @if ($item['due'] !== null) · <span @class(['dk-bad' => $item['late']])>{{ __('desk.due_on', ['date' => $item['due']->translatedFormat(__('formats.date'))]) }}</span>@endif</p>
                    </div>
                    <div class="dk-acts">
                        @if ($item['kind'] === 'accept')
                            <button type="button" class="dk-btn dk-sm dk-primary" wire:click="accept({{ $item['taskId'] }})">{{ $item['action'] }}</button>
                            <a href="{{ $item['href'] }}" wire:navigate class="dk-btn dk-sm">{{ __('desk.open') }}</a>
                        @else
                            <a href="{{ $item['href'] }}" wire:navigate @class(['dk-btn dk-sm', 'dk-primary' => $item['late']])>{{ $item['action'] }}</a>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @elseif ($all->isEmpty())
        {{-- Nothing waits on the viewer at all: the one celebration on the desk (a check drawn in a ring). --}}
        <div class="dk-celebrate" role="status">
            <svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="28" pathLength="100" /><path d="M21 33l7.5 7.5L44 25" pathLength="100" /></svg>
            <p><strong>{{ __('desk-cards.deck.done_title') }}</strong><span class="dk-is">{{ __('desk-cards.deck.done_hint') }}</span></p>
        </div>
    @else
        <div class="dk-allclear">
            <span class="dk-allclear-dot" aria-hidden="true"></span>
            <p><strong>{{ __('desk-cards.deck.view_done') }}</strong><span class="dk-is">{{ __('desk.panel.needs.empty_view') }}</span></p>
        </div>
    @endif

    @if (! $focus)
        <x-slot:foot>
            <x-desk.pager :pg="$pg" />
        </x-slot:foot>
    @endif
</x-desk.panel>
