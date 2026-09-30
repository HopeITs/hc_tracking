@props(['milestones', 'window' => null])

@php
    use App\Enums\MilestoneStatus;
    use Carbon\CarbonImmutable;

    $today = CarbonImmutable::today();
    $windowStart = $window['start'] ?? null;
    $windowSpan = $window === null ? 0 : max(1, $window['end']->getTimestamp() - $windowStart->getTimestamp());

    /** Position of a date along the window, as a clamped percentage. */
    $position = fn (CarbonImmutable $date): float => $window === null ? 0.0 : round(min(100, max(0, ($date->getTimestamp() - $windowStart->getTimestamp()) / $windowSpan * 100)), 3);

    $ticks = [];
    if ($window !== null) {
        $months = (int) $windowStart->diffInMonths($window['end']) + 1;
        $step = max(1, (int) ceil($months / 8));
        for ($tick = $windowStart; $tick->lte($window['end']); $tick = $tick->addMonthsNoOverflow($step)) {
            $ticks[] = ['label' => $tick->translatedFormat(__('formats.month_year')), 'at' => $position($tick)];
        }
    }
    $todayAt = $window !== null ? $position($today) : null;

    $markerTone = fn ($milestone): string => match (true) {
        $milestone->status === MilestoneStatus::Reached => 'border-status-good bg-status-good',
        $milestone->status === MilestoneStatus::Cancelled => 'border-secondary-light bg-secondary-soft dark:border-line dark:bg-white/10',
        $milestone->isMissed() || $milestone->isOverdue() => 'border-primary bg-primary dark:bg-status-critical dark:border-status-critical',
        $milestone->status === MilestoneStatus::Ongoing => 'border-status-warn bg-status-warn',
        default => 'border-secondary-dark bg-board dark:border-ink-muted',
    };
@endphp

<div {{ $attributes->class('flex flex-col gap-1') }}>
    @if ($milestones->isNotEmpty())
        {{-- Legend --}}
        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-board-line px-3.5 pt-3.5 pb-3 type-meta text-secondary-medium dark:text-ink-muted" aria-hidden="true">
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-status-good bg-status-good"></span>{{ MilestoneStatus::Reached->label() }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-secondary-dark bg-board dark:border-ink-muted"></span>{{ MilestoneStatus::Planned->label() }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-status-warn bg-status-warn"></span>{{ MilestoneStatus::Ongoing->label() }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-primary bg-primary dark:bg-status-critical dark:border-status-critical"></span>{{ __('milestones.missed') }} / {{ __('milestones.overdue') }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-3.5 w-0.5 bg-primary dark:bg-ink"></span>{{ __('ui.today') }}</span>
        </div>

        @if ($window !== null)
            <div class="hidden grid-cols-[minmax(12rem,16rem)_1fr_9rem] gap-4 px-3.5 @4xl/detail:grid">
                <span></span>
                <div class="relative h-8">
                    @foreach ($ticks as $tick)
                        <span class="absolute bottom-2 -translate-x-1/2 type-meta whitespace-nowrap text-secondary-medium tabular-nums rtl:translate-x-1/2 dark:text-ink-muted" style="inset-inline-start: {{ $tick['at'] }}%">{{ $tick['label'] }}</span>
                    @endforeach
                    <span class="absolute bottom-1.5 -translate-x-1/2 rounded-full bg-primary dark:bg-ink dark:text-surface-0 px-2 py-0.5 text-[0.6875rem] font-bold whitespace-nowrap text-white rtl:translate-x-1/2" style="inset-inline-start: {{ $todayAt }}%">{{ __('ui.today') }}</span>
                </div>
                <span></span>
            </div>
        @endif
    @endif

    <ol class="flex flex-col divide-y divide-board-line">
        @if ($milestones->isEmpty())
            {{ $slot }}
        @endif

        @foreach ($milestones as $milestone)
            <li class="grid gap-3 px-3.5 py-3 @4xl/detail:grid-cols-[minmax(12rem,16rem)_1fr_9rem] @4xl/detail:items-center @4xl/detail:gap-4" wire:key="milestone-timeline-{{ $milestone->id }}">
                {{-- Identity --}}
                <div class="min-w-0">
                    <p class="truncate font-semibold text-secondary-dark dark:text-ink">{{ $milestone->name }}</p>
                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 type-meta text-secondary-medium dark:text-ink-muted">
                        <x-ui.enum-badge :case="$milestone->status" />
                        @if ($milestone->weight_percentage !== null)
                            <span class="tabular-nums">{{ __('milestones.weight_weight', ['weight' => number_format((float) $milestone->weight_percentage, 0)]) }}</span>
                        @endif
                        @if ($milestone->responsibleUser !== null)
                            <span class="inline-flex min-w-0 items-center gap-1"><flux:icon.user variant="micro" class="size-3.5 shrink-0" aria-hidden="true" /><span class="truncate">{{ $milestone->responsibleUser->name }}</span></span>
                        @endif
                    </div>
                </div>

                {{-- Track --}}
                <div class="min-w-0">
                    <div class="relative hidden h-9 @4xl/detail:block">
                        @if ($window !== null)
                            @foreach ($ticks as $tick)
                                <span aria-hidden="true" class="absolute inset-y-0 w-px bg-board-line" style="inset-inline-start: {{ $tick['at'] }}%"></span>
                            @endforeach
                            <span aria-hidden="true" class="absolute inset-y-0 z-10 w-0.5 -translate-x-1/2 bg-primary/70 rtl:translate-x-1/2" style="inset-inline-start: {{ $todayAt }}%"></span>

                            @if ($milestone->planned_start_date !== null)
                                @php
                                    $markerDetails = $milestone->name.' · '.$milestone->planned_start_date->translatedFormat(__('formats.date')).' · '.$milestone->status->label();
                                @endphp
                                <button
                                    type="button"
                                    class="absolute top-1/2 z-20 flex size-6 -translate-x-1/2 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full focus-visible:outline-2 focus-visible:outline-primary rtl:translate-x-1/2"
                                    style="inset-inline-start: {{ $position($milestone->planned_start_date) }}%"
                                    x-data="{ open: false }"
                                    x-on:mouseenter="open = true"
                                    x-on:mouseleave="open = false"
                                    x-on:focus="open = true"
                                    x-on:blur="open = false"
                                    x-on:click="open = ! open"
                                    x-on:keydown.escape="open = false"
                                    aria-label="{{ $markerDetails }}"
                                >
                                    <span aria-hidden="true" class="block size-3 rotate-45 rounded-[2px] border-2 shadow-sm ring-2 ring-board {{ $markerTone($milestone) }}"></span>
                                    <span
                                        x-show="open"
                                        style="display: none"
                                        role="tooltip"
                                        class="pointer-events-none absolute bottom-full start-1/2 z-30 mb-1.5 w-max max-w-56 -translate-x-1/2 rounded-md bg-secondary-dark px-2.5 py-1.5 text-start text-xs leading-snug text-white shadow-lg rtl:translate-x-1/2 dark:bg-surface-3 dark:shadow-raised dark:text-ink"
                                    >
                                        <span class="block font-semibold">{{ $milestone->name }}</span>
                                        <span class="block opacity-80">{{ $milestone->planned_start_date->translatedFormat(__('formats.date')) }} · {{ $milestone->status->label() }}</span>
                                    </span>
                                </button>
                            @endif
                        @else
                            <span class="absolute inset-x-0 top-1/2 flex h-7 -translate-y-1/2 items-center justify-center rounded-md border border-dashed border-secondary-light type-meta text-secondary-medium dark:text-ink-muted">{{ __('common.unscheduled') }}</span>
                        @endif
                    </div>
                    <p class="mt-1 type-meta text-secondary-medium tabular-nums dark:text-ink-muted">
                        <bdi>{{ $milestone->planned_start_date?->translatedFormat(__('formats.date')) ?? __('common.unscheduled') }}</bdi>
                        @if ($milestone->actual_end_date !== null)
                            <span aria-hidden="true">·</span> {{ __('milestones.actual_end_date') }}: {{ $milestone->actual_end_date->translatedFormat(__('formats.date')) }}
                        @endif
                    </p>
                </div>

                {{-- Schedule verdict --}}
                <div class="flex items-center gap-2 @4xl/detail:flex-col @4xl/detail:items-end @4xl/detail:gap-1">
                    <x-ui.schedule-status
                        :planned="$milestone->planned_end_date ?? $milestone->planned_start_date"
                        :actual="$milestone->actual_end_date"
                        :done="$milestone->status === MilestoneStatus::Reached"
                    />
                </div>
            </li>
        @endforeach
    </ol>
</div>
