{{--
    The editable lists under one result (indicators, activities,
    assumptions; risks sit on the whole framework, _framework-risks), shared by the tree node and the outcome tabs. Expects
    $node, $resultId and $only (null for every layer the person shows, or
    the layers to keep).

    With every layer shown, the three small lists sit side by side as cards
    of one height: each lists its first four rows (fixed height, empty rows
    kept as dashed slots) and "Show all" reveals the rest.
--}}
@php
    $cap = $only === null ? 4 : null;
    $number = fn ($value): string => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $showIndicators = ($only === null || in_array('indicators', $only, true)) && ($layers['indicators'] ?? false);
    $showActivities = ($only === null || in_array('activities', $only, true)) && ($layers['activities'] ?? false) && $node['type'] === \App\Enums\ResultType::Output;
    $showAssumptions = ($only === null || in_array('assumptions', $only, true)) && ($layers['assumptions'] ?? false);
@endphp
<div @class(['pp-blocks', 'pp-fw', 'pp-fw-capped' => $cap !== null])>
    @if ($showIndicators)
        <div class="pp-fw-card pp-fw-ind">
            <div class="pp-fw-hd"><b>{{ __('proposals.framework.layer_indicators') }} · {{ count($node['indicators']) }}</b>@if ($canEdit)<button type="button" class="pp-link" wire:click="$dispatch('add-indicator', { resultId: {{ $resultId }} })">+ {{ __('proposals.framework.add') }}</button>@endif</div>
            @if ($node['indicators'] === [])
                <div class="pp-fw-empty">{{ __('proposals.framework.none_yet') }}</div>
            @else
                <div class="pp-fw-irow pp-fw-icols" aria-hidden="true">
                    <span>{{ __('proposals.framework.col_indicator') }}</span>
                    <span>{{ __('proposals.framework.col_progress') }}</span>
                    <span>{{ __('proposals.framework.col_target') }}</span>
                    <span>{{ __('proposals.framework.col_frequency') }}</span>
                </div>
                @foreach ($node['indicators'] as $indicator)
                    <div class="pp-fw-irow" @if ($this->canEditIndicators) role="button" tabindex="0" wire:click="$dispatch('edit-indicator', { indicatorId: {{ $indicator['id'] }} })" @endif data-test="framework-indicator-{{ $indicator['id'] }}" wire:key="ind-{{ $indicator['id'] }}">
                        <span class="pp-fw-name" dir="auto">{{ $indicator['name'] }}</span>
                        <span @class(['pp-bar', 'pp-bar-full' => ($indicator['percent'] ?? 0) >= 100])><i style="width: {{ (int) min(100, max(0, $indicator['percent'] ?? 0)) }}%"></i></span>
                        <span @class(['pp-fw-target', 'tabular-nums', 'pp-fw-muted' => $indicator['target'] === null])>{{ $indicator['target'] === null ? __('proposals.framework.no_target_yet') : ($indicator['actual'] ?? 0).' / '.$number($indicator['target']).' '.($indicator['unit'] ?? '') }}</span>
                        <span class="pp-fw-freq">
                            <span class="pp-mini">{{ $indicator['frequency'] }}</span>
                            @if ($indicator['unreported'])<span class="pp-mini pp-mini-red">{{ __('proposals.framework.no_report_yet') }}</span>@endif
                            @can('indicators.record')
                                <button type="button" class="pp-link" wire:click.stop="openProgressModal({{ $indicator['id'] }})" data-test="record-indicator-{{ $indicator['id'] }}">{{ __('indicators.log_reading') }}</button>
                            @endcan
                        </span>
                        @if ($layers['targets'] ?? false)
                            <span class="pp-centre-t pp-fw-centres">
                                @forelse ($indicator['centres'] as $centre)
                                    <span>{{ $centre['name'] }} <b>{{ $number($centre['target']) }}</b></span>
                                @empty
                                    <span>{{ __('proposals.framework.no_centre_targets') }}</span>
                                @endforelse
                                @if ($canEditTargets)
                                    <button type="button" class="pp-link" wire:click.stop="openCentreTargets({{ $indicator['id'] }})" data-test="edit-centre-targets-{{ $indicator['id'] }}">{{ __('proposals.framework.edit_targets') }}</button>
                                @endif
                                @can('targets.create')
                                    <button type="button" class="pp-link" wire:click.stop="$dispatch('commit-framework-target', { indicatorId: {{ $indicator['id'] }} })">{{ __('targets.commit_target') }}</button>
                                @endcan
                                @foreach ($indicator['committedTargets'] as $target)
                                    <span wire:key="proposal-target-actions-{{ $target['id'] }}">
                                        <b>{{ $number($target['value']) }}</b>
                                        @can('targets.update')
                                            <button type="button" class="pp-link" wire:click.stop="$dispatch('edit-framework-target', { targetId: {{ $target['id'] }} })">{{ __('targets.edit_target') }}</button>
                                        @endcan
                                        @can('targets.delete')
                                            @if ($target['deletable'])
                                                <button type="button" class="pp-link" wire:click.stop="$dispatch('delete-framework-target', { targetId: {{ $target['id'] }} })">{{ __('targets.delete_target') }}</button>
                                            @endif
                                        @endcan
                                    </span>
                                @endforeach
                            </span>
                        @endif
                    </div>
                @endforeach
            @endif
        </div>
    @endif

    @if ($showActivities || $showAssumptions)
        <div class="pp-fw-trio">
            @if ($showActivities)
                @php
                    $total = count($node['activities']);
                @endphp
                <div class="pp-fw-card pp-fw-list" x-data="{ all: false }">
                    <div class="pp-fw-hd"><b>{{ __('proposals.framework.layer_activities') }} · {{ $total }}</b>@if ($canEdit)<button type="button" class="pp-link" wire:click="openCreateModal({{ $resultId }})" data-test="add-child-result-{{ $resultId }}">+ {{ __('proposals.framework.add') }}</button>@endif</div>
                    @forelse ($node['activities'] as $index => $activityId)
                        @php
                            $activity = $fw['activities'][$activityId];
                            $counted = $activity['indicators'];
                            $firstTarget = collect($counted)->firstWhere('target', '!==', null);
                            $missingTarget = collect($counted)->contains(fn (array $item): bool => $item['target'] === null);
                        @endphp
                        <div class="pp-fw-row" wire:key="act-{{ $activityId }}" @if ($cap !== null && $index >= $cap) x-show="all" x-cloak @endif>
                            <span @class(['pp-fw-dot', 'pp-fw-dot-'.$activity['status']]) aria-hidden="true"></span>
                            <span class="pp-fw-body">
                                <span class="pp-fw-name" dir="auto">{{ $activity['name'] }}</span>
                                <span class="pp-fw-sub">
                                    @if ($missingTarget)<b>{{ __('proposals.framework.no_target_label') }}</b>@elseif ($firstTarget)<span>{{ __('proposals.framework.target_label', ['n' => $number($firstTarget['target'])]) }}</span>@endif
                                    @if ($counted !== [])<span>{{ trans_choice('proposals.framework.n_indicators', count($counted), ['count' => count($counted)]) }}</span>@endif
                                </span>
                            </span>
                            <span @class(['pp-fw-state', 'pp-fw-state-red' => in_array($activity['status'], ['progress', 'late'], true)])>{{ __('proposals.framework.status_'.$activity['status']) }}</span>
                        </div>
                    @empty
                        <div class="pp-fw-empty">{{ __('proposals.framework.none_yet') }}</div>
                    @endforelse
                    @include('components.proposals._framework-blocks-foot', ['total' => $total, 'board' => true])
                </div>
            @endif

            @if ($showAssumptions)
                @php
                    $total = count($node['assumptions']);
                @endphp
                <div class="pp-fw-card pp-fw-list" x-data="{ all: false }">
                    <div class="pp-fw-hd"><b>{{ __('proposals.framework.layer_assumptions') }} · {{ $total }}</b>@if ($canEdit)<button type="button" class="pp-link" wire:click="openAssumption(null, {{ $resultId }})" data-test="add-assumption-{{ $resultId }}">+ {{ __('proposals.framework.add') }}</button>@endif</div>
                    @forelse ($node['assumptions'] as $index => $assumption)
                        <div class="pp-fw-row pp-fw-row-asm" wire:key="asm-{{ $assumption['id'] }}" @if ($cap !== null && $index >= $cap) x-show="all" x-cloak @endif>
                            <span class="pp-fw-body">
                                <span class="pp-fw-name" dir="auto" @if ($canEdit) role="button" tabindex="0" wire:click="openAssumption({{ $assumption['id'] }})" @endif>{{ $assumption['statement'] }}</span>
                            </span>
                            <span class="pp-fw-pick" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                                <button type="button" @class(['pp-fw-pill', 'pp-fw-pill-'.$assumption['status']->value]) @disabled(! $canEdit) x-on:click="open = ! open" aria-haspopup="menu" x-bind:aria-expanded="open" aria-label="{{ __('proposals.framework.does_it_hold') }}">
                                    {{ $assumption['status']->label() }}
                                    @if ($canEdit)<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>@endif
                                </button>
                                @if ($canEdit)
                                    <span class="pp-fw-menu" role="menu" x-show="open" x-cloak>
                                        @foreach (\App\Enums\AssumptionStatus::cases() as $status)
                                            <button type="button" role="menuitemradio" @class(['pp-on' => $assumption['status'] === $status]) aria-checked="{{ $assumption['status'] === $status ? 'true' : 'false' }}" x-on:click="open = false" wire:click="setAssumptionStatus({{ $assumption['id'] }}, '{{ $status->value }}')">{{ $status->label() }}</button>
                                        @endforeach
                                    </span>
                                @endif
                            </span>
                        </div>
                    @empty
                        <div class="pp-fw-empty">{{ __('proposals.framework.none_yet') }}</div>
                    @endforelse
                    @include('components.proposals._framework-blocks-foot', ['total' => $total, 'board' => false])
                </div>
            @endif
        </div>
    @endif
</div>
