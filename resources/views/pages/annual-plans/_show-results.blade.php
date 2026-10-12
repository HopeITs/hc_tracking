    {{-- The logframe connection: every result this plan measures, and the targets committed under it, one aligned row per target. --}}
    <x-ui.panel :title="__('annual_plans.results_and_targets')" :count="$summary['targets']" :subtitle="__('annual_plans.how_this_plans_targets_connect_to_the_logframe')">
        <x-slot:toolbar>
            <a href="{{ route('targets.index', ['sourceType' => 'annual_plan']) }}" wire:navigate class="text-xs font-semibold text-secondary-medium hover:underline dark:text-ink-muted">
                {{ __('annual_plans.all_annual_plan_targets') }}
            </a>
        </x-slot:toolbar>

        @if ($resultGroups->isEmpty())
            <x-ui.empty-state icon="chart-bar" :heading="__('common.no_targets_yet')" :body="__('annual_plans.indicator_targets_committed_in_this_plan_will_appear_here')" />
        @else
            {{-- Column headings, shown once for the whole table rather than per result group. --}}
            <div class="hidden border-b border-board-line bg-board-head px-4 py-2 @2xl/detail:grid @2xl/detail:grid-cols-[minmax(0,1fr)_7rem_8rem_6rem]">
                <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.indicator_and_location') }}</span>
                <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('common.period') }}</span>
                <span class="type-label text-end text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.committed_value') }}</span>
                <span class="type-label text-end text-secondary-medium dark:text-ink-muted">{{ __('common.reports') }}</span>
            </div>

            <div class="divide-y divide-board-line">
                @foreach ($resultGroups as $group)
                    @php
                        $result = $group['result'];
                    @endphp
                    <div wire:key="result-group-{{ $result->id }}">
                        <div class="flex flex-wrap items-start gap-x-2.5 gap-y-1.5 bg-board-head px-4 py-2.5">
                            <x-ui.enum-badge :case="$result->type" />
                            <p class="min-w-0 flex-1 text-sm font-semibold">{{ $result->statement }}</p>
                            <a href="{{ route('results.index', ['program' => $plan->program_id]) }}" wire:navigate class="shrink-0 type-meta font-semibold text-secondary-medium hover:underline dark:text-ink-muted">
                                {{ __('annual_plans.open_in_logframe') }}
                            </a>
                        </div>

                        <div class="divide-y divide-board-line">
                            @foreach ($group['targets'] as $target)
                                @php
                                    $targetHref = $target->project !== null ? route('projects.show', ['project' => $target->project, 'tab' => 'targets']) : route('indicators.index', ['result' => $result->id]);
                                @endphp
                                <a
                                    href="{{ $targetHref }}" wire:navigate
                                    class="grid grid-cols-1 gap-x-4 gap-y-1.5 px-4 py-3 transition-colors @2xl/detail:grid-cols-[minmax(0,1fr)_7rem_8rem_6rem] @2xl/detail:items-center hover:bg-board-head focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary"
                                    wire:key="plan-target-{{ $target->id }}"
                                >
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-semibold">{{ $target->indicator->name }}</span>
                                        <span class="block truncate type-meta text-secondary-medium dark:text-ink-muted">
                                            @if ($target->project === null)
                                                {{ __('common.organisation_wide') }}
                                            @else
                                                {{ $target->project->name }} · {{ $target->project->city?->name ?? __('common.unknown_city') }}
                                            @endif
                                        </span>
                                    </span>

                                    <span class="mt-1 @2xl/detail:mt-0"><x-ui.enum-badge :case="$target->period" /></span>

                                    <span class="text-sm font-semibold tabular-nums @2xl/detail:text-end">{{ number_format((float) $target->target, 2) }}</span>

                                    <span class="@2xl/detail:text-end">
                                        <span
                                            @class([
                                                'inline-flex min-w-6 items-center justify-center gap-1 rounded-md border px-1.5 py-0.5 text-[11px] font-semibold tabular-nums',
                                                'border-status-good text-status-good' => $target->reports_count > 0,
                                                'border-secondary-light text-secondary-medium dark:text-ink-muted' => $target->reports_count === 0,
                                            ])
                                            title="{{ trans_choice('common.count_report', $target->reports_count, ['count' => $target->reports_count]) }}"
                                        >
                                            <flux:icon.document-text variant="micro" class="size-3" aria-hidden="true" />
                                            {{ $target->reports_count }}
                                        </span>
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-ui.panel>
