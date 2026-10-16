{{--
    An activity's delivery in the results chain (T16 slice 2): its planned
    months (activity_schedules) and its milestones grouped by center, each
    group with its weighted progress (Milestone::weightedProgress(); cancelled
    milestones listed but not counted). Link flags come from
    Milestone::activityLinkIssuesFor() and are shown, never hidden. Renders
    from the ResultsChain node array only, no queries.
--}}
@props(['activityId', 'groups', 'schedule'])

@php
    use Carbon\CarbonImmutable;

    $muted = 'text-secondary-medium dark:text-ink-muted';
    $percent = fn (?float $value): string => $value === null ? '' : rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    $milestoneCount = array_sum(array_map(fn (array $group): int => count($group['milestones']), $groups));
@endphp

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

@if ($groups !== [])
    <div class="mt-3" x-data="{ milestones: true }" data-chain-milestones>
        <button
            type="button"
            x-on:click="milestones = ! milestones"
            x-bind:aria-expanded="milestones.toString()"
            aria-controls="chain-milestones-{{ $activityId }}"
            class="inline-flex cursor-pointer items-center gap-1.5 rounded-sm type-meta font-semibold text-secondary-dark hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary print:hidden dark:text-ink dark:hover:text-brand-text"
        >
            <flux:icon.flag variant="micro" class="size-3.5" aria-hidden="true" />
            {{ trans_choice('results_framework.chain_milestones', $milestoneCount, ['count' => $milestoneCount]) }}
            <flux:icon.chevron-down variant="micro" class="size-3.5 transition-transform duration-200" x-bind:class="milestones ? 'rotate-180' : ''" aria-hidden="true" />
        </button>

        <div id="chain-milestones-{{ $activityId }}" x-show="milestones" x-collapse class="mt-2 space-y-2 print:block!">
            @foreach ($groups as $group)
                <section class="rounded-md border border-board-line bg-board px-3 py-2 dark:border-line" wire:key="chain-milestones-{{ $activityId }}-{{ $group['centerId'] }}">
                    <div class="grid gap-1.5 sm:grid-cols-[minmax(0,1fr)_minmax(0,14rem)] sm:items-center sm:gap-4">
                        <p class="text-sm font-semibold" dir="auto">{{ $group['center'] }}</p>
                        <div class="min-w-0" data-chain-milestone-progress="{{ $group['centerId'] }}" data-percent="{{ $percent($group['progress']) }}">
                            <p class="flex items-baseline justify-between gap-2 text-xs">
                                <span class="{{ $muted }}">{{ __('results_framework.chain_milestones_progress') }}</span>
                                <span dir="ltr" class="font-bold tabular-nums">{{ $group['progress'] === null ? '—' : $percent($group['progress']).'%' }}</span>
                            </p>
                            <x-ui.progress-bar class="mt-1" :value="$group['progress']" :tone="$group['progress'] !== null && $group['progress'] >= 100 ? 'good' : 'neutral'" :label="__('results_framework.chain_milestones_progress')" label-hidden />
                        </div>
                    </div>

                    <ul class="mt-2 divide-y divide-board-line border-t border-board-line dark:divide-line dark:border-line">
                        @foreach ($group['milestones'] as $row)
                            @php
                                $milestone = $row['milestone'];
                                $dates = collect([$milestone->planned_start_date, $milestone->planned_end_date])
                                    ->filter()
                                    ->map(fn ($date): string => $date->format('Y-m-d'))
                                    ->unique()
                                    ->implode(' – ');
                            @endphp
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2" data-chain-milestone="{{ $milestone->id }}" wire:key="chain-milestone-{{ $milestone->id }}">
                                <span @class(['min-w-0 flex-1 text-sm font-medium', 'line-through decoration-secondary-light '.$muted => $row['cancelled']]) dir="auto">{{ $milestone->name }}</span>
                                <bdi dir="ltr" class="type-meta tabular-nums {{ $muted }}">{{ $dates }}</bdi>
                                <x-ui.enum-badge :case="$milestone->status" />
                                @foreach ($row['issues'] as $issue)
                                    <span class="inline-flex basis-full items-center gap-1 type-meta text-status-critical" data-chain-issue>
                                        <flux:icon.exclamation-circle variant="micro" class="size-3.5" aria-hidden="true" />{{ __($issue) }}
                                    </span>
                                @endforeach
                            </li>
                        @endforeach
                    </ul>

                    @if ($group['hasCancelled'])
                        <p class="mt-1 type-meta {{ $muted }}">{{ __('results_framework.chain_milestone_cancelled_excluded') }}</p>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
@endif
