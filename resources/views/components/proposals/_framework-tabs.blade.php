{{--
    The Results chain as outcome tabs: the impact on top, one tab per
    outcome, and only the outcome in front below it, read top to bottom
    (statement, two folded lists, its outputs). The box keeps the same
    height whether the framework holds 3 outcomes or 30.

    Every panel is in the page and Alpine shows one, so switching a tab,
    a list or an output is instant; the choice syncs to the server
    (entangled) with the next request, and the server's first render
    already shows the right panel.
--}}
@php
    $totals = $this->subtreeTotals;
    $focusedId = $this->focusedId;
    $tabs = array_values(array_filter($this->rail, fn (array $entry): bool => in_array($entry['id'], $visible, true)));
    $impacts = array_values(array_filter($tabs, fn (array $entry): bool => $entry['heading']));
    $tabIds = array_column(array_values(array_filter($tabs, fn (array $entry): bool => ! $entry['heading'])), 'id');
    $tabShort = fn (string $text): string => \Illuminate\Support\Str::limit(rtrim($text, '.'), 60);
@endphp

<div class="pp-ot" data-test="framework-tabs" data-ids="{{ json_encode($tabIds) }}"
    x-data="{
        tab: $wire.entangle('focus'),
        child: $wire.entangle('openChild'),
        drawer: $wire.entangle('drawer'),
        cur() { const ids = JSON.parse(this.$root.dataset.ids); return ids.includes(this.tab) ? this.tab : ids[0] },
        go(id) { this.tab = id; this.child = null; this.drawer = '' },
        flip(name) { this.drawer = this.drawer === name ? '' : name },
        toggle(id) { this.child = this.child === id ? null : id },
    }">
    @foreach ($impacts as $entry)
        @php $impact = $fw['results'][$entry['id']]; @endphp
        <div @class(['pp-ot-impact', 'pp-issue' => $impact['issues'] !== []]) wire:key="impact-{{ $entry['id'] }}">
            <span class="pp-lvl pp-lvl-impact">{{ $impact['type']->label() }}</span>
            <span class="pp-ot-impact-t" dir="auto">{{ $impact['statement'] }}</span>
            <span class="pp-ot-meta">
                {{ trans_choice('proposals.framework.count_indicators', count($impact['indicators']), ['count' => count($impact['indicators'])]) }}
                @foreach ($impact['issues'] as $issue)
                    · <span class="pp-ot-bad">{{ $issue }}</span>
                @endforeach
            </span>
            @if ($canEdit)
                <button type="button" class="pp-btn pp-btn-ghost" wire:click="openEditModal({{ $entry['id'] }})" aria-label="{{ __('proposals.framework.edit_item', ['item' => $impact['statement']]) }}"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></button>
            @endif
        </div>
    @endforeach

    @if ($tabIds === [])
        <div class="pp-empty">{{ __('proposals.framework.no_outcomes_yet') }}</div>
    @else
        <div class="pp-ot-tabs" role="tablist" aria-label="{{ __('proposals.framework.tabs_label') }}">
            @foreach ($tabIds as $index => $tabId)
                @php $issues = $totals[$tabId]['issues'] ?? 0; @endphp
                <button type="button" role="tab" @class(['pp-ot-tab', 'pp-on' => $tabId === $focusedId]) x-bind:class="{ 'pp-on': cur() === {{ $tabId }} }" x-bind:aria-selected="cur() === {{ $tabId }}" aria-selected="{{ $tabId === $focusedId ? 'true' : 'false' }}" title="{{ $fw['results'][$tabId]['statement'] }}" x-on:click="go({{ $tabId }})" wire:key="tab-{{ $tabId }}" data-result-type="{{ $fw['results'][$tabId]['type']->value }}" data-test="outcome-tab-{{ $tabId }}">
                    <span class="pp-ot-n">{{ $index + 1 }}</span>
                    <span class="pp-ot-tt" dir="auto">{{ $tabShort($fw['results'][$tabId]['statement']) }}</span>
                    @if ($issues > 0)
                        <span class="pp-ot-badge" aria-label="{{ trans_choice('proposals.framework.sum_issues', $issues, ['count' => $issues]) }}">{{ $issues }}</span>
                    @else
                        <span class="pp-ot-ok">{!! $svg('check', 12) !!}</span>
                    @endif
                </button>
            @endforeach
        </div>

        @foreach ($tabIds as $position => $panelId)
            @include('components.proposals._framework-tab-panel')
        @endforeach
    @endif
</div>
