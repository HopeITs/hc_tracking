{{-- The classic logframe table: one row per result, indented by level, one column per layer, then Health. --}}
@php
    $rows = [];
    $walk = function (int $id, int $depth) use (&$walk, &$rows, $fw, $visible): void {
        if (! in_array($id, $visible, true)) {
            return;
        }
        $rows[] = [$id, $depth];
        foreach ($fw['children'][$id] ?? [] as $kid) {
            $walk($kid, $depth + 1);
        }
    };
    foreach ($fw['roots'] as $rootId) {
        $walk($rootId, 0);
    }
    $columns = array_values(array_filter($this::LAYERS, fn (string $layer): bool => $layers[$layer] ?? false));
    $num = fn (?float $value): string => $value === null ? '' : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
@endphp

<div class="pp-tbl-wrap" data-test="framework-matrix">
    <table class="pp-table">
        <thead>
            <tr>
                <th scope="col" style="min-width: 260px">{{ __('proposals.framework.result') }}</th>
                @foreach ($columns as $column)
                    <th scope="col" style="min-width: 170px">{{ __('proposals.framework.layer_'.$column) }}</th>
                @endforeach
                <th scope="col">{{ __('proposals.framework.health') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as [$id, $depth])
                @php $node = $fw['results'][$id]; @endphp
                <tr wire:key="mx-{{ $id }}">
                    <td>
                        <div class="pp-mx-res" style="padding-inline-start: {{ $depth * 16 }}px">
                            <span class="pp-lvl pp-lvl-{{ $node['type']->value }}">{{ $node['type']->label() }}</span>
                            <button type="button" class="pp-mx-item pp-mx-strong" @if ($canEdit) wire:click="openEditModal({{ $id }})" @endif dir="auto">{{ $node['statement'] }}</button>
                        </div>
                    </td>
                    @foreach ($columns as $column)
                        <td>
                            @switch($column)
                                @case('indicators')
                                    @foreach ($node['indicators'] as $indicator)
                                        <button type="button" class="pp-mx-item" @if ($this->canEditIndicators) wire:click="$dispatch('edit-indicator', { indicatorId: {{ $indicator['id'] }} })" @endif dir="auto">{{ \Illuminate\Support\Str::limit($indicator['name'], 70) }} <span class="pp-small">{{ $indicator['target'] === null ? __('proposals.framework.no_target') : ($indicator['actual'] ?? 0).'/'.$num($indicator['target']) }}</span></button>
                                    @endforeach
                                    @if ($canEdit)<button type="button" class="pp-mx-add" wire:click="$dispatch('add-indicator', { resultId: {{ $id }} })">+ {{ __('proposals.framework.add') }}</button>@endif
                                    @break
                                @case('targets')
                                    @forelse ($node['indicators'] as $indicator)
                                        @php
                                            $centreLine = $indicator['centres'] === [] ? __('proposals.framework.no_centre_targets') : collect($indicator['centres'])->map(fn (array $c): string => $c['name'].' '.$num($c['target']))->implode(' · ');
                                        @endphp
                                        @if ($canEditTargets)
                                            <button type="button" class="pp-mx-item" wire:click="openCentreTargets({{ $indicator['id'] }})" title="{{ __('proposals.framework.edit_targets') }}: {{ $indicator['name'] }}" data-test="edit-centre-targets-{{ $indicator['id'] }}">{{ $centreLine }}</button>
                                        @else
                                            <span class="pp-mx-item">{{ $centreLine }}</span>
                                        @endif
                                    @empty
                                        <span class="pp-small">—</span>
                                    @endforelse
                                    @break
                                @case('activities')
                                    @if ($node['type'] === \App\Enums\ResultType::Output)
                                        @foreach ($node['activities'] as $activityId)
                                            <span class="pp-mx-item" dir="auto">{{ \Illuminate\Support\Str::limit($fw['activities'][$activityId]['name'], 70) }} <span @class(['pp-spill', 'pp-st-'.$fw['activities'][$activityId]['status']])>{{ __('proposals.framework.status_'.$fw['activities'][$activityId]['status']) }}</span></span>
                                        @endforeach
                                        @if ($canEdit)<button type="button" class="pp-mx-add" wire:click="openCreateModal({{ $id }})">+ {{ __('proposals.framework.add') }}</button>@endif
                                    @else
                                        <span class="pp-small">—</span>
                                    @endif
                                    @break
                                @case('risks')
                                    @foreach ($node['risks'] as $risk)
                                        <span class="pp-mx-item" dir="auto">{{ \Illuminate\Support\Str::limit($risk['name'], 70) }} @if ($risk['happened'])<span class="pp-mini pp-mini-red">{{ __('proposals.framework.happened') }}</span>@endif</span>
                                    @endforeach
                                    @if ($this->canManageRisks)<button type="button" class="pp-mx-add" wire:click="openRisks({{ $id }})">+ {{ __('proposals.framework.add') }}</button>@endif
                                    @break
                                @case('assumptions')
                                    @foreach ($node['assumptions'] as $assumption)
                                        <button type="button" class="pp-mx-item" @if ($canEdit) wire:click="openAssumption({{ $assumption['id'] }})" @endif dir="auto">{{ \Illuminate\Support\Str::limit($assumption['statement'], 70) }} @if ($assumption['status'] !== \App\Enums\AssumptionStatus::Holds)<span class="pp-mini pp-mini-red">{{ $assumption['status']->label() }}</span>@endif</button>
                                    @endforeach
                                    @if ($canEdit)<button type="button" class="pp-mx-add" wire:click="openAssumption(null, {{ $id }})">+ {{ __('proposals.framework.add') }}</button>@endif
                                    @break
                            @endswitch
                        </td>
                    @endforeach
                    <td>
                        @forelse ($node['issues'] as $issue)
                            <span class="pp-mini pp-mini-red pp-mx-issue">{{ $issue }}</span>
                        @empty
                            <span class="pp-small">✓ {{ __('proposals.framework.ok') }}</span>
                        @endforelse
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
