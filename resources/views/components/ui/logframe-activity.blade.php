{{--
    An activity's delivery in the results chain (T16 slice 2): each center's
    confirmed activity status (D-51) as a text badge, and its planned months
    (activity_schedules). Renders from the ResultsChain node array only, no
    queries.
--}}
@props(['activityId', 'schedule', 'statuses' => []])

@php
    use Carbon\CarbonImmutable;

    $muted = 'text-secondary-medium dark:text-ink-muted';
@endphp

@if ($statuses !== [])
    <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5" data-chain-activity-statuses>
        <span class="inline-flex items-center gap-1 type-meta {{ $muted }}">
            <flux:icon.signal variant="micro" class="size-3.5" aria-hidden="true" />{{ __('results_framework.chain_activity_status') }}
        </span>
        @foreach ($statuses as $status)
            <span class="inline-flex items-center gap-1.5" data-chain-activity-status="{{ $status['centerId'] }}" wire:key="chain-status-{{ $activityId }}-{{ $status['centerId'] }}">
                <span class="text-xs font-semibold" dir="auto">{{ $status['center'] }}</span>
                <x-ui.enum-badge :case="$status['status']" />
            </span>
        @endforeach
    </div>
@endif

@if ($schedule !== [])
    <div class="mt-3 flex flex-wrap items-center gap-1.5" data-chain-schedule>
        <span class="inline-flex items-center gap-1 type-meta {{ $muted }}">
            <flux:icon.calendar-days variant="micro" class="size-3.5" aria-hidden="true" />{{ __('results_framework.chain_planned_months') }}
        </span>
        @foreach ($schedule as $month => $weeks)
            <span class="inline-flex items-center gap-1 rounded-full border border-board-line bg-board px-2 py-0.5 text-xs font-semibold dark:border-line" data-chain-schedule-month="{{ $month }}">
                <span class="tabular-nums">{{ CarbonImmutable::createFromFormat('!Y-m', $month)->translatedFormat('M Y') }}</span>
                @if ($weeks !== null && $weeks !== [])
                    <span class="font-normal {{ $muted }}">· {{ trans_choice('results_framework.chain_weeks', count($weeks), ['weeks' => implode(', ', $weeks)]) }}</span>
                @endif
            </span>
        @endforeach
    </div>
@endif
