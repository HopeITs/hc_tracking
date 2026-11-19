{{-- The framework task bar: who holds it, its due date, the four checks and Mark as done / Reopen. --}}
@php
    $people = $this->taskPeople;
    $isDone = $task->status === \App\Enums\TaskStatus::Done;
    $days = $task->due_date === null ? null : (int) \Carbon\CarbonImmutable::today()->diffInDays($task->due_date, false);
    $when = match (true) {
        $days === null => null,
        $days === 0 => __('proposals.attention.due_today'),
        $days < 0 => trans_choice('proposals.attention.days_late', -$days, ['count' => -$days]),
        default => trans_choice('proposals.attention.in_days', $days, ['count' => $days]),
    };
    $checkText = [
        'ind' => fn (array $c): string => $c['ok'] ? __('proposals.framework.check_ind_ok') : trans_choice('proposals.framework.check_ind_left', $c['count'], ['count' => $c['count']]),
        'tgt' => fn (array $c): string => $c['ok'] ? __('proposals.framework.check_tgt_ok') : __('proposals.framework.check_tgt_left', ['count' => $c['count'], 'total' => $c['total']]),
        'act' => fn (array $c): string => $c['ok'] ? __('proposals.framework.check_act_ok') : trans_choice('proposals.framework.check_act_left', $c['count'], ['count' => $c['count']]),
        'ra' => fn (array $c): string => $c['ok'] ? __('proposals.framework.check_ra_ok', ['risks' => $c['count'], 'assumptions' => $c['total']]) : __('proposals.framework.check_ra_none'),
    ];
@endphp

@if ($isDone)
    <div class="pp-task pp-task-done" data-test="framework-task-done">
        <div class="pp-task-row">
            <span class="pp-task-ic">{!! $svg('check', 16) !!}</span>
            <span class="pp-task-t">
                <b>{{ __('proposals.framework.task_done') }}</b>
                <span class="pp-small">
                    {{ __('proposals.framework.task_done_by', ['name' => $people['doneBy'] ?? '—', 'date' => $task->completed_date?->translatedFormat(__('formats.date'))]) }}
                    @if ($task->completion_note) · “{{ $task->completion_note }}”@endif
                    · {{ __('proposals.framework.task_next_step') }}
                </span>
            </span>
            @if ($people['mayReopen'])
                <button type="button" class="pp-btn pp-btn-sm" wire:click="reopenTask" data-test="framework-reopen">{{ __('proposals.framework.reopen') }}</button>
            @endif
        </div>
    </div>
@else
    <div class="pp-task pp-task-open" data-test="framework-task">
        <div class="pp-task-row">
            <span class="pp-task-ic">{!! $svg('task', 16) !!}</span>
            <span class="pp-task-t">
                <b>{{ $people['mine'] ? __('proposals.framework.task_yours') : __('proposals.framework.task_for', ['name' => $people['assignee'] ?? __('proposals.framework.nobody')]) }}</b>
                <span class="pp-small">{{ __('proposals.framework.task_assigned') }}@if ($when) · {{ __('proposals.framework.task_due', ['date' => $task->due_date->translatedFormat(__('formats.date')), 'when' => $when]) }}@endif</span>
            </span>
            <span class="pp-prog" title="{{ __('proposals.framework.n_of_4', ['count' => $readyCount]) }}">
                @foreach ($checks as $check)<i @class(['pp-ok' => $check['ok']])></i>@endforeach
            </span>
            <span class="pp-small">{{ __('proposals.framework.n_of_4', ['count' => $readyCount]) }}</span>
            <button type="button" class="pp-btn pp-btn-sm" wire:click="$toggle('checksOpen')" aria-expanded="{{ $checksOpen ? 'true' : 'false' }}" data-test="framework-checks-toggle">{{ $checksOpen ? __('proposals.framework.hide_checklist') : __('proposals.framework.whats_left') }}</button>
            @can('results.create')
                <button type="button" class="pp-btn pp-btn-sm pp-btn-primary" wire:click="openDoneModal" data-test="framework-mark-done">{{ __('proposals.framework.mark_done') }}</button>
            @endcan
        </div>
        @if ($checksOpen)
            <div class="pp-checks">
                @foreach ($checks as $check)
                    <button type="button" @class(['pp-check', 'pp-ok' => $check['ok']]) wire:click="goCheck('{{ $check['key'] }}')" data-test="framework-check-{{ $check['key'] }}">
                        <span class="pp-mk">{{ $check['ok'] ? '✓' : '!' }}</span>
                        <span>{{ __('proposals.framework.check_'.$check['key']) }}<span class="pp-why">{{ $checkText[$check['key']]($check) }}@unless ($check['ok']) · {{ __('proposals.framework.show_me') }}@endunless</span></span>
                    </button>
                @endforeach
            </div>
        @endif
    </div>
@endif
