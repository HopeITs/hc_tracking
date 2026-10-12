    {{-- Plan pulse: how much of the plan is being reported on, and how that reporting is landing. --}}
    <section class="overflow-hidden rounded-xl border border-board-line bg-board" aria-labelledby="plan-pulse-heading">
        <div class="border-b border-board-line bg-board-head px-5 py-4">
            <h2 id="plan-pulse-heading" class="text-base font-bold">{{ __('annual_plans.plan_pulse') }}</h2>
            <p class="mt-0.5 type-meta text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.plan_pulse_hint') }}</p>
        </div>

        <div class="grid gap-0 @4xl/detail:grid-cols-[20rem_minmax(0,1fr)]">
            <div class="flex items-center gap-5 border-b border-board-line px-5 py-5 @4xl/detail:border-e @4xl/detail:border-b-0">
                <div class="relative size-24 shrink-0" role="img" aria-label="{{ __('annual_plans.targets_reported_on') }}: {{ $summary['reportingShare'] }}%">
                    <svg viewBox="0 0 120 120" class="size-full -rotate-90" aria-hidden="true">
                        <circle cx="60" cy="60" r="{{ $gaugeRadius }}" class="fill-none stroke-board-line" stroke-width="10" />
                        <circle
                            cx="60" cy="60" r="{{ $gaugeRadius }}"
                            class="fill-none stroke-primary transition-[stroke-dashoffset] duration-500 ease-out motion-reduce:transition-none"
                            stroke-width="10"
                            stroke-linecap="round"
                            stroke-dasharray="{{ $gaugeCircumference }}"
                            stroke-dashoffset="{{ $gaugeOffset }}"
                        />
                    </svg>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <span class="text-xl font-bold tabular-nums text-secondary-dark dark:text-ink" aria-hidden="true">{{ $summary['reportingShare'] }}%</span>
                    </div>
                </div>

                <div class="min-w-0">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.targets_reported_on') }}</p>
                    <p class="mt-1.5 type-figure font-bold tabular-nums">{{ $summary['reported'] }}<span class="type-body font-normal text-secondary-medium dark:text-ink-muted"> / {{ $summary['targets'] }}</span></p>
                    <p class="mt-1.5 type-meta text-secondary-medium dark:text-ink-muted">
                        {{ trans_choice('annual_plans.count_target_awaiting_first_report', $summary['unreported'], ['count' => $summary['unreported']]) }}
                    </p>
                </div>
            </div>

            <div class="flex flex-col justify-center gap-3 px-5 py-5">
                <div class="flex items-baseline justify-between gap-2">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.report_review_mix') }}</p>
                    <p class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">{{ trans_choice('common.count_report', $summary['reportsTotal'], ['count' => $summary['reportsTotal']]) }}</p>
                </div>

                @if ($summary['reportsTotal'] === 0)
                    <p class="type-meta text-secondary-light">{{ __('annual_plans.no_reports_submitted_yet') }}</p>
                @else
                    <div class="flex h-2.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" role="img" aria-label="{{ __('annual_plans.approved') }} {{ $summary['reportsApproved'] }}, {{ \App\Enums\ReportStatus::Reviewed->label() }} {{ $summary['reportsReviewed'] }}, {{ \App\Enums\ReportStatus::Draft->label() }} {{ $summary['reportsDraft'] }}">
                        @foreach ($reportSegments as $segment)
                            @if ($segment['count'] > 0)
                                <span class="{{ $segment['class'] }} h-full" style="width: {{ $summary['reportsTotal'] > 0 ? round($segment['count'] / $summary['reportsTotal'] * 100, 2) : 0 }}%"></span>
                            @endif
                        @endforeach
                    </div>

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 type-meta text-secondary-medium dark:text-ink-muted">
                        @foreach ($reportSegments as $segment)
                            <span class="inline-flex items-center gap-1.5">
                                <span aria-hidden="true" class="{{ $segment['class'] }} size-2 shrink-0 rounded-full"></span>
                                {{ $segment['label'] }} <span class="font-semibold tabular-nums text-secondary-dark dark:text-ink">{{ $segment['count'] }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 border-t border-board-line bg-board-head p-4 @2xl/detail:grid-cols-4">
            <x-ui.stat-tile :label="__('common.targets')" :value="$summary['targets']" icon="flag" />
            <x-ui.stat-tile :label="__('common.projects')" :value="$summary['projects']" icon="map-pin" />
            <x-ui.stat-tile :label="__('common.organisation_wide')" :value="$summary['orgWide']" icon="building-office" />
            <x-ui.stat-tile :label="__('annual_plans.results_touched')" :value="$summary['results']" icon="squares-2x2" />
        </div>

        @if ($periodMix !== [])
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-board-line px-5 py-3 type-meta text-secondary-medium dark:text-ink-muted">
                <span class="font-semibold text-secondary-dark dark:text-ink">{{ __('annual_plans.reporting_rhythm') }}</span>
                @foreach ($periodMix as $row)
                    <span class="inline-flex items-center gap-1.5">
                        <x-ui.enum-badge :case="$row['period']" />
                        <span class="tabular-nums">{{ $row['count'] }} · {{ $row['share'] }}%</span>
                    </span>
                @endforeach
            </div>
        @endif
    </section>
