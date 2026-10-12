@use('App\Enums\TaskPriority')
@use('App\Enums\TaskStatus')
@use('Illuminate\Support\Js')
                @forelse ($columnTasks as $task)
                    @php
                        $isDone = $task->status === TaskStatus::Done;
                        $isMissed = $task->isMissed();
                        $isOverdue = $task->isOverdue();
                        // What the viewer may do to this card (TaskPolicy).
                        $canUpdate = auth()->user()->can('update', $task);
                        $canMove = $canUpdate || auth()->user()->can('updateStatus', $task);
                        $canDelete = auth()->user()->can('delete', $task);
                        $canCreate = auth()->user()->can('create', App\Models\Task::class);
                        $isDueDateLocked = ! $canUpdate || $task->created_by !== auth()->id();
                        $assignedIds = $task->taskAssignees->pluck('employee_id')->all();
                        $boardAssignees = $task->taskAssignees->pluck('employee')->filter();
                    @endphp
                    <div
                        @class([
                            'group flex flex-col gap-2.5 rounded-xl border border-secondary-soft bg-white p-3 shadow-hairline transition-all duration-150 hover:-translate-y-0.5 hover:shadow-md dark:border-line dark:bg-surface-1',
                            'cursor-grab active:cursor-grabbing' => ! $isDone && $canMove,
                        ])
                        wire:key="task-board-card-{{ $task->id }}"
                        data-task-id="{{ $task->id }}"
                        draggable="{{ $isDone || ! $canMove ? 'false' : 'true' }}"
                        x-on:dragstart="$event.dataTransfer.setData('text/plain', JSON.stringify({ id: {{ $task->id }}, status: '{{ $case->value }}' })); $event.currentTarget.classList.add('opacity-40')"
                        x-on:dragend="$event.currentTarget.classList.remove('opacity-40')"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-1.5">
                                @if (in_array($task->id, $this->unreadIds, true))
                                    <span class="size-2 shrink-0 rounded-full bg-primary dark:bg-ink" data-unread-dot="task-{{ $task->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                                @endif
                                <a href="{{ route('tasks.show', $task) }}" wire:navigate class="min-w-0 truncate text-sm font-semibold text-secondary-dark hover:text-primary hover:underline dark:text-ink dark:hover:text-status-critical" data-test="task-board-card-{{ $task->id }}">
                                    {{ $task->title }}
                                </a>
                            </div>
                            <div class="flex shrink-0 items-center gap-0.5 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
                                @if ($canCreate)
                                    <x-ui.button
                                        tone="ghost"
                                        size="sm"
                                        icon="plus"
                                        class="size-7!"
                                        x-on:click="subtasksOpen[{{ $task->id }}] = true; $nextTick(() => $refs['subtaskAddInputBoard{{ $task->id }}']?.focus())"
                                        :aria-label="__('tasks.add_subtask')"
                                        data-test="task-board-add-subtask-{{ $task->id }}"
                                    />
                                @endif
                                @if ($canUpdate)
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-7!" wire:click="openEditDrawer({{ $task->id }})" :aria-label="__('tasks.edit_title', ['title' => $task->title])" />
                                @endif
                                @if ($canDelete)
                                    <x-ui.button tone="ghost" size="sm" icon="trash" class="size-7!" wire:click="confirmDelete({{ $task->id }})" :aria-label="__('tasks.delete_title', ['title' => $task->title])" />
                                @endif
                            </div>
                        </div>

                        <p class="-mt-1.5 truncate text-xs text-secondary-medium dark:text-ink-muted">{{ $task->sourceLabel() }}</p>

                        <div class="flex flex-wrap items-center gap-1.5 text-xs">
                            @if (! $canUpdate)
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-1 font-semibold {{ $priorityChipClasses($task->priority) }}" data-test="task-board-priority-{{ $task->id }}">
                                    <flux:icon.flag :variant="$task->priority === TaskPriority::Low ? 'outline' : 'solid'" class="size-3 shrink-0" />
                                    {{ $task->priority->label() }}
                                </span>
                            @else
                            <flux:dropdown position="bottom" align="start">
                                <button type="button" aria-label="{{ __('tasks.priority') }}: {{ $task->priority->label() }}" class="inline-flex cursor-pointer items-center gap-1 rounded-full px-2 py-1 font-semibold {{ $priorityChipClasses($task->priority) }}" data-test="task-board-priority-trigger-{{ $task->id }}">
                                    <flux:icon.flag :variant="$task->priority === TaskPriority::Low ? 'outline' : 'solid'" class="size-3 shrink-0" />
                                    {{ $task->priority->label() }}
                                </button>
                                <flux:menu>
                                    @foreach (TaskPriority::cases() as $priorityCase)
                                        <flux:menu.item wire:click="quickSetPriority({{ $task->id }}, '{{ $priorityCase->value }}')" :wire:key="'task-board-'.$task->id.'-priority-'.$priorityCase->value">
                                            <flux:icon.flag :variant="$priorityCase === TaskPriority::Low ? 'outline' : 'solid'" class="size-4 shrink-0 {{ $priorityIconClasses($priorityCase) }}" />
                                            <span class="min-w-0 truncate">{{ $priorityCase->label() }}</span>
                                            @if ($task->priority === $priorityCase)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                        </flux:menu.item>
                                    @endforeach
                                </flux:menu>
                            </flux:dropdown>
                            @endif

                            @if ($isMissed)
                                <span class="inline-flex items-center gap-1 rounded-full bg-status-critical-soft px-2 py-1 font-semibold text-primary dark:text-status-critical" data-test="task-board-missed-{{ $task->id }}">
                                    <flux:icon.exclamation-triangle variant="micro" class="size-3 shrink-0" aria-hidden="true" />
                                    {{ __('tasks.missed') }}
                                </span>
                            @endif

                            <div @class(['inline-flex items-center gap-1 rounded-full px-2 py-1 font-semibold', 'bg-status-critical-soft text-primary dark:text-status-critical' => $isOverdue, 'bg-secondary-soft text-secondary-medium dark:bg-white/10 dark:text-ink-muted' => ! $isOverdue])>
                                <flux:icon.calendar-days variant="micro" class="size-3 shrink-0" aria-hidden="true" />
                                <input
                                    type="date"
                                    value="{{ $task->due_date?->toDateString() }}"
                                    wire:change="rescheduleTask({{ $task->id }}, $event.target.value)"
                                    @disabled($isDueDateLocked)
                                    aria-label="{{ __('tasks.due_date_2') }}: {{ $task->title }}"
                                    title="{{ $isDueDateLocked ? __('tasks.only_the_task_creator_can_change_the_due_date') : '' }}"
                                    class="w-24 border-0 bg-transparent p-0 tabular-nums focus:ring-0 focus:outline-none disabled:cursor-not-allowed disabled:opacity-70"
                                    data-test="task-board-due-date-{{ $task->id }}"
                                />
                            </div>

                            @if ($task->subtasks_count > 0)
                                <button
                                    type="button"
                                    x-on:click="subtasksOpen[{{ $task->id }}] = !subtasksOpen[{{ $task->id }}]"
                                    :aria-expanded="!!subtasksOpen[{{ $task->id }}]"
                                    class="inline-flex items-center gap-1 rounded-full bg-secondary-soft px-2 py-1 font-semibold text-secondary-medium hover:bg-secondary-light/60 dark:bg-white/10 dark:text-ink-muted dark:hover:bg-surface-2"
                                    :aria-label="subtasksOpen[{{ $task->id }}] ? {{ Js::from(__('tasks.hide_subtasks')) }} : {{ Js::from(__('tasks.show_subtasks')) }}"
                                    data-test="task-board-subtasks-toggle-{{ $task->id }}"
                                >
                                    <flux:icon.rectangle-stack variant="micro" class="size-3 shrink-0" aria-hidden="true" />
                                    {{ $task->subtasks_count }}
                                </button>
                            @endif
                        </div>

                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2">
                            <div @class(['h-full rounded-full transition-[width]', 'bg-status-good' => $task->status === TaskStatus::Done, 'bg-primary dark:bg-ink-muted' => $task->status !== TaskStatus::Done]) style="width: {{ $task->progress_percentage }}%"></div>
                        </div>

                        {{-- Trigger only: the option list lives once in _assignee-popover.blade.php. --}}
                        <div
                            x-data
                            role="button"
                            @if ($canUpdate)
                            tabindex="0"
                            x-on:click="$store.assigneePicker.openFor({{ $task->id }}, {{ Js::from($assignedIds) }}, $el.getBoundingClientRect())"
                            x-on:keydown.enter="$store.assigneePicker.openFor({{ $task->id }}, {{ Js::from($assignedIds) }}, $el.getBoundingClientRect())"
                            x-on:keydown.space.prevent="$store.assigneePicker.openFor({{ $task->id }}, {{ Js::from($assignedIds) }}, $el.getBoundingClientRect())"
                            @endif
                            aria-haspopup="listbox"
                            x-bind:aria-expanded="$store.assigneePicker.open && $store.assigneePicker.taskId === {{ $task->id }} ? 'true' : 'false'"
                            aria-label="{{ __('tasks.assignees') }}: {{ $boardAssignees->isEmpty() ? __('common.not_assigned') : $boardAssignees->map->name->implode(', ') }}"
                            class="inline-flex cursor-pointer items-center -space-x-2 rtl:space-x-reverse"
                            data-test="task-board-assignee-trigger-{{ $task->id }}"
                        >
                                @if ($boardAssignees->isEmpty())
                                    <span class="inline-flex size-7 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light">
                                        <flux:icon.user-plus variant="micro" class="size-3.5" />
                                    </span>
                                @else
                                    @foreach ($boardAssignees->take(3) as $employee)
                                        <div class="group/avatar relative">
                                            <span class="inline-flex size-7 items-center justify-center rounded-full bg-secondary-dark text-[11px] font-semibold text-white ring-2 ring-white dark:bg-surface-3 dark:ring-surface-1">
                                                {{ Illuminate\Support\Str::of($employee->name)->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                                            </span>
                                            @if ($canUpdate)
                                            <button
                                                type="button"
                                                wire:click.stop="toggleAssignee({{ $task->id }}, {{ $employee->id }})"
                                                class="absolute -top-1 -end-1 hidden size-4 cursor-pointer items-center justify-center rounded-full bg-secondary-dark text-white hover:bg-primary dark:hover:bg-status-critical dark:hover:text-surface-0 group-hover/avatar:flex dark:bg-ink dark:text-surface-0"
                                                aria-label="{{ __('tasks.remove_assignee_name', ['name' => $employee->name]) }}"
                                                data-test="task-board-assignee-remove-{{ $task->id }}-{{ $employee->id }}"
                                            >
                                                <flux:icon.x-mark variant="micro" class="size-2.5" />
                                            </button>
                                            @endif
                                        </div>
                                    @endforeach
                                    @if ($boardAssignees->count() > 3)
                                        <span class="inline-flex size-7 items-center justify-center rounded-full bg-secondary-soft text-[10px] font-semibold text-secondary-dark ring-2 ring-white dark:bg-surface-3 dark:text-ink dark:ring-surface-1">
                                            +{{ $boardAssignees->count() - 3 }}
                                        </span>
                                    @endif
                                @endif
                        </div>

                        {{-- Non-pointer alternative to the drag-and-drop status change above,
                             matching the milestone board's established fallback pattern. --}}
                        <label class="block">
                            <span class="sr-only">{{ __('tasks.change_status') }}</span>
                            <select
                                wire:change="quickSetStatus({{ $task->id }}, $event.target.value)"
                                wire:loading.attr="disabled"
                                wire:target="quickSetStatus"
                                @disabled($isDone || ! $canMove)
                                title="{{ $isDone ? __('tasks.completed_tasks_cannot_change_status') : '' }}"
                                class="w-full rounded-lg border border-secondary-soft bg-white px-2 py-1.5 text-xs text-secondary-dark disabled:cursor-not-allowed disabled:opacity-60 dark:border-line dark:bg-surface-1 dark:text-ink"
                                data-test="task-quick-status-{{ $task->id }}"
                            >
                                @foreach (TaskStatus::cases() as $statusOption)
                                    <option value="{{ $statusOption->value }}" @selected($statusOption === $task->status)>{{ $statusOption->label() }}</option>
                                @endforeach
                            </select>
                        </label>

                        <div x-show="subtasksOpen[{{ $task->id }}]" x-cloak class="flex flex-col gap-1.5 rounded-lg bg-secondary-soft/30 p-2 dark:bg-white/[0.04]">
                            @foreach ($task->subtasks as $subtask)
                                @php $boardSubtaskAssignees = $subtask->taskAssignees->pluck('employee')->filter(); @endphp
                                <div class="flex flex-wrap items-center gap-1.5" wire:key="task-board-subtask-row-{{ $subtask->id }}" data-test="task-board-subtask-row-{{ $subtask->id }}">
                                    <button
                                        type="button"
                                        wire:click="toggleComplete({{ $subtask->id }})"
                                        @disabled($subtask->status === TaskStatus::Done || ! auth()->user()->can('updateStatus', $subtask))
                                        aria-label="{{ __('tasks.mark_complete') }}"
                                        class="inline-flex size-5 shrink-0 cursor-pointer items-center justify-center rounded-full text-secondary-medium hover:bg-secondary-soft disabled:cursor-not-allowed disabled:opacity-70 disabled:hover:bg-transparent dark:text-ink-muted dark:hover:bg-surface-2"
                                    >
                                        <flux:icon.check-circle :variant="$subtask->status === TaskStatus::Done ? 'solid' : 'outline'" class="size-4 {{ $subtask->status === TaskStatus::Done ? 'text-status-good' : '' }}" />
                                    </button>
                                    <a
                                        href="{{ route('tasks.show', $subtask) }}"
                                        wire:navigate
                                        title="{{ $subtask->title }}"
                                        @class(['min-w-0 flex-1 truncate text-xs hover:underline', 'text-secondary-light line-through' => $subtask->status === TaskStatus::Done, 'text-secondary-dark dark:text-ink' => $subtask->status !== TaskStatus::Done])
                                    >{{ $subtask->title }}</a>
                                    @if (! auth()->user()->can('update', $subtask))
                                        <flux:icon.flag :variant="$subtask->priority === TaskPriority::Low ? 'outline' : 'solid'" class="size-3 shrink-0 {{ $priorityIconClasses($subtask->priority) }}" />
                                    @else
                                    <flux:dropdown position="bottom" align="start">
                                        <button type="button" aria-label="{{ __('tasks.priority') }}: {{ $subtask->priority->label() }}" class="inline-flex shrink-0 cursor-pointer items-center justify-center rounded hover:bg-secondary-soft dark:hover:bg-surface-2" data-test="task-board-subtask-priority-trigger-{{ $subtask->id }}">
                                            <flux:icon.flag :variant="$subtask->priority === TaskPriority::Low ? 'outline' : 'solid'" class="size-3 shrink-0 {{ $priorityIconClasses($subtask->priority) }}" />
                                        </button>
                                        <flux:menu>
                                            @foreach (TaskPriority::cases() as $subtaskPriorityCase)
                                                <flux:menu.item wire:click="quickSetPriority({{ $subtask->id }}, '{{ $subtaskPriorityCase->value }}')" :wire:key="'task-board-subtask-'.$subtask->id.'-priority-'.$subtaskPriorityCase->value">
                                                    <flux:icon.flag :variant="$subtaskPriorityCase === TaskPriority::Low ? 'outline' : 'solid'" class="size-4 shrink-0 {{ $priorityIconClasses($subtaskPriorityCase) }}" />
                                                    <span class="min-w-0 truncate">{{ $subtaskPriorityCase->label() }}</span>
                                                    @if ($subtask->priority === $subtaskPriorityCase)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                                </flux:menu.item>
                                            @endforeach
                                        </flux:menu>
                                    </flux:dropdown>
                                    @endif
                                    <x-ui.status-pill :case="$subtask->status" class="shrink-0" />
                                    <div class="basis-full ps-7 text-[11px] text-secondary-medium dark:text-ink-muted">
                                        {{ $boardSubtaskAssignees->isEmpty() ? __('common.not_assigned') : $boardSubtaskAssignees->map->name->implode(', ') }}
                                        @if ($subtask->due_date !== null)
                                            · {{ $subtask->due_date->translatedFormat(__('formats.date_short')) }}
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            @if ($canCreate)
                            @php $boardSubtaskDraft = $this->subtaskDraft($task); @endphp
                            <div class="flex flex-col gap-1.5 pt-0.5" data-test="task-board-subtask-add-row-{{ $task->id }}">
                                <label class="flex items-center gap-2">
                                    <flux:icon.plus variant="micro" class="size-3.5 shrink-0 text-primary dark:text-brand-text" aria-hidden="true" />
                                    <span class="sr-only">{{ __('tasks.add_subtask') }}</span>
                                    <input
                                        type="text"
                                        x-ref="subtaskAddInputBoard{{ $task->id }}"
                                        placeholder="{{ __('tasks.add_subtask') }}"
                                        class="w-full min-w-0 border-0 bg-transparent p-1 text-xs text-secondary-dark placeholder:text-secondary-light focus:ring-0 focus:outline-none dark:text-ink"
                                        wire:keydown.enter="addSubtaskQuick({{ $task->id }}, $event.target.value); $event.target.value = ''"
                                        data-test="task-board-subtask-quick-add-{{ $task->id }}"
                                    />
                                </label>
                                <div class="flex flex-wrap items-center gap-1.5 ps-5">
                                    @php
                                        $boardDraftIds = collect($boardSubtaskDraft['assignees'])->map(fn ($id) => (int) $id)->values()->all();
                                        $boardDraftAssignees = collect($this->userOptions)->whereIn('id', $boardDraftIds);
                                        $boardDraftPicker = "\$store.assigneePicker.openFor('board-subtask-{$task->id}', ".Js::from($boardDraftIds).", \$el.getBoundingClientRect(), 'toggleSubtaskDraftAssignee', [{$task->id}])";
                                    @endphp
                                    <div
                                        x-data
                                        role="button"
                                        tabindex="0"
                                        x-on:click="{{ $boardDraftPicker }}"
                                        x-on:keydown.enter="{{ $boardDraftPicker }}"
                                        x-on:keydown.space.prevent="{{ $boardDraftPicker }}"
                                        aria-haspopup="listbox"
                                        aria-label="{{ __('tasks.assignees') }}"
                                        class="inline-flex shrink-0 cursor-pointer items-center -space-x-2 rtl:space-x-reverse"
                                        data-test="task-board-subtask-assignee-trigger-{{ $task->id }}"
                                    >
                                            @if ($boardDraftAssignees->isEmpty())
                                                <span class="inline-flex size-6 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light">
                                                    <flux:icon.user-plus variant="micro" class="size-3" />
                                                </span>
                                            @else
                                                @foreach ($boardDraftAssignees->take(3) as $userOption)
                                                    <span class="inline-flex size-6 items-center justify-center rounded-full bg-secondary-dark text-[10px] font-semibold text-white ring-2 ring-white dark:bg-surface-3 dark:ring-surface-1">
                                                        {{ Illuminate\Support\Str::of($userOption['name'])->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                                                    </span>
                                                @endforeach
                                                @if ($boardDraftAssignees->count() > 3)
                                                    <span class="inline-flex size-6 items-center justify-center rounded-full bg-secondary-soft text-[9px] font-semibold text-secondary-dark ring-2 ring-white dark:bg-surface-3 dark:text-ink dark:ring-surface-1">
                                                        +{{ $boardDraftAssignees->count() - 3 }}
                                                    </span>
                                                @endif
                                            @endif
                                    </div>

                                    <input
                                        type="date"
                                        wire:model="subtaskDrafts.{{ $task->id }}.due_date"
                                        value="{{ $boardSubtaskDraft['due_date'] }}"
                                        aria-label="{{ __('tasks.due_date_2') }}"
                                        class="w-24 shrink-0 rounded border-0 bg-transparent p-0.5 text-[11px] tabular-nums text-secondary-medium focus:ring-1 focus:ring-primary focus:outline-none dark:text-ink-muted"
                                        data-test="task-board-subtask-due-date-{{ $task->id }}"
                                    />

                                    <select
                                        wire:model="subtaskDrafts.{{ $task->id }}.priority"
                                        aria-label="{{ __('tasks.priority') }}"
                                        class="shrink-0 rounded-md border border-secondary-soft bg-white px-1 py-0.5 text-[11px] text-secondary-dark dark:border-line dark:bg-surface-1 dark:text-ink"
                                        data-test="task-board-subtask-priority-{{ $task->id }}"
                                    >
                                        @foreach (TaskPriority::cases() as $priorityCase)
                                            <option value="{{ $priorityCase->value }}" @selected($boardSubtaskDraft['priority'] === $priorityCase->value)>{{ $priorityCase->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="px-1 py-6 text-center text-xs text-secondary-light" data-test="task-board-empty-{{ $case->value }}">{{ __('tasks.no_tasks_in_this_status') }}</p>
                @endforelse
