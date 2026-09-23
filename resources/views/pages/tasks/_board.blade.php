@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
    use Carbon\CarbonImmutable;
    use Illuminate\Support\Js;

    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task> $allTasks */
    $allTasks = $this->boardTasks;
    $today = CarbonImmutable::today();

    $columnAccent = fn (TaskStatus $case): string => match ($case) {
        TaskStatus::Todo => 'bg-secondary-light',
        TaskStatus::InProgress => 'bg-status-active',
        TaskStatus::UnderReview => 'bg-status-warn',
        TaskStatus::Done => 'bg-status-good',
    };

    $columnIcon = fn (TaskStatus $case): string => match ($case) {
        TaskStatus::Todo => 'stop-circle',
        TaskStatus::InProgress => 'play-circle',
        TaskStatus::UnderReview => 'eye',
        TaskStatus::Done => 'check-circle',
    };

    $priorityChipClasses = fn (TaskPriority $case): string => match ($case) {
        TaskPriority::High => 'bg-status-critical-soft text-primary dark:text-status-critical',
        TaskPriority::Medium => 'bg-status-warn-soft text-status-warn',
        TaskPriority::Low => 'bg-secondary-soft text-secondary-medium dark:bg-secondary-medium/50 dark:text-secondary-soft',
    };

    $priorityIconClasses = fn (TaskPriority $case): string => match ($case) {
        TaskPriority::High => 'text-primary dark:text-status-critical',
        TaskPriority::Medium => 'text-status-warn',
        TaskPriority::Low => 'text-secondary-medium dark:text-secondary-soft',
    };
@endphp

<div class="grid grid-cols-1 gap-4 @2xl/directory:grid-cols-2 @6xl/directory:grid-cols-4" x-data="{ subtasksOpen: {} }">
    @foreach (TaskStatus::cases() as $case)
        @php $columnTasks = $allTasks->where('status', $case)->values(); @endphp

        <div
            class="flex flex-col gap-3 overflow-hidden rounded-2xl border border-secondary-soft bg-secondary-soft/20 pb-3 transition-shadow dark:border-secondary-medium dark:bg-secondary-medium/10"
            wire:key="task-board-column-{{ $case->value }}"
            data-test="task-board-column-{{ $case->value }}"
            x-data="{ dragOver: false }"
            x-on:dragover.prevent="dragOver = true"
            x-on:dragleave="dragOver = false"
            x-on:drop.prevent="
                dragOver = false;
                const dragged = JSON.parse($event.dataTransfer.getData('text/plain') || '{}');
                if (dragged.id && dragged.status !== '{{ $case->value }}') {
                    $wire.quickSetStatus(dragged.id, '{{ $case->value }}');
                }
            "
            :class="dragOver ? 'ring-2 ring-primary ring-offset-1' : ''"
        >
            <div class="h-1 w-full shrink-0 {{ $columnAccent($case) }}"></div>

            <div class="flex items-center justify-between px-3.5">
                <div class="flex items-center gap-1.5">
                    <flux:icon :name="$columnIcon($case)" variant="micro" class="size-4 shrink-0 {{ $columnAccent($case) }} rounded-full text-white" />
                    <span class="text-xs font-bold tracking-wide text-secondary-dark uppercase dark:text-white">{{ $case->label() }}</span>
                </div>
                <span class="inline-flex min-w-6 items-center justify-center rounded-full bg-white px-1.5 py-0.5 text-[11px] font-bold tabular-nums text-secondary-medium shadow-sm dark:bg-secondary-dark dark:text-secondary-soft">{{ $columnTasks->count() }}</span>
            </div>

            <div class="flex min-h-16 flex-col gap-2 px-3">
                @forelse ($columnTasks as $task)
                    @php
                        $isOverdue = $task->status !== TaskStatus::Done && $task->due_date !== null && $task->due_date->lt($today);
                        $assignedIds = $task->taskAssignees->pluck('employee_id')->all();
                        $boardAssignees = $task->taskAssignees->pluck('employee')->filter();
                    @endphp
                    <div
                        class="group flex cursor-grab flex-col gap-2.5 rounded-xl border border-secondary-soft bg-white p-3 shadow-[0_1px_2px_rgba(16,24,40,0.04)] transition-all duration-150 hover:-translate-y-0.5 hover:shadow-md active:cursor-grabbing dark:border-secondary-medium dark:bg-secondary-dark"
                        wire:key="task-board-card-{{ $task->id }}"
                        data-task-id="{{ $task->id }}"
                        draggable="true"
                        x-on:dragstart="$event.dataTransfer.setData('text/plain', JSON.stringify({ id: {{ $task->id }}, status: '{{ $case->value }}' })); $event.currentTarget.classList.add('opacity-40')"
                        x-on:dragend="$event.currentTarget.classList.remove('opacity-40')"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="min-w-0 truncate text-sm font-semibold text-secondary-dark hover:text-primary hover:underline dark:text-white dark:hover:text-status-critical" data-test="task-board-card-{{ $task->id }}">
                                {{ $task->title }}
                            </a>
                            <div class="flex shrink-0 items-center gap-0.5 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
                                <x-ui.button
                                    tone="ghost"
                                    size="sm"
                                    icon="plus"
                                    class="size-7!"
                                    x-on:click="subtasksOpen[{{ $task->id }}] = true; $nextTick(() => $refs['subtaskAddInputBoard{{ $task->id }}']?.focus())"
                                    :aria-label="__('tasks.add_subtask')"
                                    data-test="task-board-add-subtask-{{ $task->id }}"
                                />
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-7!" wire:click="openEditDrawer({{ $task->id }})" :aria-label="__('tasks.edit_title', ['title' => $task->title])" />
                                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-7!" wire:click="confirmDelete({{ $task->id }})" :aria-label="__('tasks.delete_title', ['title' => $task->title])" />
                            </div>
                        </div>

                        <p class="-mt-1.5 truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $task->sourceLabel() }}</p>

                        <div class="flex flex-wrap items-center gap-1.5 text-xs">
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

                            <div @class(['inline-flex items-center gap-1 rounded-full px-2 py-1 font-semibold', 'bg-status-critical-soft text-primary dark:text-status-critical' => $isOverdue, 'bg-secondary-soft text-secondary-medium dark:bg-secondary-medium/50 dark:text-secondary-soft' => ! $isOverdue])>
                                <flux:icon.calendar-days variant="micro" class="size-3 shrink-0" aria-hidden="true" />
                                <input
                                    type="date"
                                    value="{{ $task->due_date?->toDateString() }}"
                                    wire:change="rescheduleTask({{ $task->id }}, $event.target.value)"
                                    aria-label="{{ __('tasks.due_date_2') }}: {{ $task->title }}"
                                    class="w-24 border-0 bg-transparent p-0 tabular-nums focus:ring-0 focus:outline-none"
                                    data-test="task-board-due-date-{{ $task->id }}"
                                />
                            </div>

                            @if ($task->subtasks_count > 0)
                                <button
                                    type="button"
                                    x-on:click="subtasksOpen[{{ $task->id }}] = !subtasksOpen[{{ $task->id }}]"
                                    :aria-expanded="!!subtasksOpen[{{ $task->id }}]"
                                    class="inline-flex items-center gap-1 rounded-full bg-secondary-soft px-2 py-1 font-semibold text-secondary-medium hover:bg-secondary-light/60 dark:bg-secondary-medium/50 dark:text-secondary-soft dark:hover:bg-secondary-medium"
                                    :aria-label="subtasksOpen[{{ $task->id }}] ? {{ Js::from(__('tasks.hide_subtasks')) }} : {{ Js::from(__('tasks.show_subtasks')) }}"
                                    data-test="task-board-subtasks-toggle-{{ $task->id }}"
                                >
                                    <flux:icon.rectangle-stack variant="micro" class="size-3 shrink-0" aria-hidden="true" />
                                    {{ $task->subtasks_count }}
                                </button>
                            @endif
                        </div>

                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium">
                            <div @class(['h-full rounded-full transition-[width]', 'bg-status-good' => $task->status === TaskStatus::Done, 'bg-primary' => $task->status !== TaskStatus::Done]) style="width: {{ $task->progress_percentage }}%"></div>
                        </div>

                        <x-ui.multi-select-popover
                            :label="__('tasks.assignees')"
                            :trigger-label="__('tasks.assignees').': '.($boardAssignees->isEmpty() ? __('common.not_assigned') : $boardAssignees->map->name->implode(', '))"
                            class="inline-flex cursor-pointer items-center -space-x-2 rtl:space-x-reverse"
                            data-test="task-board-assignee-trigger-{{ $task->id }}"
                        >
                            <x-slot:trigger>
                                @if ($boardAssignees->isEmpty())
                                    <span class="inline-flex size-7 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light">
                                        <flux:icon.user-plus variant="micro" class="size-3.5" />
                                    </span>
                                @else
                                    @foreach ($boardAssignees->take(3) as $employee)
                                        <div class="group/avatar relative">
                                            <span class="inline-flex size-7 items-center justify-center rounded-full bg-secondary-dark text-[11px] font-semibold text-white ring-2 ring-white dark:bg-secondary-medium dark:ring-secondary-dark">
                                                {{ Illuminate\Support\Str::of($employee->name)->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                                            </span>
                                            <button
                                                type="button"
                                                wire:click.stop="toggleAssignee({{ $task->id }}, {{ $employee->id }})"
                                                class="absolute -top-1 -end-1 hidden size-4 cursor-pointer items-center justify-center rounded-full bg-secondary-dark text-white hover:bg-primary group-hover/avatar:flex dark:bg-secondary-medium"
                                                aria-label="{{ __('tasks.remove_assignee_name', ['name' => $employee->name]) }}"
                                                data-test="task-board-assignee-remove-{{ $task->id }}-{{ $employee->id }}"
                                            >
                                                <flux:icon.x-mark variant="micro" class="size-2.5" />
                                            </button>
                                        </div>
                                    @endforeach
                                    @if ($boardAssignees->count() > 3)
                                        <span class="inline-flex size-7 items-center justify-center rounded-full bg-secondary-soft text-[10px] font-semibold text-secondary-dark ring-2 ring-white dark:bg-secondary-medium dark:text-white dark:ring-secondary-dark">
                                            +{{ $boardAssignees->count() - 3 }}
                                        </span>
                                    @endif
                                @endif
                            </x-slot:trigger>

                            @foreach ($this->userOptions as $userOption)
                                @php $isAssigned = in_array($userOption['id'], $assignedIds, true); @endphp
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected="{{ $isAssigned ? 'true' : 'false' }}"
                                    wire:click="toggleAssignee({{ $task->id }}, {{ $userOption['id'] }})"
                                    wire:key="task-board-{{ $task->id }}-assignee-option-{{ $userOption['id'] }}"
                                    x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                                    class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-secondary-medium"
                                    data-test="task-board-assignee-option-{{ $task->id }}-{{ $userOption['id'] }}"
                                >
                                    <span class="min-w-0 flex-1 truncate">{{ $userOption['name'] }} · {{ $userOption['secondary_name'] }}</span>
                                    @if ($isAssigned)
                                        <flux:icon.check class="size-4 shrink-0 text-primary" />
                                    @endif
                                </button>
                            @endforeach
                        </x-ui.multi-select-popover>

                        {{-- Non-pointer alternative to the drag-and-drop status change above,
                             matching the milestone board's established fallback pattern. --}}
                        <label class="block">
                            <span class="sr-only">{{ __('tasks.change_status') }}</span>
                            <select
                                wire:change="quickSetStatus({{ $task->id }}, $event.target.value)"
                                wire:loading.attr="disabled"
                                wire:target="quickSetStatus"
                                class="w-full rounded-lg border border-secondary-soft bg-white px-2 py-1.5 text-xs text-secondary-dark disabled:opacity-60 dark:border-secondary-medium dark:bg-secondary-dark dark:text-white"
                                data-test="task-quick-status-{{ $task->id }}"
                            >
                                @foreach (TaskStatus::cases() as $statusOption)
                                    <option value="{{ $statusOption->value }}" @selected($statusOption === $task->status)>{{ $statusOption->label() }}</option>
                                @endforeach
                            </select>
                        </label>

                        <div x-show="subtasksOpen[{{ $task->id }}]" x-cloak class="flex flex-col gap-1.5 rounded-lg bg-secondary-soft/30 p-2 dark:bg-secondary-medium/20">
                            @foreach ($task->subtasks as $subtask)
                                @php $boardSubtaskAssignees = $subtask->taskAssignees->pluck('employee')->filter(); @endphp
                                <div class="flex flex-wrap items-center gap-1.5" wire:key="task-board-subtask-row-{{ $subtask->id }}" data-test="task-board-subtask-row-{{ $subtask->id }}">
                                    <button
                                        type="button"
                                        wire:click="toggleComplete({{ $subtask->id }})"
                                        aria-label="{{ $subtask->status === TaskStatus::Done ? __('tasks.mark_incomplete') : __('tasks.mark_complete') }}"
                                        class="inline-flex size-5 shrink-0 cursor-pointer items-center justify-center rounded-full text-secondary-medium hover:bg-secondary-soft dark:text-secondary-soft dark:hover:bg-secondary-medium"
                                    >
                                        <flux:icon.check-circle :variant="$subtask->status === TaskStatus::Done ? 'solid' : 'outline'" class="size-4 {{ $subtask->status === TaskStatus::Done ? 'text-status-good' : '' }}" />
                                    </button>
                                    <a
                                        href="{{ route('tasks.show', $subtask) }}"
                                        wire:navigate
                                        title="{{ $subtask->title }}"
                                        @class(['min-w-0 flex-1 truncate text-xs hover:underline', 'text-secondary-light line-through' => $subtask->status === TaskStatus::Done, 'text-secondary-dark dark:text-white' => $subtask->status !== TaskStatus::Done])
                                    >{{ $subtask->title }}</a>
                                    <flux:icon.flag :variant="$subtask->priority === TaskPriority::Low ? 'outline' : 'solid'" class="size-3 shrink-0 {{ $priorityIconClasses($subtask->priority) }}" />
                                    <x-ui.status-pill :case="$subtask->status" class="shrink-0" />
                                    <div class="basis-full ps-7 text-[11px] text-secondary-medium dark:text-secondary-soft">
                                        {{ $boardSubtaskAssignees->isEmpty() ? __('common.not_assigned') : $boardSubtaskAssignees->map->name->implode(', ') }}
                                        @if ($subtask->due_date !== null)
                                            · {{ $subtask->due_date->translatedFormat(__('formats.date_short')) }}
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            @php $boardSubtaskDraft = $this->subtaskDraft($task); @endphp
                            <div class="flex flex-col gap-1.5 pt-0.5" data-test="task-board-subtask-add-row-{{ $task->id }}">
                                <label class="flex items-center gap-2">
                                    <flux:icon.plus variant="micro" class="size-3.5 shrink-0 text-primary" aria-hidden="true" />
                                    <span class="sr-only">{{ __('tasks.add_subtask') }}</span>
                                    <input
                                        type="text"
                                        x-ref="subtaskAddInputBoard{{ $task->id }}"
                                        placeholder="{{ __('tasks.add_subtask') }}"
                                        class="w-full min-w-0 border-0 bg-transparent p-1 text-xs text-secondary-dark placeholder:text-secondary-light focus:ring-0 focus:outline-none dark:text-white"
                                        wire:keydown.enter="addSubtaskQuick({{ $task->id }}, $event.target.value); $event.target.value = ''"
                                        data-test="task-board-subtask-quick-add-{{ $task->id }}"
                                    />
                                </label>
                                <div class="flex flex-wrap items-center gap-1.5 ps-5">
                                    <x-ui.multi-select-popover
                                        :label="__('tasks.assignees')"
                                        class="inline-flex shrink-0 cursor-pointer items-center -space-x-2 rtl:space-x-reverse"
                                        data-test="task-board-subtask-assignee-trigger-{{ $task->id }}"
                                    >
                                        <x-slot:trigger>
                                            @php $boardDraftAssignees = collect($this->userOptions)->whereIn('id', collect($boardSubtaskDraft['assignees'])->map(fn ($id) => (int) $id)); @endphp
                                            @if ($boardDraftAssignees->isEmpty())
                                                <span class="inline-flex size-6 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light">
                                                    <flux:icon.user-plus variant="micro" class="size-3" />
                                                </span>
                                            @else
                                                @foreach ($boardDraftAssignees->take(3) as $userOption)
                                                    <span class="inline-flex size-6 items-center justify-center rounded-full bg-secondary-dark text-[10px] font-semibold text-white ring-2 ring-white dark:bg-secondary-medium dark:ring-secondary-dark">
                                                        {{ Illuminate\Support\Str::of($userOption['name'])->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                                                    </span>
                                                @endforeach
                                                @if ($boardDraftAssignees->count() > 3)
                                                    <span class="inline-flex size-6 items-center justify-center rounded-full bg-secondary-soft text-[9px] font-semibold text-secondary-dark ring-2 ring-white dark:bg-secondary-medium dark:text-white dark:ring-secondary-dark">
                                                        +{{ $boardDraftAssignees->count() - 3 }}
                                                    </span>
                                                @endif
                                            @endif
                                        </x-slot:trigger>

                                        @foreach ($this->userOptions as $userOption)
                                            @php $isBoardDraftAssigned = in_array((string) $userOption['id'], $boardSubtaskDraft['assignees'], true); @endphp
                                            <button
                                                type="button"
                                                role="option"
                                                aria-selected="{{ $isBoardDraftAssigned ? 'true' : 'false' }}"
                                                wire:click="toggleSubtaskDraftAssignee({{ $task->id }}, {{ $userOption['id'] }})"
                                                wire:key="task-board-{{ $task->id }}-subtask-draft-assignee-{{ $userOption['id'] }}"
                                                x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                                                class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-secondary-medium"
                                                data-test="task-board-subtask-assignee-option-{{ $task->id }}-{{ $userOption['id'] }}"
                                            >
                                                <span class="min-w-0 flex-1 truncate">{{ $userOption['name'] }} · {{ $userOption['secondary_name'] }}</span>
                                                @if ($isBoardDraftAssigned)
                                                    <flux:icon.check class="size-4 shrink-0 text-primary" />
                                                @endif
                                            </button>
                                        @endforeach
                                    </x-ui.multi-select-popover>

                                    <input
                                        type="date"
                                        wire:model="subtaskDrafts.{{ $task->id }}.due_date"
                                        value="{{ $boardSubtaskDraft['due_date'] }}"
                                        aria-label="{{ __('tasks.due_date_2') }}"
                                        class="w-24 shrink-0 rounded border-0 bg-transparent p-0.5 text-[11px] tabular-nums text-secondary-medium focus:ring-1 focus:ring-primary focus:outline-none dark:text-secondary-soft"
                                        data-test="task-board-subtask-due-date-{{ $task->id }}"
                                    />

                                    <select
                                        wire:model="subtaskDrafts.{{ $task->id }}.priority"
                                        aria-label="{{ __('tasks.priority') }}"
                                        class="shrink-0 rounded-md border border-secondary-soft bg-white px-1 py-0.5 text-[11px] text-secondary-dark dark:border-secondary-medium dark:bg-secondary-dark dark:text-white"
                                        data-test="task-board-subtask-priority-{{ $task->id }}"
                                    >
                                        @foreach (TaskPriority::cases() as $priorityCase)
                                            <option value="{{ $priorityCase->value }}" @selected($boardSubtaskDraft['priority'] === $priorityCase->value)>{{ $priorityCase->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="px-1 py-6 text-center text-xs text-secondary-light" data-test="task-board-empty-{{ $case->value }}">{{ __('tasks.no_tasks_in_this_status') }}</p>
                @endforelse
            </div>

            @php
                $boardRowKey = 'board|'.$case->value;
                $boardTaskDraft = $this->taskDraft($boardRowKey, $case->value);
                $boardTaskDraftAssignees = collect($this->userOptions)->whereIn('id', collect($boardTaskDraft['assignees'])->map(fn ($id) => (int) $id));
                $boardDraftPriority = TaskPriority::from($boardTaskDraft['priority']);
            @endphp
            <div class="mx-3 flex flex-col gap-1.5 rounded-xl border border-dashed border-secondary-light bg-white/60 px-2.5 py-2 transition-colors focus-within:border-primary hover:border-secondary-medium dark:border-secondary-medium dark:bg-secondary-dark/40" data-test="task-board-add-row-{{ $case->value }}">
                <label class="flex items-center gap-2">
                    <flux:icon.plus variant="micro" class="size-4 shrink-0 text-primary" aria-hidden="true" />
                    <span class="sr-only">{{ __('tasks.add_task') }}</span>
                    <input
                        type="text"
                        placeholder="{{ __('tasks.add_task') }}"
                        class="w-full min-w-0 border-0 bg-transparent p-0 text-sm text-secondary-dark placeholder:text-secondary-light focus:ring-0 focus:outline-none dark:text-white"
                        wire:keydown.enter="quickCreateTask($event.target.value, '{{ $case->value }}', null, {{ Js::from($boardRowKey) }}); $event.target.value = ''"
                        data-test="task-board-quick-add-{{ $case->value }}"
                    />
                </label>
                <div class="flex flex-wrap items-center gap-1.5 ps-6">
                    <x-ui.multi-select-popover
                        :label="__('tasks.assignees')"
                        class="inline-flex shrink-0 cursor-pointer items-center -space-x-2 rtl:space-x-reverse"
                        data-test="task-board-add-assignee-trigger-{{ $case->value }}"
                    >
                        <x-slot:trigger>
                            @if ($boardTaskDraftAssignees->isEmpty())
                                <span class="inline-flex size-6 items-center justify-center rounded-full border border-dashed border-secondary-light text-secondary-light">
                                    <flux:icon.user-plus variant="micro" class="size-3" />
                                </span>
                            @else
                                @foreach ($boardTaskDraftAssignees->take(3) as $userOption)
                                    <span class="inline-flex size-6 items-center justify-center rounded-full bg-secondary-dark text-[10px] font-semibold text-white ring-2 ring-white dark:bg-secondary-medium dark:ring-secondary-dark">
                                        {{ Illuminate\Support\Str::of($userOption['name'])->explode(' ')->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                                    </span>
                                @endforeach
                                @if ($boardTaskDraftAssignees->count() > 3)
                                    <span class="inline-flex size-6 items-center justify-center rounded-full bg-secondary-soft text-[9px] font-semibold text-secondary-dark ring-2 ring-white dark:bg-secondary-medium dark:text-white dark:ring-secondary-dark">
                                        +{{ $boardTaskDraftAssignees->count() - 3 }}
                                    </span>
                                @endif
                            @endif
                        </x-slot:trigger>

                        @foreach ($this->userOptions as $userOption)
                            @php $isBoardTaskDraftAssigned = in_array((string) $userOption['id'], $boardTaskDraft['assignees'], true); @endphp
                            <button
                                type="button"
                                role="option"
                                aria-selected="{{ $isBoardTaskDraftAssigned ? 'true' : 'false' }}"
                                wire:click="toggleTaskDraftAssignee({{ Js::from($boardRowKey) }}, {{ $userOption['id'] }})"
                                wire:key="task-board-add-{{ $case->value }}-assignee-{{ $userOption['id'] }}"
                                x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                                class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm hover:bg-secondary-soft dark:hover:bg-secondary-medium"
                                data-test="task-board-add-assignee-option-{{ $case->value }}-{{ $userOption['id'] }}"
                            >
                                <span class="min-w-0 flex-1 truncate">{{ $userOption['name'] }} · {{ $userOption['secondary_name'] }}</span>
                                @if ($isBoardTaskDraftAssigned)
                                    <flux:icon.check class="size-4 shrink-0 text-primary" />
                                @endif
                            </button>
                        @endforeach
                    </x-ui.multi-select-popover>

                    <input
                        type="date"
                        wire:model="taskDrafts.{{ $boardRowKey }}.due_date"
                        value="{{ $boardTaskDraft['due_date'] }}"
                        aria-label="{{ __('tasks.due_date_2') }}"
                        class="w-24 shrink-0 rounded border-0 bg-transparent p-0.5 text-[11px] tabular-nums text-secondary-medium focus:ring-1 focus:ring-primary focus:outline-none dark:text-secondary-soft"
                        data-test="task-board-add-due-date-{{ $case->value }}"
                    />

                    <flux:dropdown position="bottom" align="start">
                        <button type="button" aria-label="{{ __('tasks.priority') }}: {{ $boardDraftPriority->label() }}" class="inline-flex size-6 cursor-pointer items-center justify-center rounded hover:bg-secondary-soft dark:hover:bg-secondary-medium" data-test="task-board-add-priority-trigger-{{ $case->value }}">
                            <flux:icon.flag :variant="$boardDraftPriority === TaskPriority::Low ? 'outline' : 'solid'" class="size-3.5 {{ $priorityIconClasses($boardDraftPriority) }}" />
                        </button>
                        <flux:menu>
                            @foreach (TaskPriority::cases() as $priorityCase)
                                <flux:menu.item wire:click="$set('taskDrafts.{{ $boardRowKey }}.priority', '{{ $priorityCase->value }}')" :wire:key="'task-board-add-'.$case->value.'-priority-'.$priorityCase->value">
                                    <flux:icon.flag :variant="$priorityCase === TaskPriority::Low ? 'outline' : 'solid'" class="size-4 shrink-0 {{ $priorityIconClasses($priorityCase) }}" />
                                    <span class="min-w-0 truncate">{{ $priorityCase->label() }}</span>
                                    @if ($boardDraftPriority === $priorityCase)<flux:icon.check class="ms-auto size-4 shrink-0 text-secondary-medium" />@endif
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </div>
        </div>
    @endforeach
</div>
