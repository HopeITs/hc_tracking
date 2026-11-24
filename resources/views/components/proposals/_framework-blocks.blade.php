{{--
    The editable lists under one result (indicators, activities, risks,
    assumptions), shared by the tree node and the outcome tabs. Expects
    $node, $resultId and $only (null for every layer the person shows, or
    the layers to keep).
--}}
<div class="pp-blocks">
    @if (($only === null || in_array('indicators', $only, true)) && ($layers['indicators'] ?? false))
        <div class="pp-block">
            <div class="pp-block-h"><b>{{ __('proposals.framework.layer_indicators') }} · {{ count($node['indicators']) }}</b>@if ($canEdit)<button type="button" class="pp-link" wire:click="$dispatch('add-indicator', { resultId: {{ $resultId }} })">+ {{ __('proposals.framework.add') }}</button>@endif</div>
            @forelse ($node['indicators'] as $indicator)
                <div class="pp-li" @if ($this->canEditIndicators) role="button" tabindex="0" wire:click="$dispatch('edit-indicator', { indicatorId: {{ $indicator['id'] }} })" @endif data-test="framework-indicator-{{ $indicator['id'] }}" wire:key="ind-{{ $indicator['id'] }}">
                    <span class="pp-li-t" dir="auto">{{ $indicator['name'] }}</span>
                    <span class="pp-li-r">
                        <span @class(['pp-bar', 'pp-bar-full' => ($indicator['percent'] ?? 0) >= 100])><i style="width: {{ (int) min(100, max(0, $indicator['percent'] ?? 0)) }}%"></i></span>
                        <span class="pp-small tabular-nums">{{ $indicator['target'] === null ? __('proposals.framework.no_target_yet') : ($indicator['actual'] ?? 0).' / '.rtrim(rtrim(number_format($indicator['target'], 2, '.', ''), '0'), '.').' '.($indicator['unit'] ?? '') }}</span>
                        <span class="pp-mini">{{ $indicator['frequency'] }}</span>
                        @if ($indicator['unreported'])<span class="pp-mini pp-mini-red">{{ __('proposals.framework.no_report_yet') }}</span>@endif
                    </span>
                    @if ($layers['targets'] ?? false)
                        <span class="pp-centre-t">
                            @forelse ($indicator['centres'] as $centre)
                                <span>{{ $centre['name'] }} <b>{{ rtrim(rtrim(number_format($centre['target'], 2, '.', ''), '0'), '.') }}</b></span>
                            @empty
                                <span>{{ __('proposals.framework.no_centre_targets') }}</span>
                            @endforelse
                            @if ($canEditTargets)
                                <button type="button" class="pp-link" wire:click.stop="openCentreTargets({{ $indicator['id'] }})" data-test="edit-centre-targets-{{ $indicator['id'] }}">{{ __('proposals.framework.edit_targets') }}</button>
                            @endif
                        </span>
                    @endif
                </div>
            @empty
                <span class="pp-small">{{ __('proposals.framework.none_yet') }}</span>
            @endforelse
        </div>
    @endif

    @if (($only === null || in_array('activities', $only, true)) && ($layers['activities'] ?? false) && $node['type'] === \App\Enums\ResultType::Output)
        <div class="pp-block">
            <div class="pp-block-h"><b>{{ __('proposals.framework.layer_activities') }} · {{ count($node['activities']) }}</b><span class="pp-block-acts"><button type="button" class="pp-link" wire:click="setLayout('board')">{{ __('proposals.framework.edit_on_board') }}</button>@if ($canEdit)<button type="button" class="pp-link" wire:click="openCreateModal({{ $resultId }})" data-test="add-child-result-{{ $resultId }}">+ {{ __('proposals.framework.add') }}</button>@endif</span></div>
            @forelse ($node['activities'] as $activityId)
                @php $activity = $fw['activities'][$activityId]; @endphp
                <div class="pp-li" wire:key="act-{{ $activityId }}">
                    <span class="pp-li-t" dir="auto">{{ $activity['name'] }}</span>
                    <span class="pp-li-r">
                        <span @class(['pp-spill', 'pp-st-'.$activity['status']])>{{ __('proposals.framework.status_'.$activity['status']) }}</span>
                        @foreach ($activity['indicators'] as $counted)
                            <span @class(['pp-mini', 'pp-mini-red' => $counted['target'] === null])>{{ $counted['target'] === null ? __('proposals.framework.no_target') : __('proposals.framework.target_n', ['n' => rtrim(rtrim(number_format($counted['target'], 2, '.', ''), '0'), '.')]) }}</span>
                        @endforeach
                    </span>
                </div>
            @empty
                <span class="pp-small">{{ __('proposals.framework.none_yet') }}</span>
            @endforelse
        </div>
    @endif

    @if (($only === null || in_array('risks', $only, true)) && ($layers['risks'] ?? false))
        <div class="pp-block">
            <div class="pp-block-h"><b>{{ __('proposals.framework.layer_risks') }} · {{ count($node['risks']) }}</b>@if ($this->canManageRisks && in_array($node['type'], [\App\Enums\ResultType::Outcome, \App\Enums\ResultType::Output], true))<button type="button" class="pp-link" wire:click="openRisks({{ $resultId }})" data-test="result-risks-{{ $resultId }}">+ {{ __('proposals.framework.add') }}</button>@endif</div>
            @forelse ($node['risks'] as $risk)
                <div class="pp-li" wire:key="risk-{{ $risk['id'] }}">
                    <span class="pp-li-t" dir="auto">{{ $risk['name'] }}</span>
                    <span class="pp-li-r">
                        @if ($risk['likelihood'])<span class="pp-mini">{{ __('risks.likelihood').': '.__('risks.rating_'.['1' => 'low', '2' => 'medium', '3' => 'high'][(string) min(3, max(1, (int) $risk['likelihood']))]) }}</span>@endif
                        @if ($risk['severity'])<span class="pp-mini">{{ __('risks.severity').': '.__('risks.rating_'.['1' => 'low', '2' => 'medium', '3' => 'high'][(string) min(3, max(1, (int) $risk['severity']))]) }}</span>@endif
                        @if ($risk['happened'])<span class="pp-mini pp-mini-red">{{ __('proposals.framework.happened') }}</span>@endif
                    </span>
                </div>
            @empty
                <span class="pp-small">{{ __('proposals.framework.none_yet') }}</span>
            @endforelse
        </div>
    @endif

    @if (($only === null || in_array('assumptions', $only, true)) && ($layers['assumptions'] ?? false))
        <div class="pp-block">
            <div class="pp-block-h"><b>{{ __('proposals.framework.layer_assumptions') }} · {{ count($node['assumptions']) }}</b>@if ($canEdit)<button type="button" class="pp-link" wire:click="openAssumption(null, {{ $resultId }})" data-test="add-assumption-{{ $resultId }}">+ {{ __('proposals.framework.add') }}</button>@endif</div>
            @forelse ($node['assumptions'] as $assumption)
                <div class="pp-li" wire:key="asm-{{ $assumption['id'] }}">
                    <span class="pp-li-t" dir="auto" @if ($canEdit) role="button" tabindex="0" wire:click="openAssumption({{ $assumption['id'] }})" @endif>{{ $assumption['statement'] }}</span>
                    <span class="pp-li-r">
                        <span class="pp-seg" role="group" aria-label="{{ __('proposals.framework.does_it_hold') }}">
                            @foreach (\App\Enums\AssumptionStatus::cases() as $status)
                                <button type="button" @class(['pp-on' => $assumption['status'] === $status, 'pp-seg-warn' => $status === \App\Enums\AssumptionStatus::AtRisk, 'pp-seg-bad' => $status === \App\Enums\AssumptionStatus::Broken]) @disabled(! $canEdit) wire:click="setAssumptionStatus({{ $assumption['id'] }}, '{{ $status->value }}')" aria-pressed="{{ $assumption['status'] === $status ? 'true' : 'false' }}">{{ $status->label() }}</button>
                            @endforeach
                        </span>
                    </span>
                </div>
            @empty
                <span class="pp-small">{{ __('proposals.framework.none_yet') }}</span>
            @endforelse
        </div>
    @endif
</div>
