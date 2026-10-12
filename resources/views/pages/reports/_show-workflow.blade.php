{{-- The workflow panel on ⚡show.blade.php: the three-step progress, status chips and the workflow action buttons. --}}
@use('App\Enums\ReportStatus')
    <x-ui.panel :title="__('reports.workflow')" :subtitle="__('reports.each_action_moves_the_report_through_draft_reviewed_and_appr')">
        <div class="flex flex-col gap-4 p-3.5">
            @php
                $steps = [ReportStatus::Draft, ReportStatus::Reviewed, ReportStatus::Approved];
                $currentIndex = array_search($report->status, $steps, true);
            @endphp

            <ol class="flex items-center" role="list" aria-label="{{ __('reports.workflow') }}">
                @foreach ($steps as $index => $step)
                    @php
                        $reached = $index <= $currentIndex;
                        $isCurrent = $index === $currentIndex;
                    @endphp
                    <li class="flex flex-1 items-center last:flex-none" wire:key="workflow-step-{{ $step->value }}">
                        <div class="flex flex-col items-center gap-1.5">
                            <span @class([
                                'flex size-8 items-center justify-center rounded-full border-2 text-xs font-bold',
                                'border-primary bg-primary dark:bg-ink-muted dark:border-ink-muted dark:text-surface-0 text-white' => $reached,
                                'border-secondary-light text-secondary-light dark:border-ink-faint dark:text-ink-faint' => ! $reached,
                            ])>
                                @if ($reached && ! $isCurrent)
                                    <flux:icon.check variant="micro" class="size-4" />
                                @else
                                    {{ $index + 1 }}
                                @endif
                            </span>
                            <span @class([
                                'type-meta whitespace-nowrap',
                                'font-semibold text-secondary-dark dark:text-ink' => $isCurrent,
                                'text-secondary-medium dark:text-ink-muted' => ! $isCurrent,
                            ])>{{ $step->label() }}</span>
                        </div>

                        @unless ($loop->last)
                            <span @class([
                                'mx-2 h-0.5 flex-1',
                                'bg-primary dark:bg-ink-muted' => $index < $currentIndex,
                                'bg-secondary-soft dark:bg-line-strong' => $index >= $currentIndex,
                            ])></span>
                        @endunless
                    </li>
                @endforeach
            </ol>

            <div class="flex flex-wrap items-center gap-3 border-t border-secondary-soft pt-3.5 dark:border-line">
                <div class="flex flex-wrap items-center gap-2 me-auto">
                    @if ($report->submit_date !== null)
                        <span class="rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-ink-muted">
                            {{ __('reports.submitted_date', ['date' => $report->submit_date->translatedFormat(__('formats.date'))]) }}
                        </span>
                    @endif

                    @if ($report->is_seen_by_meal)
                        <span class="rounded-md border border-status-good/30 bg-status-good-soft px-2 py-0.5 text-xs font-semibold text-status-good">{{ __('reports.seen_by_meal') }}</span>
                    @else
                        <span class="rounded-md border border-status-warn/30 bg-status-warn-soft px-2 py-0.5 text-xs font-semibold text-status-warn">{{ __('reports.awaiting_meal_review') }}</span>
                    @endif

                    @unless ($report->isEditable())
                        <span class="rounded-md border border-primary bg-primary dark:bg-white/10 dark:text-ink dark:border-line px-2 py-0.5 text-xs font-semibold text-white">{{ __('reports.locked') }}</span>
                    @endunless
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    {{-- Maker-checker: the author changes and submits; a second person approves (see ReportPolicy). --}}
                    @can('update', $report)
                        <x-ui.button size="sm" icon="pencil-square" wire:click="openEditModal({{ $report->id }})" data-test="edit-report-button">{{ __('reports.edit_report') }}</x-ui.button>
                    @endcan
                    @can('delete', $report)
                        <x-ui.button size="sm" icon="trash" wire:click="confirmDeleteReport({{ $report->id }})" data-test="delete-report-button">{{ __('reports.delete_report') }}</x-ui.button>
                    @endcan

                    @can('submit', $report)
                        <x-ui.button size="sm" tone="primary" icon="paper-airplane" wire:click="submitForReview" data-test="submit-for-review-button">{{ __('reports.submit_for_review') }}</x-ui.button>
                    @endcan

                    @can('approve', $report)
                        <x-ui.button size="sm" tone="primary" icon="check" wire:click="approve" data-test="approve-button">{{ __('reports.approve') }}</x-ui.button>
                    @endcan

                    @can('returnToDraft', $report)
                        <x-ui.button size="sm" icon="arrow-uturn-left" class="rtl:[&_svg]:-scale-x-100" wire:click="returnToDraft" data-test="return-to-draft-button">{{ __('reports.return_to_draft') }}</x-ui.button>
                    @endcan

                    @if (! $report->is_seen_by_meal && auth()->user()->can('markSeen', $report))
                        <x-ui.button size="sm" icon="eye" wire:click="markSeenByMeal" data-test="mark-seen-button">{{ __('reports.mark_seen_by_meal') }}</x-ui.button>
                    @endif
                </div>
            </div>
        </div>
    </x-ui.panel>
