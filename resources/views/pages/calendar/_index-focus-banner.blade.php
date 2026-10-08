{{--
    Above the grid on calendar/index: the "showing one type" banner when the
    calendar is focused on a type, otherwise the colour legend. Included by
    ⚡index.blade.php, whose top PHP block defines $focusedType,
    $typeDotClasses and $typeLabels.
--}}
        @if ($focusedType !== null)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-secondary-soft bg-secondary-soft/30 px-4 py-3 dark:border-line dark:bg-white/[0.04]" data-test="calendar-focused-banner">
                <div class="flex items-center gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-white ring-1 ring-secondary-soft dark:bg-surface-1 dark:ring-line">
                        <span class="size-2.5 rounded-full {{ $typeDotClasses[$focusedType] }}"></span>
                    </span>
                    <div>
                        <p class="text-sm font-bold text-secondary-dark dark:text-ink">{{ __('calendar.showing_type', ['type' => $typeLabels[$focusedType]]) }}</p>
                        <p class="text-xs text-secondary-medium dark:text-ink-muted">{{ trans_choice('calendar.items_count', $this->focusedItems->count(), ['count' => $this->focusedItems->count()]) }}</p>
                    </div>
                </div>
                <x-ui.button size="sm" wire:click="resetFilters" data-test="calendar-clear-focus">{{ __('calendar.clear_filter') }}</x-ui.button>
            </div>
        @else
            <div class="flex flex-wrap items-center gap-4 text-xs text-secondary-medium dark:text-ink-muted">
                <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full {{ $typeDotClasses['milestone'] }}"></span>{{ __('tasks.milestone') }}</span>
                <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full {{ $typeDotClasses['task'] }}"></span>{{ __('tasks.task') }}</span>
                <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full {{ $typeDotClasses['report'] }}"></span>{{ __('calendar.report_deadline') }}</span>
                @if ($this->canSeeType('proposal'))
                    <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full {{ $typeDotClasses['proposal'] }}"></span>{{ __('calendar.proposal_deadline') }}</span>
                @endif
                @if ($this->canSeeType('transfer'))
                    <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full {{ $typeDotClasses['transfer'] }}"></span>{{ __('calendar.transfer') }}</span>
                @endif
                @if ($this->canSeeType('donor'))
                    <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full {{ $typeDotClasses['donor'] }}"></span>{{ __('calendar.donor_follow_up') }}</span>
                @endif
                <span class="flex items-center gap-1.5"><span class="size-2 shrink-0 rounded-full {{ $typeDotClasses['project'] }}"></span>{{ __('calendar.project_timeline') }}</span>
            </div>
        @endif
