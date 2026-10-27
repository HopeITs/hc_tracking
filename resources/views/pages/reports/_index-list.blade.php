        <x-ui.panel :subtitle="trans_choice('reports.count_matching_report', $this->reports->total(), ['count' => $this->reports->total()])">
            <x-slot:toolbar>
                @if ($filtersAreActive)
                    <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-ink-muted!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
                @endif
            </x-slot:toolbar>

            @if ($this->reports->isEmpty())
                <x-ui.empty-state
                    icon="document-chart-bar"
                    :heading="$filtersAreActive ? __('reports.no_matching_reports') : __('reports.no_reports_yet')"
                    :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('reports.record_the_first_figure_against_a_target')"
                />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-line">
                    @foreach ($this->reports as $report)
                        @php
                            $rowTarget = $report->planIndicator;
                            $rowUnit = $unitOf($rowTarget);
                            // The target's own achievement (approved reports only), not this one figure against it.
                            $rowPercent = $rowTarget->percentAchieved();
                            $rowAchievement = $rowPercent === null ? null : (int) floor($rowPercent);
                            $rowIsOverdue = $report->isOverdue();
                        @endphp
                        <article class="group flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-4 transition-colors duration-150 hover:bg-board-head" wire:key="report-{{ $report->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-x-1.5 type-meta text-secondary-medium dark:text-ink-muted">
                                    <span>{{ $sourceLabel($rowTarget) }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span>{{ $report->planned_date?->translatedFormat(__('formats.date')) }}</span>
                                    @if ($rowIsOverdue)
                                        <span class="inline-flex items-center gap-1 rounded-md border border-primary bg-status-critical-soft px-1.5 text-[11px] font-semibold text-primary dark:text-brand-text">
                                            <flux:icon.clock variant="micro" class="size-3" aria-hidden="true" />
                                            {{ __('reports.overdue') }}
                                        </span>
                                    @endif
                                </p>
                                <p class="flex items-center gap-1.5 text-base font-bold">
                                    @if (in_array($report->id, $this->unreadIds, true))
                                        <span class="size-2 shrink-0 rounded-full bg-primary dark:bg-ink" data-unread-dot="report-{{ $report->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                                    @endif
                                    {{ $rowTarget->indicator->name }}
                                </p>
                                <p class="truncate text-xs text-secondary-medium dark:text-ink-muted">
                                    @if ($rowTarget->project !== null)
                                        <a href="{{ route('projects.show', $rowTarget->project) }}" wire:navigate class="hover:text-secondary-dark hover:underline dark:hover:text-ink">{{ $rowTarget->project->name }}</a>
                                    @else
                                        {{ __('common.organisation_wide') }}
                                    @endif
                                    · {{ trans_choice('reports.count_file', $report->files_count, ['count' => $report->files_count]) }}
                                </p>
                            </div>

                            <div class="text-end">
                                <p class="text-xl leading-none font-extrabold tabular-nums">{{ $formatFigure((float) $report->value) }}{{ $rowUnit }}</p>
                                <p class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted">
                                    {{ __('reports.of_target', ['target' => $formatFigure((float) $rowTarget->target).$rowUnit]) }}
                                    @if ($rowAchievement !== null)
                                        <span class="font-semibold {{ $achievementTextClass($rowAchievement) }}" title="{{ __('reports.target_achieved_approved_only') }}">· {{ __('reports.percent_achieved', ['percent' => $rowAchievement]) }}</span>
                                    @endif
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-1.5">
                                <x-ui.enum-badge :case="$report->status" />

                                @if ($report->monthlyReport !== null)
                                    <a href="{{ route('monthly-reports.show', $report->monthlyReport) }}" wire:navigate class="inline-flex items-center gap-1 rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold hover:bg-secondary-soft dark:border-line-strong dark:hover:bg-white/[0.06]" data-monthly-report-chip="{{ $report->id }}">
                                        <flux:icon.calendar-days variant="micro" class="size-3" aria-hidden="true" />
                                        {{ __('reports.monthly_report_chip') }}
                                    </a>
                                @endif

                                @if ($report->is_seen_by_meal)
                                    <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-ink-muted">{{ __('reports.meal') }}</span>
                                @endif
                            </div>

                            <x-ui.user-chip :user="$report->user" compact class="w-40" />

                            {{-- Only a draft can change or go: a reviewed or approved figure is the record. --}}
                            <div class="flex items-center gap-0.5">
                                <x-ui.button tone="ghost" size="sm" icon="eye" class="size-9!" :href="route('reports.show', $report)" wire:navigate :aria-label="__('common.view_name', ['name' => $rowTarget->indicator->name])" />
                                @if ($report->isEditable())
                                    @can('update', $report)
                                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $report->id }})" :aria-label="__('reports.edit_report')" />
                                    @endcan
                                    @can('delete', $report)
                                        <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDeleteReport({{ $report->id }})" :aria-label="__('reports.delete_report')" data-test="delete-report-{{ $report->id }}" />
                                    @endcan
                                @else
                                    <flux:tooltip :content="__('reports.only_draft_reports_can_be_edited')">
                                        <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                            <flux:icon.lock-closed class="size-4" />
                                        </span>
                                    </flux:tooltip>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif

            <x-slot:footer>
                <flux:pagination :paginator="$this->reports" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#report-directory-heading" />
            </x-slot:footer>
        </x-ui.panel>
