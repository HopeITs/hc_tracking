@php
    use App\Enums\TaskStatus;
    use Carbon\CarbonImmutable;
    use Illuminate\Support\Js;

    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task> $allTasks */
    $allTasks = $this->boardTasks;
    $today = CarbonImmutable::today();

    $columnTone = fn (TaskStatus $case): string => match ($case) {
        TaskStatus::Done => 'border-status-good/30 bg-status-good-soft',
        TaskStatus::UnderReview => 'border-status-warn/30 bg-status-warn-soft',
        TaskStatus::InProgress, TaskStatus::Todo => 'border-board-line bg-board',
    };
@endphp

<div class="grid grid-cols-1 gap-4 @2xl/directory:grid-cols-2 @6xl/directory:grid-cols-4" x-data="{ subtasksOpen: {} }">
    @foreach (TaskStatus::cases() as $case)
        @php $columnTasks = $allTasks->where('status', $case)->values(); @endphp

        <div
            class="flex flex-col gap-3 rounded-xl border p-3 transition-colors {{ $columnTone($case) }}"
            wire:key="task-board-column-{{ $case->value }}"
            data-test="task-board-column-{{ $case->value }}"
        >
            <div class="flex items-center justify-between px-1">
                <x-ui.enum-badge :case="$case" />
                <span class="text-xs font-semibold tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $columnTasks->count() }}</span>
            </div>

            <div
                class="flex min-h-16 flex-col gap-2"
                x-data="taskDropList('task-board', (wire, id) => wire.quickSetStatus(id, '{{ $case->value }}'))"
            >
                @forelse ($columnTasks as $task)
                    @php
                        $isOverdue = $task->status !== TaskStatus::Done && $task->due_date !== null && $task->due_date->lt($today);
                    @endphp
                    <div
                        class="flex cursor-grab flex-col gap-2 rounded-lg border border-secondary-soft bg-white p-3 shadow-[0_1px_2px_rgba(16,24,40,0.04)] dark:border-secondary-medium dark:bg-secondary-dark"
                        wire:key="task-board-card-{{ $task->id }}"
                        data-task-card
                        data-task-id="{{ $task->id }}"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="min-w-0 truncate text-sm font-semibold text-secondary-dark hover:underline dark:text-white" data-test="task-board-card-{{ $task->id }}">
                                {{ $task->title }}
                            </a>
                            <div class="flex shrink-0 items-center gap-0.5">
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openEditDrawer({{ $task->id }})" :aria-label="__('tasks.edit_title', ['title' => $task->title])" />
                                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-8!" wire:click="confirmDelete({{ $task->id }})" :aria-label="__('tasks.delete_title', ['title' => $task->title])" />
                            </div>
                        </div>

                        @if ($task->milestone !== null)
                            <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $task->milestone->name }}</p>
                        @endif

                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                            <x-ui.enum-badge :case="$task->priority" />
                            @if ($task->due_date !== null)
                                <span @class(['tabular-nums', 'font-semibold text-primary' => $isOverdue, 'text-secondary-medium dark:text-secondary-soft' => ! $isOverdue])>
                                    {{ $task->due_date->translatedFormat(__('formats.date_short')) }}
                                </span>
                            @endif
                            @if ($task->parent_task_id === null && $task->subtasks_count > 0)
                                <button
                                    type="button"
                                    x-on:click="subtasksOpen[{{ $task->id }}] = !subtasksOpen[{{ $task->id }}]"
                                    :aria-expanded="!!subtasksOpen[{{ $task->id }}]"
                                    class="inline-flex items-center gap-0.5 rounded px-1 py-0.5 text-secondary-medium hover:bg-secondary-soft/60 dark:text-secondary-soft dark:hover:bg-secondary-medium"
                                    :aria-label="subtasksOpen[{{ $task->id }}] ? {{ Js::from(__('tasks.hide_subtasks')) }} : {{ Js::from(__('tasks.show_subtasks')) }}"
                                    data-test="task-board-subtasks-toggle-{{ $task->id }}"
                                >
                                    <flux:icon.rectangle-stack variant="micro" class="size-3.5" aria-hidden="true" />
                                    {{ $task->subtasks_count }}
                                </button>
                            @endif
                        </div>

                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium">
                            <div class="h-full rounded-full bg-primary" style="width: {{ $task->progress_percentage }}%"></div>
                        </div>

                        @php $boardAssignees = $task->taskAssignees->pluck('employee')->filter(); @endphp
                        @if ($boardAssignees->isEmpty())
                            <x-ui.employee-chip :employee="null" compact />
                        @else
                            <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $boardAssignees->map->name->implode(', ') }}</p>
                        @endif

                        {{-- Non-pointer alternative to the drag-and-drop status change above,
                             matching the milestone board's established fallback pattern. --}}
                        <label class="block">
                            <span class="sr-only">{{ __('tasks.change_status') }}</span>
                            <select
                                wire:change="quickSetStatus({{ $task->id }}, $event.target.value)"
                                wire:loading.attr="disabled"
                                wire:target="quickSetStatus"
                                class="w-full rounded-md border border-secondary-soft bg-white px-2 py-1.5 text-xs text-secondary-dark disabled:opacity-60 dark:border-secondary-medium dark:bg-secondary-dark dark:text-white"
                                data-test="task-quick-status-{{ $task->id }}"
                            >
                                @foreach (TaskStatus::cases() as $statusOption)
                                    <option value="{{ $statusOption->value }}" @selected($statusOption === $task->status)>{{ $statusOption->label() }}</option>
                                @endforeach
                            </select>
                        </label>

                        @if ($task->parent_task_id === null && $task->subtasks_count > 0)
                            <div x-show="subtasksOpen[{{ $task->id }}]" x-cloak class="flex flex-col gap-1.5 rounded-lg bg-secondary-soft/30 p-2 dark:bg-secondary-medium/20">
                                @foreach ($task->subtasks as $subtask)
                                    <div class="flex items-center gap-1.5" wire:key="task-board-subtask-row-{{ $subtask->id }}" data-test="task-board-subtask-row-{{ $subtask->id }}">
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
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="px-1 py-4 text-center text-xs text-secondary-light" data-test="task-board-empty-{{ $case->value }}">{{ __('tasks.no_tasks_in_this_status') }}</p>
                @endforelse
            </div>

            <label class="flex items-center gap-2 rounded-lg border border-dashed border-secondary-light px-2 py-1.5 dark:border-secondary-medium">
                <flux:icon.plus variant="micro" class="size-4 shrink-0 text-primary" aria-hidden="true" />
                <span class="sr-only">{{ __('tasks.add_task') }}</span>
                <input
                    type="text"
                    placeholder="{{ __('tasks.add_task') }}"
                    class="w-full min-w-0 border-0 bg-transparent p-0 text-sm text-secondary-dark placeholder:text-secondary-light focus:ring-0 focus:outline-none dark:text-white"
                    wire:keydown.enter="quickCreateTask($event.target.value, '{{ $case->value }}', null); $event.target.value = ''"
                    data-test="task-board-quick-add-{{ $case->value }}"
                />
            </label>
        </div>
    @endforeach
</div>
