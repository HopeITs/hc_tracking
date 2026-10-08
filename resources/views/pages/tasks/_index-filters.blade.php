{{--
    What sits between the page header and the task views: the stat tiles (each
    a quick filter), the "My Tasks" rail, and the view switcher with the
    group/sort controls and the reset and "my tasks only" toggles. Included by
    ⚡index.blade.php, whose top PHP block defines $filtersAreActive.
--}}
@use('App\Enums\TaskStatus')
    <div class="grid gap-3 @2xl/directory:grid-cols-2 @4xl/directory:grid-cols-3 @6xl/directory:grid-cols-5">
        <button type="button" wire:click="clearFilters" data-test="task-stat-total" @class(['text-start transition-transform', 'scale-[0.98]' => ! $filtersAreActive])>
            <x-ui.stat-tile :label="__('tasks.total_tasks')" :value="$this->taskStats['total']" icon="clipboard-document-check" />
        </button>
        <button type="button" wire:click="toggleStatusFilter('{{ TaskStatus::Done->value }}')" data-test="task-stat-done" @class(['text-start transition-transform', 'scale-[0.98] ring-2 ring-status-good rounded-xl' => $status === TaskStatus::Done->value])>
            <x-ui.stat-tile :label="TaskStatus::Done->label()" :value="$this->taskStats['done']" icon="check-circle" tone="good" />
        </button>
        <button type="button" wire:click="toggleDueFilter('overdue')" data-test="task-stat-overdue" @class(['text-start transition-transform', 'scale-[0.98] ring-2 ring-status-critical rounded-xl' => $dueFilter === 'overdue'])>
            <x-ui.stat-tile :label="__('tasks.overdue')" :value="$this->taskStats['overdue']" icon="exclamation-triangle" tone="critical" />
        </button>
        <button type="button" wire:click="toggleDueFilter('missed')" data-test="task-stat-missed" @class(['text-start transition-transform', 'scale-[0.98] ring-2 ring-status-critical rounded-xl' => $dueFilter === 'missed'])>
            <x-ui.stat-tile :label="__('tasks.missed')" :value="$this->taskStats['missed']" icon="exclamation-circle" tone="critical" />
        </button>
        <button type="button" wire:click="toggleDueFilter('due_soon')" data-test="task-stat-due-soon" @class(['text-start transition-transform', 'scale-[0.98] ring-2 ring-status-warn rounded-xl' => $dueFilter === 'due_soon'])>
            <x-ui.stat-tile :label="__('tasks.due_within_7_days')" :value="$this->taskStats['due_soon']" icon="clock" tone="warn" />
        </button>
    </div>

    <div class="flex flex-col gap-3 rounded-2xl border border-secondary-soft bg-secondary-soft/20 p-4 dark:border-line dark:bg-white/[0.03]" data-test="my-tasks-rail">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <flux:icon name="user-circle" variant="micro" class="size-5 text-primary dark:text-brand-text" aria-hidden="true" />
                <h2 class="text-sm font-bold text-secondary-dark dark:text-ink">{{ __('tasks.my_tasks') }}</h2>
            </div>
            @if ($this->myTasksPreview->isNotEmpty())
                <button type="button" wire:click="toggleMyTasksOnly" class="text-xs font-semibold text-primary dark:text-brand-text hover:underline" data-test="my-tasks-view-all">
                    {{ $myTasksOnly ? __('tasks.showing_my_tasks_only') : __('tasks.view_all') }}
                </button>
            @endif
        </div>
        @if ($this->myTasksPreview->isNotEmpty())
            @php $viewerEmployeeId = auth()->user()->employee_id; @endphp
            <div class="flex gap-3 overflow-x-auto pb-1">
                @foreach ($this->myTasksPreview as $myTask)
                    @php $isAssignedToMe = $viewerEmployeeId !== null && $myTask->taskAssignees->pluck('employee_id')->contains($viewerEmployeeId); @endphp
                    <div
                        role="button"
                        tabindex="0"
                        @can('update', $myTask)
                            wire:click="openEditDrawer({{ $myTask->id }})"
                            x-on:keydown="if (! $event.target.closest('a') && ($event.key === 'Enter' || $event.key === ' ')) { $event.preventDefault(); $wire.openEditDrawer({{ $myTask->id }}) }"
                        @else
                            {{-- Only the status is theirs to change: open the task's own page. --}}
                            x-on:click="if (! $event.target.closest('a')) { Livewire.navigate({{ Illuminate\Support\Js::from(route('tasks.show', $myTask)) }}) }"
                            x-on:keydown="if (! $event.target.closest('a') && ($event.key === 'Enter' || $event.key === ' ')) { $event.preventDefault(); Livewire.navigate({{ Illuminate\Support\Js::from(route('tasks.show', $myTask)) }}) }"
                        @endcan
                        wire:key="my-task-{{ $myTask->id }}"
                        data-test="my-task-card-{{ $myTask->id }}"
                        @class([
                            'flex w-64 shrink-0 flex-col gap-2 rounded-xl border p-3 text-start shadow-sm transition-shadow hover:shadow-md dark:bg-surface-1',
                            'border-primary/30 bg-white dark:border-status-critical/30' => $isAssignedToMe,
                            'border-dashed border-secondary-light bg-secondary-soft/30 dark:border-line dark:bg-white/[0.03]' => ! $isAssignedToMe,
                        ])
                    >
                        <div class="flex items-center justify-between gap-2">
                            <x-ui.status-pill :case="$myTask->status" />
                            @if ($isAssignedToMe)
                                <span class="inline-flex shrink-0 items-center gap-1 text-[10px] font-bold text-primary dark:text-status-critical" data-test="my-task-role-assigned-{{ $myTask->id }}">
                                    <flux:icon.user variant="micro" class="size-3" aria-hidden="true" />
                                    {{ __('tasks.assigned_to_you') }}
                                </span>
                            @endif
                        </div>
                        @unless ($isAssignedToMe)
                            @php $assigneeName = $myTask->taskAssignees->first()?->employee?->name; @endphp
                            @if ($assigneeName !== null)
                                <span class="inline-flex items-start gap-1 text-[10px] font-semibold text-secondary-medium dark:text-ink-muted" data-test="my-task-role-delegated-{{ $myTask->id }}">
                                    <flux:icon.paper-airplane variant="micro" class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                                    <span class="break-words">{{ __('tasks.assigned_to_name', ['name' => $assigneeName]) }}</span>
                                </span>
                            @endif
                        @endunless
                        <a
                            href="{{ route('tasks.show', $myTask) }}"
                            wire:navigate
                            title="{{ $myTask->title }}"
                            x-on:click.stop
                            class="truncate text-sm font-semibold text-secondary-dark hover:text-primary hover:underline dark:text-ink dark:hover:text-status-critical"
                            data-test="my-task-title-{{ $myTask->id }}"
                        >{{ $myTask->title }}</a>
                        <div class="flex items-center justify-between text-xs text-secondary-medium dark:text-ink-muted">
                            <span @class(['font-semibold text-status-critical' => $myTask->isOverdue() || $myTask->isMissed()])>
                                {{ $myTask->due_date?->translatedFormat('M j') ?? __('tasks.no_due_date') }}
                            </span>
                            <x-ui.enum-badge :case="$myTask->priority" />
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-secondary-medium dark:text-ink-muted" data-test="my-tasks-empty">{{ __('common.no_tasks_yet') }}</p>
        @endif
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex flex-wrap gap-1 rounded-lg border border-secondary-soft bg-secondary-soft/40 p-1 dark:border-line dark:bg-surface-2" role="group" aria-label="{{ __('tasks.view') }}">
            @foreach ([
                'list' => ['icon' => 'queue-list', 'label' => __('tasks.list_view')],
                'board' => ['icon' => 'view-columns', 'label' => __('tasks.board_view')],
                'calendar' => ['icon' => 'calendar-days', 'label' => __('tasks.calendar_view')],
            ] as $viewOption => $viewMeta)
                <button
                    type="button"
                    wire:click="$set('view', '{{ $viewOption }}')"
                    aria-pressed="{{ $view === $viewOption ? 'true' : 'false' }}"
                    data-test="task-view-{{ $viewOption }}"
                    @class([
                        'inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-md px-3 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                        'bg-white text-secondary-dark shadow-sm dark:bg-surface-3 dark:shadow-raised dark:text-ink' => $view === $viewOption,
                        'text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => $view !== $viewOption,
                    ])
                >
                    <flux:icon :name="$viewMeta['icon']" variant="micro" class="size-4" aria-hidden="true" />
                    {{ $viewMeta['label'] }}
                </button>
            @endforeach
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            @if ($view === 'list')
                <x-ui.header-filter-select :label="__('tasks.group_by')" wire:model.live="group" :value="$group" class="w-40!">
                    <option value="none">{{ __('tasks.no_grouping') }}</option>
                    <option value="status">{{ __('common.status') }}</option>
                    <option value="priority">{{ __('tasks.priority') }}</option>
                    <option value="assignee">{{ __('tasks.assignee') }}</option>
                    <option value="milestone">{{ __('tasks.milestone') }}</option>
                </x-ui.header-filter-select>
                <x-ui.header-filter-select :label="__('tasks.sort_by')" wire:model.live="sortField" :value="$sortField" class="w-40!">
                    <option value="due">{{ __('tasks.due_date_2') }}</option>
                    <option value="title">{{ __('common.name') }}</option>
                    <option value="status">{{ __('common.status') }}</option>
                    <option value="priority">{{ __('tasks.priority') }}</option>
                    <option value="assignee">{{ __('tasks.assignee') }}</option>
                    <option value="created">{{ __('tasks.created_date') }}</option>
                </x-ui.header-filter-select>
                <x-ui.button
                    size="sm"
                    wire:click="$set('sortDirection', '{{ $sortDirection === 'asc' ? 'desc' : 'asc' }}')"
                    :aria-label="$sortDirection === 'asc' ? __('tasks.sort_ascending') : __('tasks.sort_descending')"
                    data-test="task-sort-direction-toggle"
                >
                    <flux:icon :name="$sortDirection === 'asc' ? 'bars-arrow-up' : 'bars-arrow-down'" variant="micro" class="size-4" aria-hidden="true" />
                </x-ui.button>
            @endif

            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="self-end" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
            @endif

            <button
                type="button"
                wire:click="toggleMyTasksOnly"
                aria-pressed="{{ $myTasksOnly ? 'true' : 'false' }}"
                data-test="task-filter-my-tasks-only"
                @class([
                    'inline-flex shrink-0 items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-primary',
                    'border-primary bg-primary dark:bg-ink dark:text-surface-0 dark:border-ink text-white' => $myTasksOnly,
                    'border-secondary-light text-secondary-medium hover:bg-secondary-soft dark:text-ink-muted dark:hover:bg-surface-2' => ! $myTasksOnly,
                ])
            >
                <flux:icon.user variant="micro" class="size-3.5" />
                {{ __('tasks.my_tasks_only') }}
            </button>
        </div>
    </div>
