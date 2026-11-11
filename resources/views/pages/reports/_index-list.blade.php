{{--
    The reports feed: one row per report with the indicator it reports on, where
    it belongs, one status word, the figure against its target, and the single
    action most people come for (Open). Edit and delete sit in the ⋯ menu and
    only for drafts. Included by ⚡index.blade.php, which defines $achievement*
    and $unitOf and $sourceLabel in its top PHP block.
--}}
        <x-ui.panel :subtitle="trans_choice('reports.count_matching_report', $this->reports->total(), ['count' => $this->reports->total()])">
            <x-slot:toolbar>
                @if ($filtersAreActive)
                    <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-ink-muted!" wire:click="clearFilters" data-test="reset-filters">{{ __('common.reset_filters') }}</flux:button>
                @endif
            </x-slot:toolbar>

            @if ($this->reports->isEmpty())
                <x-ui.empty-state
                    icon="document-chart-bar"
                    :heading="$filtersAreActive ? __('reports.no_matching_reports') : __('reports.no_reports_yet')"
                    :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('reports.record_the_first_figure_against_a_target')"
                />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-line" data-test="report-list">
                    @foreach ($this->reports as $report)
                        @php
                            $rowTarget = $report->planIndicator;
                            $rowUnit = $unitOf($rowTarget);
                            // The target's own achievement (approved reports only), not this one figure against it.
                            $rowPercent = $rowTarget->percentAchieved();
                            $rowAchievement = $rowPercent === null ? null : (int) floor($rowPercent);
                            $rowStatus = match (true) {
                                $report->isOverdue() => 'late',
                                $report->status === App\Enums\ReportStatus::Draft => 'draft',
                                $report->status === App\Enums\ReportStatus::Reviewed => 'waiting',
                                default => 'approved',
                            };
                            $statusTone = match ($rowStatus) {
                                'late' => 'text-status-critical',
                                'waiting' => 'text-status-warn',
                                'approved' => 'text-status-good',
                                default => 'text-secondary-dark dark:text-ink',
                            };
                            $statusDot = match ($rowStatus) {
                                'late' => 'bg-status-critical',
                                'waiting' => 'bg-status-warn',
                                'approved' => 'bg-status-good',
                                default => 'bg-secondary-light dark:bg-ink-faint',
                            };
                            $canEditRow = $report->isEditable() && auth()->user()->can('update', $report);
                            $canDeleteRow = $report->isEditable() && auth()->user()->can('delete', $report);
                        @endphp
                        <article class="group grid gap-3 px-4 py-4 transition-colors duration-150 hover:bg-board-head md:grid-cols-[minmax(0,1fr)_minmax(11rem,15rem)_auto] md:items-center" wire:key="report-{{ $report->id }}" data-status="{{ $rowStatus }}">
                            <div class="min-w-0">
                                <p class="flex items-center gap-1.5 text-base font-bold leading-snug">
                                    @if (in_array($report->id, $this->unreadIds, true))
                                        <span class="size-2 shrink-0 rounded-full bg-primary dark:bg-ink" data-unread-dot="report-{{ $report->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                                    @endif
                                    <bdi>{{ $rowTarget->indicator->name }}</bdi>
                                </p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 type-meta text-secondary-medium dark:text-ink-muted">
                                    <span><bdi>{{ $rowTarget->project?->name ?? __('common.organisation_wide') }}</bdi></span>
                                    <span aria-hidden="true">·</span>
                                    <span><bdi>{{ $sourceLabel($rowTarget) }}</bdi></span>
                                    <span aria-hidden="true">·</span>
                                    <span>{{ $report->planned_date?->translatedFormat(__('formats.date')) }}</span>
                                </p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 type-meta text-secondary-medium dark:text-ink-muted">
                                    <x-ui.user-chip :user="$report->user" compact />
                                    @if ($report->monthlyReport !== null)
                                        <a href="{{ route('monthly-reports.show', $report->monthlyReport) }}" wire:navigate class="inline-flex items-center gap-1 rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold hover:bg-secondary-soft dark:border-line-strong dark:hover:bg-white/[0.06]" data-monthly-report-chip="{{ $report->id }}">
                                            <flux:icon.calendar-days variant="micro" class="size-3" aria-hidden="true" />
                                            {{ __('reports.monthly_report_chip') }}
                                        </a>
                                    @endif
                                </p>
                            </div>

                            <div class="min-w-0">
                                <p class="inline-flex items-center gap-1.5 text-sm font-semibold {{ $statusTone }}" data-test="report-status-{{ $report->id }}">
                                    <span aria-hidden="true" class="size-2 shrink-0 rounded-full {{ $statusDot }}"></span>
                                    {{ __('reports.row_status_'.$rowStatus) }}
                                </p>
                                <p class="mt-1 text-sm tabular-nums" dir="ltr">
                                    <span class="font-bold">{{ $formatFigure((float) $report->value) }}{{ $rowUnit }}</span>
                                    <span class="text-secondary-medium dark:text-ink-muted">{{ __('reports.of_target', ['target' => $formatFigure((float) $rowTarget->target).$rowUnit]) }}</span>
                                </p>
                                <div class="md:min-h-4">
                                    @if ($rowAchievement !== null)
                                        <p class="type-meta font-semibold {{ $achievementTextClass($rowAchievement) }}" title="{{ __('reports.target_achieved_approved_only') }}">{{ __('reports.percent_achieved', ['percent' => $rowAchievement]) }}</p>
                                    @endif
                                </div>
                                <div class="md:min-h-4">
                                    @if ($report->is_seen_by_meal)
                                        <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('reports.seen_by_meal') }}</p>
                                    @elseif ($rowStatus === 'waiting')
                                        <p class="type-meta font-semibold text-status-warn">{{ __('targets.not_yet_seen_by_meal') }}</p>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center gap-1 md:grid md:grid-cols-[max-content_2.25rem]" data-report-row-actions>
                                <x-ui.button size="sm" icon="arrows-pointing-out" class="min-h-11 md:min-h-9" :href="route('reports.show', $report)" wire:navigate :aria-label="__('common.view_name', ['name' => $rowTarget->indicator->name])" data-test="open-report-{{ $report->id }}">
                                    {{ __('reports.open_report') }}
                                </x-ui.button>

                                {{-- Only a draft can change or go: a reviewed or approved figure is the record. --}}
                                @if ($canEditRow || $canDeleteRow || ! $report->isEditable())
                                    <flux:dropdown align="end">
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" class="min-h-11 md:min-h-9" :aria-label="__('reports.more_actions_for_name', ['name' => $rowTarget->indicator->name])" data-test="report-menu-{{ $report->id }}" />
                                        <flux:menu>
                                            @if ($canEditRow)
                                                <flux:menu.item icon="pencil-square" wire:click="openEditModal({{ $report->id }})">{{ __('reports.edit_report') }}</flux:menu.item>
                                            @endif
                                            @if ($canDeleteRow)
                                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDeleteReport({{ $report->id }})" data-test="delete-report-{{ $report->id }}">{{ __('reports.delete_report') }}</flux:menu.item>
                                            @endif
                                            @if (! $report->isEditable())
                                                <flux:menu.item icon="lock-closed" disabled>{{ __('reports.only_draft_reports_can_be_edited') }}</flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
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
