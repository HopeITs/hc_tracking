@props(['slots', 'strategy' => null])

@php
    use App\Enums\ResultType;

    $currentYear = (int) now()->year;
    $yearElapsed = (int) round(now()->dayOfYear / now()->daysInYear * 100);
    $planned = collect($slots)->whereNotNull('plan');
    $totalTargets = $planned->sum('targets');
    $totalReports = $planned->sum('reports');
    $totalApproved = $planned->sum('approved');

    $levelMarks = [
        ResultType::Impact->value => 'bg-secondary-dark dark:bg-white',
        ResultType::Outcome->value => 'bg-primary',
        ResultType::Output->value => 'bg-secondary-light',
    ];
@endphp

<section {{ $attributes->class('overflow-hidden rounded-md border border-board-line bg-board') }} aria-labelledby="program-plans-heading">
    <header class="flex flex-col gap-5 border-b border-board-line bg-board-head px-5 py-5 @4xl/detail:flex-row @4xl/detail:items-end @4xl/detail:justify-between">
        <div class="max-w-xl">
            <h2 id="program-plans-heading" class="text-xl font-bold text-balance">{{ __('programs.annual_plans') }}</h2>
            <p class="mt-1 text-sm text-secondary-medium dark:text-secondary-soft">{{ __('programs.plan_years_description') }}</p>
        </div>

        @if ($slots !== [])
            <dl class="grid grid-cols-3 divide-x divide-board-line overflow-hidden rounded-md border border-board-line bg-board rtl:divide-x-reverse">
                <div class="px-4 py-2.5">
                    <dt class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('programs.plan_years') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1 tabular-nums"><span class="text-xl font-bold">{{ $planned->count() }}</span><span class="text-sm text-secondary-medium dark:text-secondary-soft">/ {{ count($slots) }}</span></dd>
                </div>
                <div class="px-4 py-2.5">
                    <dt class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('common.targets') }}</dt>
                    <dd class="mt-0.5 text-xl font-bold tabular-nums">{{ $totalTargets }}</dd>
                </div>
                <div class="px-4 py-2.5">
                    <dt class="type-label text-secondary-medium dark:text-secondary-soft">{{ __('common.reports') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1 tabular-nums"><span class="text-xl font-bold">{{ $totalApproved }}</span><span class="text-sm text-secondary-medium dark:text-secondary-soft">/ {{ $totalReports }}</span></dd>
                </div>
            </dl>
        @endif
    </header>

    @if ($slots === [])
        <x-ui.empty-state icon="calendar-days" :heading="__('common.no_annual_plans_yet')" :body="__('programs.operational_plans_written_for_this_program_will_appear_here')" />
    @else
        <div class="bg-ground px-4 py-6 @3xl/detail:px-8 @3xl/detail:py-8">
            <div class="mb-5 flex flex-wrap items-center gap-x-4 gap-y-1 type-meta text-secondary-medium dark:text-secondary-soft">
                <span>{{ __('programs.plan_years_planned', ['planned' => $planned->count(), 'total' => count($slots)]) }}</span>
                @if ($strategy !== null)
                    <span class="inline-flex items-center gap-1"><flux:icon.flag variant="micro" class="size-3.5" aria-hidden="true" />{{ __('programs.plan_strategy_window', ['start' => $strategy->start_year, 'end' => $strategy->end_year]) }}</span>
                @endif
                <span class="inline-flex items-center gap-3 @3xl/detail:ms-auto" aria-hidden="true">
                    @foreach (ResultType::cases() as $type)
                        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full {{ $levelMarks[$type->value] }}"></span>{{ $type->label() }}</span>
                    @endforeach
                </span>
            </div>

            <div
                class="relative"
                x-data="{
                    atStart: true,
                    atEnd: true,
                    measure() {
                        const track = this.$refs.track;
                        const offset = Math.abs(track.scrollLeft);
                        this.atStart = offset < 4;
                        this.atEnd = offset + track.clientWidth >= track.scrollWidth - 4;
                    },
                    step(direction) {
                        const track = this.$refs.track;
                        const sign = getComputedStyle(track).direction === 'rtl' ? -1 : 1;
                        track.scrollBy({ left: direction * sign * track.clientWidth * 0.8, behavior: 'smooth' });
                    },
                }"
                x-init="$nextTick(() => {
                    const current = $refs.track.querySelector('[data-current-year]');
                    if (current && $refs.track.scrollWidth > $refs.track.clientWidth) {
                        $refs.track.scrollLeft = current.offsetLeft - ($refs.track.clientWidth - current.offsetWidth) / 2;
                    }
                    measure();
                })"
                x-on:resize.window.debounce.100ms="measure()"
            >
                <ol x-ref="track" x-on:scroll.passive="measure()" class="relative grid gap-4 ps-8 [scrollbar-width:thin] @3xl/detail:auto-cols-[minmax(15.5rem,20rem)] @3xl/detail:grid-flow-col @3xl/detail:justify-center-safe @3xl/detail:gap-0 @3xl/detail:overflow-x-auto @3xl/detail:ps-0 @3xl/detail:pb-3">
                    {{-- Narrow layout: the timeline axis runs down the inline-start edge. --}}
                    <span aria-hidden="true" class="absolute start-3 top-2 bottom-2 w-0.5 bg-board-line @3xl/detail:hidden"></span>

                    @foreach ($slots as $slot)
                        @php
                            $plan = $slot['plan'];
                            $isCurrent = $slot['year'] === $currentYear;
                            $isClosed = $slot['year'] < $currentYear;
                            $status = match (true) {
                                $isCurrent => __('programs.plan_status_current'),
                                $isClosed => __('programs.plan_status_closed'),
                                default => __('programs.plan_status_upcoming'),
                            };
                        @endphp

                        <li
                            class="relative @3xl/detail:px-2 @3xl/detail:pt-16"
                            wire:key="plan-slot-{{ $slot['year'] }}"
                            @if ($isCurrent) data-current-year @endif
                        >
                            {{-- Wide layout: axis segment, year tick and label. --}}
                            <span aria-hidden="true" @class([
                                'absolute top-4 hidden h-0.5 @3xl/detail:top-10 @3xl/detail:block',
                                'start-1/2' => $loop->first,
                                'start-0' => ! $loop->first,
                                'end-1/2' => $loop->last,
                                'end-0' => ! $loop->last,
                                'bg-secondary-light' => $isClosed,
                                'bg-board-line' => ! $isClosed,
                            ])></span>
                            @if ($isCurrent)
                                <span aria-hidden="true" @class(['absolute start-0 top-10 hidden h-0.5 w-1/2 bg-secondary-light @3xl/detail:block', 'invisible' => $loop->first])></span>
                            @endif

                            <span aria-hidden="true" @class([
                                'absolute top-4 -translate-y-1/2 rounded-full border-2 bg-board @3xl/detail:top-10',
                                '-translate-x-1/2 rtl:translate-x-1/2' => true,
                                'size-4 border-primary ring-4 ring-primary/15' => $isCurrent,
                                'size-3 border-secondary-light' => ! $isCurrent && $plan !== null,
                                'size-2.5 border-board-line' => ! $isCurrent && $plan === null,
                                '-start-5 @3xl/detail:start-1/2' => true,
                            ])></span>

                            @if ($isCurrent)
                                <span class="absolute top-10 hidden -translate-y-[calc(100%+0.75rem)] rounded-full bg-primary px-2 py-0.5 text-[0.6875rem] font-bold text-white @3xl/detail:start-1/2 @3xl/detail:block @3xl/detail:-translate-x-1/2 rtl:@3xl/detail:translate-x-1/2">{{ __('programs.plan_now') }}</span>
                            @endif

                            @if ($plan === null)
                                <div class="flex h-full min-h-40 flex-col items-center justify-center gap-2 rounded-md border border-dashed border-secondary-light px-4 py-6 text-center">
                                    <p class="text-lg font-bold text-secondary-medium tabular-nums dark:text-secondary-soft">{{ __('common.fy_year', ['year' => $slot['year']]) }}</p>
                                    <p class="type-meta text-secondary-medium dark:text-secondary-soft">{{ __('programs.plan_no_plan_written') }}</p>
                                </div>
                            @else
                                @php
                                    $levelTotal = array_sum($slot['levels']);
                                    $reportShare = $slot['reports'] > 0 ? round($slot['approved'] / $slot['reports'] * 100) : 0;
                                @endphp

                                <a
                                    href="{{ route('yearly-plans.show', $plan) }}"
                                    wire:navigate
                                    wire:key="program-plan-{{ $plan->id }}"
                                    @class([
                                        'group relative flex h-full flex-col overflow-hidden rounded-md border bg-board transition-[border-color,box-shadow] duration-200 hover:border-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                                        'border-primary shadow-[0_18px_40px_-24px_rgb(0_0_0/0.4)]' => $isCurrent,
                                        'border-board-line shadow-[0_10px_28px_-24px_rgb(0_0_0/0.35)] hover:shadow-[0_18px_40px_-24px_rgb(0_0_0/0.4)]' => ! $isCurrent,
                                    ])
                                >
                                    @if ($isCurrent)
                                        <span aria-hidden="true" class="absolute inset-x-0 top-0 h-1 bg-primary"></span>
                                    @endif

                                    <div class="px-4 pt-4">
                                        <div class="flex items-center justify-between gap-2">
                                            <p @class(['type-label', 'text-primary' => $isCurrent, 'text-secondary-medium dark:text-secondary-soft' => ! $isCurrent])>{{ $status }}</p>
                                            <flux:icon.arrow-up-right variant="micro" class="size-4 text-secondary-light transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 group-hover:text-primary rtl:-scale-x-100" aria-hidden="true" />
                                        </div>
                                        <p class="mt-1 text-xl font-bold text-balance tabular-nums">{{ __('common.fy_year', ['year' => $slot['year']]) }}</p>

                                        @if ($isCurrent)
                                            <div class="mt-2">
                                                <div class="h-1 overflow-hidden rounded-full bg-board-line" aria-hidden="true"><div class="h-full rounded-full bg-primary" style="width: {{ $yearElapsed }}%"></div></div>
                                                <p class="mt-1 type-meta text-secondary-medium dark:text-secondary-soft">{{ __('programs.plan_year_elapsed', ['percent' => $yearElapsed]) }}</p>
                                            </div>
                                        @endif
                                    </div>

                                    <div class="mt-4 border-t border-board-line px-4 py-3">
                                        <div class="flex items-baseline gap-2">
                                            <span class="type-figure">{{ $slot['targets'] }}</span>
                                            <span class="text-sm font-semibold text-secondary-medium dark:text-secondary-soft">{{ trans_choice('programs.plan_targets_word', $slot['targets']) }}</span>
                                        </div>

                                        @if ($slot['targets'] === 0)
                                            <p class="mt-2 inline-flex items-center gap-1 type-meta text-status-warn"><flux:icon.exclamation-triangle variant="micro" class="size-3.5" aria-hidden="true" />{{ __('common.no_targets_yet') }}</p>
                                        @else
                                            <div class="mt-2 flex h-2 gap-0.5 overflow-hidden rounded-full" role="img" aria-label="{{ __('programs.plan_targets_by_level') }}: {{ collect(ResultType::cases())->map(fn ($type) => $type->label().' '.$slot['levels'][$type->value])->join(', ') }}">
                                                @foreach (ResultType::cases() as $type)
                                                    @if ($slot['levels'][$type->value] > 0)
                                                        <span class="{{ $levelMarks[$type->value] }}" style="flex-grow: {{ $slot['levels'][$type->value] }}"></span>
                                                    @endif
                                                @endforeach
                                                @if ($levelTotal === 0)
                                                    <span class="grow bg-board-line"></span>
                                                @endif
                                            </div>
                                            <p class="mt-2 type-meta text-secondary-medium dark:text-secondary-soft">{{ __('programs.plan_target_scope', ['attributed' => $slot['attributed'], 'wide' => $slot['targets'] - $slot['attributed']]) }}</p>
                                        @endif
                                    </div>

                                    <div class="border-t border-board-line px-4 py-3">
                                        <div class="flex items-center justify-between gap-2 type-meta">
                                            <span class="inline-flex items-center gap-1 text-secondary-medium dark:text-secondary-soft"><flux:icon.document-check variant="micro" class="size-3.5" aria-hidden="true" />{{ trans_choice('programs.plan_reports_approved', $slot['reports'], ['count' => $slot['reports'], 'approved' => $slot['approved']]) }}</span>
                                            @if ($slot['reports'] > 0)
                                                <span class="font-semibold tabular-nums">{{ $reportShare }}%</span>
                                            @endif
                                        </div>
                                        <div class="mt-2 h-1 overflow-hidden rounded-full bg-board-line" aria-hidden="true"><div class="h-full rounded-full bg-status-good" style="width: {{ $reportShare }}%"></div></div>
                                    </div>

                                    <div class="mt-auto flex items-center gap-2 border-t border-board-line px-4 py-3">
                                        <flux:icon.user-circle variant="micro" class="size-4 shrink-0 text-secondary-light" aria-hidden="true" />
                                        <span class="truncate type-meta">{{ $plan->manager?->name ?? __('common.not_assigned') }}</span>
                                    </div>
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ol>

                @foreach (['start' => -1, 'end' => 1] as $edge => $direction)
                    <button
                        type="button"
                        x-show="! {{ $edge === 'start' ? 'atStart' : 'atEnd' }}"
                        x-transition.opacity
                        x-on:click="step({{ $direction }})"
                        aria-label="{{ __($edge === 'start' ? 'programs.plan_scroll_back' : 'programs.plan_scroll_forward') }}"
                        @class([
                            'absolute top-32 z-10 hidden size-10 cursor-pointer items-center justify-center rounded-full border border-secondary-dark bg-secondary-dark text-white shadow-lg hover:border-primary hover:bg-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary @3xl/detail:flex',
                            '-start-3' => $edge === 'start',
                            '-end-3' => $edge === 'end',
                        ])
                    >
                        <flux:icon :name="$edge === 'start' ? 'chevron-left' : 'chevron-right'" variant="micro" class="size-4 rtl:rotate-180" aria-hidden="true" />
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</section>
