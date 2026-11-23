{{--
    One outcome tab's panel: statement, the two folded lists (indicators;
    risks and assumptions) and its outputs, each output folded. Alpine
    (x-data on _framework-tabs) shows it; the server hides every panel but
    the one in front on first paint. Expects $panelId and $position.
--}}
@php
    $current = $fw['results'][$panelId];
    $inFront = $panelId === $focusedId;
    $children = array_values(array_filter($fw['children'][$panelId] ?? [], fn (int $id): bool => in_array($id, $visible, true)));
    $raCount = count($current['risks']) + count($current['assumptions']);
    $raBad = collect($current['risks'])->where('happened', true)->count()
        + collect($current['assumptions'])->filter(fn (array $a): bool => $a['status'] !== \App\Enums\AssumptionStatus::Holds)->count();
    $childLabel = $current['type'] === \App\Enums\ResultType::Outcome ? 'output' : 'outcome';
    $hidden = fn (bool $shown): string => $shown ? '' : 'style="display: none"';
    $pen = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
    $bin = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>';
@endphp

<div class="pp-ot-body" role="tabpanel" x-show="cur() === {{ $panelId }}" {!! $hidden($inFront) !!} wire:key="panel-{{ $panelId }}" data-test="outcome-panel-{{ $panelId }}" id="n-{{ $panelId }}">
    <div class="pp-ot-head">
        <span class="pp-ot-meta">{{ __('proposals.framework.tab_of', ['type' => $current['type']->label(), 'n' => $position + 1, 'total' => count($tabIds)]) }}</span>
        @if ($canEdit)
            <span class="pp-ot-acts">
                <button type="button" class="pp-btn pp-btn-ghost" wire:click="openEditModal({{ $panelId }})" aria-label="{{ __('proposals.framework.edit_item', ['item' => $current['statement']]) }}">{!! $pen !!}</button>
                <button type="button" class="pp-btn pp-btn-ghost" wire:click="confirmDelete({{ $panelId }})" aria-label="{{ __('proposals.framework.delete_item', ['item' => $current['statement']]) }}">{!! $bin !!}</button>
            </span>
        @endif
    </div>
    <p class="pp-ot-st" dir="auto">{{ $current['statement'] }}</p>

    <div class="pp-ot-chips">
        @if ($layers['indicators'] ?? false)
            <button type="button" @class(['pp-ot-chip', 'pp-on' => $inFront && in_array($drawer, ['ind', 'all'], true)]) x-bind:class="{ 'pp-on': drawer === 'ind' || drawer === 'all' }" x-on:click="flip('ind')" x-bind:aria-expanded="drawer === 'ind' || drawer === 'all'" data-test="drawer-ind-{{ $panelId }}">
                {{ trans_choice('proposals.framework.count_indicators', count($current['indicators']), ['count' => count($current['indicators'])]) }}
            </button>
        @endif
        @if (($layers['risks'] ?? false) || ($layers['assumptions'] ?? false))
            <button type="button" @class(['pp-ot-chip', 'pp-on' => $inFront && in_array($drawer, ['ra', 'all'], true)]) x-bind:class="{ 'pp-on': drawer === 'ra' || drawer === 'all' }" x-on:click="flip('ra')" x-bind:aria-expanded="drawer === 'ra' || drawer === 'all'" data-test="drawer-ra-{{ $panelId }}">
                {{ trans_choice('proposals.framework.sum_ra', $raCount, ['count' => $raCount]) }}
                @if ($raBad > 0) · <span class="pp-ot-bad">{{ trans_choice('proposals.framework.sum_need_look', $raBad, ['count' => $raBad]) }}</span>@endif
            </button>
        @endif
        @foreach ($current['issues'] as $issue)
            <span class="pp-ot-bad pp-ot-issue">{{ $issue }}</span>
        @endforeach
    </div>

    @if ($layers['indicators'] ?? false)
        <div class="pp-ot-drawer" x-show="drawer === 'ind' || drawer === 'all'" {!! $hidden($inFront && in_array($drawer, ['ind', 'all'], true)) !!}>
            @include('components.proposals._framework-blocks', ['node' => $current, 'resultId' => $panelId, 'only' => ['indicators']])
        </div>
    @endif
    @if (($layers['risks'] ?? false) || ($layers['assumptions'] ?? false))
        <div class="pp-ot-drawer" x-show="drawer === 'ra' || drawer === 'all'" {!! $hidden($inFront && in_array($drawer, ['ra', 'all'], true)) !!}>
            @include('components.proposals._framework-blocks', ['node' => $current, 'resultId' => $panelId, 'only' => ['risks', 'assumptions']])
        </div>
    @endif

    @if ($current['type'] === \App\Enums\ResultType::Output)
        <div class="pp-ot-drawer">
            @include('components.proposals._framework-blocks', ['node' => $current, 'resultId' => $panelId, 'only' => ['activities']])
        </div>
    @else
        <div class="pp-ot-sub">
            <b>{{ __('proposals.framework.sum_'.$childLabel.'s') }}</b>
            <span class="pp-ot-meta">{{ count($children) }}</span>
            @if ($canEdit)
                <button type="button" class="pp-link pp-ot-add" wire:click="openCreateModal({{ $panelId }})" data-test="add-child-result-{{ $panelId }}">+ {{ __('proposals.framework.add_child_'.$childLabel) }}</button>
            @endif
        </div>

        @if ($children === [])
            <p class="pp-ot-meta">{{ __('proposals.framework.none_yet') }}</p>
        @else
            <ol class="pp-ot-outs">
                @foreach ($children as $childIndex => $childId)
                    @php
                        $child = $fw['results'][$childId];
                        $forced = in_array($childId, $openIds, true) || $search !== '';
                        $isOpen = $forced || ($inFront && $openChild === $childId);
                        $childIssues = $totals[$childId]['issues'] ?? 0;
                        $openExpr = $forced ? 'true' : 'child === '.$childId;
                    @endphp
                    <li @class(['pp-ot-out', 'pp-on' => $isOpen]) x-bind:class="{ 'pp-on': {{ $openExpr }} }" wire:key="out-{{ $childId }}" id="n-{{ $childId }}">
                        <div class="pp-ot-outh" role="button" tabindex="0" aria-expanded="{{ $isOpen ? 'true' : 'false' }}" x-bind:aria-expanded="{{ $openExpr }}" x-on:click="toggle({{ $childId }})" x-on:keydown.enter.prevent="toggle({{ $childId }})" x-on:keydown.space.prevent="toggle({{ $childId }})" data-test="node-toggle-{{ $childId }}">
                            <span class="pp-ot-num">{{ $position + 1 }}.{{ $childIndex + 1 }}</span>
                            <span class="pp-ot-outt" dir="auto">{{ $child['statement'] }}</span>
                            <span class="pp-ot-meta">
                                @if ($childIssues > 0)<span class="pp-ot-bad"><i class="pp-ot-dot" aria-hidden="true"></i>{{ trans_choice('proposals.framework.sum_issues', $childIssues, ['count' => $childIssues]) }}</span>@endif
                            </span>
                            @if ($canEdit)
                                <span class="pp-ot-acts" x-on:click.stop>
                                    <button type="button" class="pp-btn pp-btn-ghost" wire:click="openEditModal({{ $childId }})" aria-label="{{ __('proposals.framework.edit_item', ['item' => $child['statement']]) }}">{!! $pen !!}</button>
                                    <button type="button" class="pp-btn pp-btn-ghost" wire:click="confirmDelete({{ $childId }})" aria-label="{{ __('proposals.framework.delete_item', ['item' => $child['statement']]) }}">{!! $bin !!}</button>
                                </span>
                            @endif
                            <svg class="pp-ot-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                        </div>
                        <div class="pp-ot-outb" x-show="{{ $openExpr }}" {!! $hidden($isOpen) !!} data-test="node-body-{{ $childId }}" data-open="{{ $isOpen ? 'true' : 'false' }}">
                            @include('components.proposals._framework-blocks', ['node' => $child, 'resultId' => $childId, 'only' => null])
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    @endif

    <nav class="pp-ot-nav" aria-label="{{ __('proposals.framework.tabs_label') }}">
        @if ($position > 0)
            <button type="button" class="pp-link" x-on:click="go({{ $tabIds[$position - 1] }})" data-test="tab-prev"><span class="pp-flip">‹</span> {{ __('proposals.framework.tab_n', ['type' => $fw['results'][$tabIds[$position - 1]]['type']->label(), 'n' => $position]) }}</button>
        @else
            <span></span>
        @endif
        @if ($position < count($tabIds) - 1)
            <button type="button" class="pp-link" x-on:click="go({{ $tabIds[$position + 1] }})" data-test="tab-next">{{ __('proposals.framework.tab_n', ['type' => $fw['results'][$tabIds[$position + 1]]['type']->label(), 'n' => $position + 2]) }} <span class="pp-flip">›</span></button>
        @endif
    </nav>
</div>
