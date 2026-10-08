        {{-- Layer one: the planning arc, and the year it points the cockpit at. --}}
        <section class="overflow-hidden rounded-xl border border-board-line bg-board" aria-labelledby="planning-arc-heading">
            <div class="flex flex-wrap items-end justify-between gap-3 border-b border-board-line bg-board-head px-5 py-4">
                <div class="min-w-0">
                    <h2 id="planning-arc-heading" class="text-base font-bold">{{ __('annual_plans.planning_arc') }}</h2>
                    <p class="mt-0.5 type-meta text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.planning_arc_hint') }}</p>
                </div>
                <p class="type-meta text-secondary-medium tabular-nums dark:text-ink-muted">
                    @if ($yearRange !== null)
                        {{-- A gappy selection cannot be described as a span: "2023–2027" would claim columns that are not on screen. --}}
                        @if ($yearRange['contiguous'])
                            {{ __('annual_plans.fiscal_years_from_to', ['from' => $yearRange['from'], 'to' => $yearRange['to']]) }}
                        @else
                            {{ __('annual_plans.fiscal_years_list', ['years' => $yearRange['list']]) }}
                        @endif
                        @if ($yearRange['clamped'])
                            <span class="font-normal">· {{ __('annual_plans.narrowed_to_count_columns', ['count' => count($yearColumns)]) }}</span>
                        @endif
                    @else
                        {{ __('annual_plans.no_fiscal_years_to_show') }}
                    @endif
                </p>
            </div>

            <div class="grid gap-0 @4xl/matrix:grid-cols-[minmax(0,1fr)_22rem]">
                <div class="px-5 pt-6 pb-5 @4xl/matrix:border-e @4xl/matrix:border-board-line" dir="ltr">
                    <div class="mb-4 flex flex-wrap items-center gap-4 type-meta text-secondary-medium dark:text-ink-muted">
                        <span class="inline-flex items-center gap-2">
                            <span aria-hidden="true" class="h-0.5 w-5 rounded-full bg-primary dark:bg-ink"></span>
                            {{ __('annual_plans.chart_series_plans') }}
                        </span>
                        <span class="inline-flex items-center gap-2">
                            <span aria-hidden="true" class="h-0 w-5 border-t-2 border-dashed border-secondary-medium dark:border-ink-muted"></span>
                            {{ __('annual_plans.chart_series_targets') }}
                        </span>
                    </div>

                    {{-- The arc itself: decorative and aria-hidden, since every data point is restated by a focusable, labelled year button below it. --}}
                    <div class="relative" style="aspect-ratio: {{ $arcViewW }} / {{ $arcViewH }}" aria-hidden="true">
                        <svg class="size-full overflow-visible" viewBox="0 0 {{ $arcViewW }} {{ $arcViewH }}">
                            <path d="{{ $arcPlanArea }}" class="fill-secondary-soft dark:fill-line-strong" />

                            <path
                                d="{{ $arcTargetLine }}"
                                class="fill-none stroke-secondary-medium dark:stroke-ink-muted"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-dasharray="1 8"
                            />

                            <path
                                d="{{ $arcPlanLine }}"
                                class="arc-line-draw fill-none stroke-primary dark:stroke-ink"
                                style="--arc-length: 1600"
                                stroke-width="3"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />

                            @foreach ($arcPoints as $point)
                                <circle cx="{{ $point['x'] }}" cy="{{ $point['targetY'] }}" r="2.5" class="fill-secondary-medium dark:fill-ink-muted" />
                            @endforeach

                            @foreach ($arcPoints as $point)
                                @if ($point['rail']['isActive'])
                                    <circle cx="{{ $point['x'] }}" cy="{{ $point['planY'] }}" r="9" class="fill-none stroke-primary dark:stroke-ink" stroke-width="1.5" />
                                @endif
                                <circle
                                    cx="{{ $point['x'] }}" cy="{{ $point['planY'] }}"
                                    r="{{ $point['rail']['isActive'] ? 5.5 : 4 }}"
                                    stroke-width="2"
                                    @class([
                                        'fill-primary dark:fill-ink stroke-primary dark:stroke-ink' => $point['rail']['isActive'],
                                        'fill-board stroke-primary dark:stroke-ink' => ! $point['rail']['isActive'],
                                    ])
                                />
                            @endforeach
                        </svg>

                        <div class="pointer-events-none absolute inset-0">
                            @foreach ($arcPoints as $point)
                                <span
                                    class="absolute -translate-x-1/2 -translate-y-[calc(100%+10px)] text-sm font-bold tabular-nums text-secondary-dark dark:text-ink"
                                    style="left: {{ $point['xPct'] }}%; top: {{ $point['planYPct'] }}%;"
                                >{{ $point['rail']['plans'] }}</span>
                            @endforeach
                        </div>
                    </div>

                    {{-- The year axis: the real controls, restated in full for assistive tech via each button's label. --}}
                    <div class="relative mt-2 h-16">
                        @foreach ($arcPoints as $point)
                            @php
                                $rail = $point['rail'];
                            @endphp
                            <div class="absolute top-0 flex -translate-x-1/2 flex-col items-center gap-1.5" style="left: {{ $point['xPct'] }}%" wire:key="rail-{{ $rail['year'] }}">
                                <button
                                    type="button"
                                    wire:click="focusOnYear({{ $rail['year'] }})"
                                    aria-pressed="{{ $rail['isActive'] ? 'true' : 'false' }}"
                                    aria-label="{{ __('annual_plans.focus_year_plans_targets', ['year' => $rail['year'], 'plans' => $rail['plans'], 'targets' => $rail['targets']]) }}"
                                    @class([
                                        'min-h-9 cursor-pointer rounded-md px-2.5 py-1 text-sm font-bold tabular-nums transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                                        'bg-primary dark:bg-ink dark:text-surface-0 text-white' => $rail['isActive'],
                                        'text-secondary-dark hover:bg-secondary-soft dark:text-ink dark:hover:bg-surface-2' => ! $rail['isActive'],
                                    ])
                                >{{ $rail['year'] }}</button>

                                <span @class([
                                    'type-label rounded-full bg-primary dark:bg-brand px-2 py-0.5 text-white',
                                    'invisible' => ! $rail['isCurrent'],
                                ])>{{ __('annual_plans.now') }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($cockpit !== null)
                    <aside class="border-t border-board-line bg-board-head px-5 py-5 @4xl/matrix:border-t-0" aria-labelledby="cockpit-heading">
                        <span id="cockpit-heading" class="sr-only">{{ __('annual_plans.focused_fiscal_year') }}</span>

                        <div class="flex items-center gap-5">
                            <div class="relative size-24 shrink-0" role="img" aria-label="{{ __('annual_plans.portfolio_covered') }}: {{ $gaugeShare }}%">
                                <svg viewBox="0 0 120 120" class="size-full -rotate-90" aria-hidden="true">
                                    <circle cx="60" cy="60" r="{{ $gaugeRadius }}" class="fill-none stroke-board-line" stroke-width="10" />
                                    <circle
                                        cx="60" cy="60" r="{{ $gaugeRadius }}"
                                        class="fill-none stroke-primary dark:stroke-ink transition-[stroke-dashoffset] duration-500 ease-out motion-reduce:transition-none"
                                        stroke-width="10"
                                        stroke-linecap="round"
                                        stroke-dasharray="{{ $gaugeCircumference }}"
                                        stroke-dashoffset="{{ $gaugeOffset }}"
                                    />
                                </svg>
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <span class="text-xl font-bold tabular-nums text-secondary-dark dark:text-ink" aria-hidden="true">{{ $gaugeShare }}%</span>
                                </div>
                            </div>

                            <div class="min-w-0">
                                <div class="flex items-baseline gap-2">
                                    <h3 class="text-3xl font-bold tracking-tight tabular-nums" aria-hidden="true">{{ $cockpit['year'] }}</h3>
                                    @if ($cockpit['plansDelta'] !== null && $cockpit['plansDelta'] !== 0)
                                        <span @class([
                                            'type-meta font-semibold tabular-nums',
                                            'text-status-good' => $cockpit['plansDelta'] > 0,
                                            'text-secondary-medium dark:text-ink-muted' => $cockpit['plansDelta'] < 0,
                                        ])>{{ $cockpit['plansDelta'] > 0 ? '+' : '' }}{{ $cockpit['plansDelta'] }} {{ __('annual_plans.vs_previous_year') }}</span>
                                    @endif
                                </div>
                                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.portfolio_covered') }}</p>
                                <p class="mt-1.5 type-meta text-secondary-medium tabular-nums dark:text-ink-muted">
                                    {{ __('annual_plans.coverage_with_gaps', ['percent' => $gaugeShare, 'gaps' => $cockpit['gaps']]) }}
                                </p>
                            </div>
                        </div>

                        <dl class="mt-5 grid grid-cols-3 divide-x divide-board-line overflow-hidden rounded-md border border-board-line bg-board rtl:divide-x-reverse">
                            <div class="px-3 py-2.5">
                                <dt class="type-label text-secondary-medium dark:text-ink-muted">{{ __('common.targets') }}</dt>
                                <dd class="mt-0.5 text-xl font-bold tabular-nums">{{ $cockpit['targets'] }}</dd>
                            </div>
                            <div class="px-3 py-2.5">
                                <dt class="type-label text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.approved') }}</dt>
                                <dd class="mt-0.5 flex items-baseline gap-1 tabular-nums"><span class="text-xl font-bold">{{ $cockpit['approved'] }}</span><span class="text-sm text-secondary-medium dark:text-ink-muted">/ {{ $cockpit['reports'] }}</span></dd>
                            </div>
                            <div class="px-3 py-2.5">
                                <dt class="type-label text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.managers') }}</dt>
                                <dd class="mt-0.5 text-xl font-bold tabular-nums">{{ $cockpit['managers'] }}</dd>
                            </div>
                        </dl>
                    </aside>
                @endif
            </div>
        </section>
