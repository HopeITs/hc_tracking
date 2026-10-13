{{--
    The toolbar of calendar/index: today, the view-mode picker, previous and
    next, the period heading, the type and responsible filters, and the add
    menu. Included by ⚡index.blade.php, whose top PHP block defines
    $periodHeading, $typeDotClasses and $selectedFilterCount.
--}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button size="sm" wire:click="goToToday" data-test="calendar-today-button">{{ __('common.today') }}</x-ui.button>

            <flux:dropdown position="bottom-start" class="filter-picker">
                <x-ui.button size="sm" icon:trailing="chevron-down" data-test="calendar-view-mode-button">{{ __('calendar.'.$viewMode) }}</x-ui.button>
                <flux:menu class="filter-menu">
                    <div class="px-2 pb-1 pt-1 text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-ink-muted">{{ __('calendar.time_period') }}</div>
                    @foreach ([['day', 'D'], ['four_days', '4'], ['week', 'W'], ['month', 'M']] as [$periodKey, $shortcut])
                        <flux:menu.item wire:click="setViewMode('{{ $periodKey }}')" data-test="calendar-view-mode-{{ $periodKey }}">
                            <span class="min-w-0 flex-1 truncate">{{ __('calendar.'.$periodKey) }}</span>
                            <kbd class="me-1 rounded border border-secondary-soft px-1.5 py-0.5 text-[10px] font-semibold dark:border-line">{{ $shortcut }}</kbd>
                            @if ($viewMode === $periodKey)<flux:icon.check class="size-4 shrink-0 text-primary dark:text-brand-text" />@endif
                        </flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            <div class="flex items-center gap-1">
                <x-ui.button size="sm" wire:click="{{ $viewMode === 'month' ? 'previousMonth' : 'previousPeriod' }}" :aria-label="__('common.previous')">
                    <flux:icon.chevron-left variant="micro" class="size-4 rtl:-scale-x-100" />
                </x-ui.button>
                <x-ui.button size="sm" wire:click="{{ $viewMode === 'month' ? 'nextMonth' : 'nextPeriod' }}" :aria-label="__('common.next')">
                    <flux:icon.chevron-right variant="micro" class="size-4 rtl:-scale-x-100" />
                </x-ui.button>
            </div>
            <flux:heading size="lg">{{ $periodHeading }}</flux:heading>
        </div>

        <div class="flex items-center gap-3">
            <div class="inline-flex items-center rounded-lg border border-secondary-light bg-white dark:border-field-line dark:bg-surface-1">
            <div class="filter-picker relative">
                <button
                    type="button"
                    data-flux-button
                    wire:click="$toggle('showTypeFilterMenu')"
                    aria-expanded="{{ $showTypeFilterMenu ? 'true' : 'false' }}"
                    aria-haspopup="listbox"
                    aria-label="{{ __('calendar.filter_by_type') }}"
                    data-test="calendar-type-filter-button"
                    class="inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-s-lg px-2.5 py-1.5 text-secondary-dark hover:bg-secondary-soft dark:text-ink dark:hover:bg-surface-2"
                >
                    <flux:icon.funnel variant="micro" class="size-4" />
                    <span class="hidden text-sm sm:inline">{{ __('calendar.filter_by_type') }}</span>
                </button>

                @if ($showTypeFilterMenu)
                    <div
                        x-on:click.outside="$wire.showTypeFilterMenu = false"
                        x-on:keydown.escape.window="$wire.showTypeFilterMenu = false"
                        data-flux-menu
                        role="listbox"
                        aria-multiselectable="true"
                        class="filter-menu absolute end-0 z-30 mt-1 min-w-44 rounded-lg border p-[.3125rem] shadow-xs"
                    >
                        @foreach ([
                            ['milestone', 'showMilestones', 'tasks.milestone', 'calendar-filter-milestones'],
                            ['task', 'showTasks', 'tasks.task', 'calendar-filter-tasks'],
                            ['report', 'showReports', 'calendar.report_deadline', 'calendar-filter-reports'],
                        ] as [$typeKey, $showProp, $labelKey, $testHook])
                            <div class="group flex items-center gap-1 rounded-md hover:bg-secondary-soft dark:hover:bg-white/[0.06]" role="option" aria-selected="{{ $$showProp ? 'true' : 'false' }}">
                                <button type="button" data-flux-menu-item wire:click="toggleType('{{ $typeKey }}')" class="flex min-w-0 flex-1 cursor-pointer items-center gap-2 px-2 py-1.5 text-start text-sm" data-test="{{ $testHook }}">
                                    <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses[$typeKey] }}"></span>
                                    <span class="min-w-0 flex-1 truncate">{{ __($labelKey) }}</span>
                                    @if ($$showProp)<flux:icon.check class="size-4 shrink-0 text-primary dark:text-brand-text" />@endif
                                </button>
                                <button type="button" wire:click="focusOnlyType('{{ $typeKey }}')" class="me-1 shrink-0 cursor-pointer rounded px-1.5 py-1 text-[10px] font-semibold text-secondary-medium opacity-0 hover:bg-white group-hover:opacity-100 dark:text-ink-muted dark:hover:bg-white/[0.06]" data-test="calendar-filter-only-{{ $typeKey }}">
                                    {{ __('calendar.only') }}
                                </button>
                            </div>
                        @endforeach

                        <div class="my-1 border-t border-secondary-soft dark:border-line"></div>

                        @foreach ([
                            ['proposal', 'showProposals', 'calendar.proposal_deadline', 'calendar-filter-proposals'],
                            ['transfer', 'showTransfers', 'calendar.transfer', 'calendar-filter-transfers'],
                            ['donor', 'showDonors', 'calendar.donor_follow_up', 'calendar-filter-donors'],
                            ['project', 'showProjects', 'calendar.project_timeline', 'calendar-filter-projects'],
                        ] as [$typeKey, $showProp, $labelKey, $testHook])
                            @continue(! $this->canSeeType($typeKey))
                            <div class="group flex items-center gap-1 rounded-md hover:bg-secondary-soft dark:hover:bg-white/[0.06]" role="option" aria-selected="{{ $$showProp ? 'true' : 'false' }}">
                                <button type="button" data-flux-menu-item wire:click="toggleType('{{ $typeKey }}')" class="flex min-w-0 flex-1 cursor-pointer items-center gap-2 px-2 py-1.5 text-start text-sm" data-test="{{ $testHook }}">
                                    <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses[$typeKey] }}"></span>
                                    <span class="min-w-0 flex-1 truncate">{{ __($labelKey) }}</span>
                                    @if ($$showProp)<flux:icon.check class="size-4 shrink-0 text-primary dark:text-brand-text" />@endif
                                </button>
                                <button type="button" wire:click="focusOnlyType('{{ $typeKey }}')" class="me-1 shrink-0 cursor-pointer rounded px-1.5 py-1 text-[10px] font-semibold text-secondary-medium opacity-0 hover:bg-white group-hover:opacity-100 dark:text-ink-muted dark:hover:bg-white/[0.06]" data-test="calendar-filter-only-{{ $typeKey }}">
                                    {{ __('calendar.only') }}
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="h-5 w-px shrink-0 bg-secondary-light dark:bg-line"></div>

            <x-ui.multi-select-popover
                :label="__('calendar.filter_by_responsible')"
                class="inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-e-lg px-2.5 py-1.5 text-secondary-dark hover:bg-secondary-soft dark:text-ink dark:hover:bg-surface-2"
                data-test="calendar-responsible-filter-button"
            >
                <x-slot:trigger>
                    <flux:icon.user variant="micro" class="size-4" />
                    <span class="hidden text-sm sm:inline">{{ __('calendar.filter_by_responsible') }}</span>
                    @if ($selectedFilterCount > 0)
                        <span class="inline-flex min-w-4 items-center justify-center rounded-full bg-primary dark:bg-white/10 dark:text-ink px-1 text-[10px] font-semibold text-white" data-test="calendar-responsible-filter-count">{{ $selectedFilterCount }}</span>
                    @endif
                </x-slot:trigger>

                <button type="button" wire:click="$set('filterEmployeeIds', [])" class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-white/[0.06]" data-test="calendar-responsible-filter-me">
                    <span class="min-w-0 flex-1 truncate">{{ __('calendar.me') }}</span>
                    @if ($filterEmployeeIds === [])<flux:icon.check class="ms-auto size-4 shrink-0 text-primary dark:text-brand-text" />@endif
                </button>
                <div class="my-1 border-t border-secondary-soft dark:border-line"></div>
                <div
                    wire:ignore
                    x-data="employeeCheckList"
                    x-effect="paint(optionSearch, touched)"
                    x-on:click="pick($event)"
                    class="contents"
                    data-employees-url="{{ route('employees.picker-options', ['l' => app()->getLocale()]) }}"
                    data-model="filterEmployeeIds"
                    data-role="option"
                    data-item-class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-white/[0.06]"
                    data-check-class="ms-auto size-4 shrink-0 text-primary dark:text-brand-text"
                ></div>
            </x-ui.multi-select-popover>
            </div>

            <flux:dropdown position="bottom-end" class="filter-picker">
                <x-ui.button tone="primary" icon="plus" icon:trailing="chevron-down" data-test="calendar-add-button">{{ __('common.add') }}</x-ui.button>
                <flux:menu class="filter-menu">
                    @can('create', App\Models\Milestone::class)
                        <flux:menu.item wire:click="openCreateModal" data-test="calendar-add-milestone-button">
                            <flux:icon.flag variant="micro" class="size-4" />
                            {{ __('milestones.add_milestone') }}
                        </flux:menu.item>
                    @endcan
                    @can('create', App\Models\Task::class)
                        <flux:menu.item wire:click="openCreateTaskModal" data-test="calendar-add-task-button">
                            <flux:icon.clipboard-document-check variant="micro" class="size-4" />
                            {{ __('tasks.add_task') }}
                        </flux:menu.item>
                    @endcan
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>
