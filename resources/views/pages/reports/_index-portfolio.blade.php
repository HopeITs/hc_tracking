        <x-ui.panel :subtitle="__('reports.reports_grouped_by_project_and_indicator_so_the_connection_b')">
            <x-slot:toolbar>
                @if ($filtersAreActive)
                    <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-ink-muted!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
                @endif
            </x-slot:toolbar>

            @if ($this->portfolio->isEmpty())
                <x-ui.empty-state
                    icon="rectangle-group"
                    :heading="$filtersAreActive ? __('reports.no_matching_reports') : __('reports.no_reports_yet')"
                    :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('reports.record_the_first_figure_against_a_target')"
                />
            @else
                <div class="divide-y-4 divide-ground dark:divide-surface-0">
                    @foreach ($this->portfolio as $group)
                        <div wire:key="portfolio-project-{{ $group['project']?->id ?? 'org' }}">
                            {{-- Project header: name, indicator count, and a rollup badge so the manager can triage projects before opening one. --}}
                            <div class="flex flex-wrap items-center justify-between gap-3 bg-board-head px-4 py-3 dark:bg-surface-2">
                                <div class="flex items-baseline gap-2">
                                    @if ($group['project'] !== null)
                                        <a href="{{ route('projects.show', $group['project']) }}" wire:navigate class="text-base font-extrabold tracking-tight hover:underline">{{ $group['project']->name }}</a>
                                    @else
                                        <p class="text-base font-extrabold tracking-tight">{{ __('common.organisation_wide') }}</p>
                                    @endif
                                    <span class="type-meta text-secondary-medium dark:text-ink-muted">
                                        {{ trans_choice('projects.count_indicator', $group['indicators']->count(), ['count' => $group['indicators']->count()]) }}
                                        · {{ trans_choice('common.count_report', $group['reportCount'], ['count' => $group['reportCount']]) }}
                                    </span>
                                </div>

                                @if ($group['avgAchievement'] !== null)
                                    <span class="inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold tabular-nums {{ $achievementBadgeClass($group['avgAchievement']) }}">
                                        {{ __('reports.avg_target_achievement') }} {{ $group['avgAchievement'] }}%
                                    </span>
                                @endif
                            </div>

                            <div class="divide-y divide-secondary-soft dark:divide-line">
                                @foreach ($group['indicators'] as $indicatorGroup)
                                    @php
                                        $planIndicator = $indicatorGroup['planIndicator'];
                                        $latest = $indicatorGroup['latest'];
                                        $achievement = $indicatorGroup['achievement'];
                                        $points = $sparklinePoints($indicatorGroup['trend']);
                                        $barWidth = $achievement === null ? 0 : max(2, min(100, $achievement));
                                        $unit = $unitOf($planIndicator);
                                    @endphp
                                    <div
                                        wire:key="portfolio-indicator-{{ $planIndicator->id }}"
                                        class="group grid grid-cols-1 gap-3 px-4 py-4 transition-colors duration-150 hover:bg-board-head @lg/directory:grid-cols-[1fr_auto] @lg/directory:items-center @lg/directory:gap-6"
                                    >
                                        <div class="min-w-0">
                                            <p class="type-meta text-secondary-medium dark:text-ink-muted">
                                                {{ $sourceLabel($planIndicator) }} · {{ $planIndicator->period->label() }} · {{ trans_choice('common.count_report', $indicatorGroup['reports']->count(), ['count' => $indicatorGroup['reports']->count()]) }}
                                            </p>
                                            <p class="truncate text-base font-bold">{{ $planIndicator->indicator->name }}</p>

                                            <div class="mt-2.5 flex items-center gap-3">
                                                <div
                                                    class="h-1.5 w-full max-w-64 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2"
                                                    role="progressbar"
                                                    aria-valuemin="0"
                                                    aria-valuemax="100"
                                                    aria-valuenow="{{ $barWidth }}"
                                                    aria-label="{{ __('reports.achievement') }}"
                                                >
                                                    <div class="h-full rounded-full {{ $achievementBarClass($achievement) }}" style="width: {{ $barWidth }}%"></div>
                                                </div>

                                                @if ($points !== null)
                                                    <svg viewBox="0 0 100 28" preserveAspectRatio="none" class="h-5 w-16 shrink-0" aria-hidden="true">
                                                        <polyline
                                                            points="{{ $points }}"
                                                            fill="none"
                                                            stroke-width="2.5"
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            class="{{ str_replace('bg-', 'stroke-', $achievementBarClass($achievement)) }}"
                                                        />
                                                    </svg>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="flex items-center justify-between gap-4 @lg/directory:flex-col @lg/directory:items-end @lg/directory:justify-center @lg/directory:gap-1.5">
                                            <div class="text-end">
                                                <p class="text-xl leading-none font-extrabold tabular-nums {{ $achievementTextClass($achievement) }}">{{ $achievement === null ? '—' : $achievement.'%' }}</p>
                                                <p class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted">
                                                    {{ __('reports.achieved_of_target', ['value' => $formatFigure($planIndicator->achievedValue()).$unit, 'target' => $formatFigure((float) $planIndicator->target).$unit]) }}
                                                </p>
                                            </div>

                                            <div class="flex shrink-0 items-center gap-1.5">
                                                <x-ui.enum-badge :case="$latest->status" />
                                                <x-ui.button tone="ghost" size="sm" icon="eye" class="size-9!" :href="route('reports.show', $latest)" wire:navigate :aria-label="__('common.view_name', ['name' => $planIndicator->indicator->name])" />
                                                @if ($latest->is_seen_by_meal)
                                                    <flux:tooltip :content="__('reports.seen_by_meal')">
                                                        <flux:icon.eye variant="micro" class="size-4 text-status-good" />
                                                    </flux:tooltip>
                                                @endif
                                                <flux:icon.chevron-right variant="micro" class="size-4 shrink-0 text-secondary-light transition-transform duration-200 group-hover:translate-x-0.5 rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5" />
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
