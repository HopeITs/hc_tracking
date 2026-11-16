{{--
    One program row under a pillar on strategies/show: status dot, name
    (to the program's page), its status in words, projects, manager, and
    for managers the button that places it under a pillar.

    program: Program from allPrograms(). folded: hidden until "Show N more".
    assignLabel: 'move' (under a pillar) | 'choose' (under no pillar).
--}}
@php
    $state = $this->programStates[$program->id] ?? null;
    [$stateTone, $stateText] = match ($state) {
        'no_plan' => ['dk-warn', __('strategies.state_no_plan', ['year' => now()->year])],
        'behind' => ['dk-bad', __('strategies.state_behind')],
        'on_pace' => ['dk-good', __('strategies.state_on_pace')],
        'no_progress' => ['dk-mute', __('strategies.state_no_progress')],
        default => ['dk-mute', null],
    };
@endphp

<li class="dk-sprow" wire:key="pillar-program-{{ $program->id }}" data-test="strategy-program-{{ $program->id }}" @if ($folded) x-show="more" x-cloak @endif>
    <a href="{{ route('programs.show', $program) }}" wire:navigate class="dk-sprow-name">
        <span class="dk-pdot {{ $stateTone }}" aria-hidden="true"></span>
        <span dir="auto">{{ $program->name }}</span>
    </a>
    <span class="dk-sprow-state {{ $stateTone }}" data-program-state="{{ $state ?? 'none' }}">{{ $stateText }}</span>
    <span class="dk-sprow-count dk-num">{{ trans_choice('common.count_project', $program->projects_count, ['count' => $program->projects_count]) }}</span>
    <x-ui.employee-chip :employee="$program->manager" compact :empty="__('strategies.no_manager')" class="dk-sprow-who" />
    <span class="dk-sprow-act">
        @php $strategicPlanStatus = $this->strategicPlanStatuses[$program->id] ?? null; @endphp
        <a href="{{ route('strategic-plans.show', [$this->strategy, $program]) }}" wire:navigate class="dk-slink" aria-label="{{ __('strategic_plans.open_plan_named', ['program' => $program->name]) }}" data-test="strategic-plan-link-{{ $program->id }}">{{ __('strategic_plans.open_plan') }} · {{ $strategicPlanStatus?->label() ?? __('strategic_plans.not_started') }}</a>
        @can('strategies.update')
            @php
                $assignAria = $assignLabel === 'move' ? __('strategies.move_to_pillar_named', ['program' => $program->name]) : __('strategies.choose_pillar_for', ['program' => $program->name]);
            @endphp
            <button type="button" class="dk-slink" wire:click="openAssignModal({{ $program->id }})" aria-label="{{ $assignAria }}" data-test="assign-program-{{ $program->id }}">{{ $assignLabel === 'move' ? __('strategies.move_to_pillar') : __('strategies.assign_pillar') }}</button>
        @endcan
    </span>
</li>
