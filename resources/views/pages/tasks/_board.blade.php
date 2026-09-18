@php
    use App\Enums\TaskStatus;
    use Carbon\CarbonImmutable;

    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Task> $allTasks */
    $allTasks = $this->boardTasks;
    $today = CarbonImmutable::today();

    $columnTone = fn (TaskStatus $case): string => match ($case) {
        TaskStatus::Done => 'border-status-good/30 bg-status-good-soft',
        TaskStatus::UnderReview => 'border-status-warn/30 bg-status-warn-soft',
        TaskStatus::InProgress, TaskStatus::Todo => 'border-board-line bg-board',
    };
@endphp

<div class="grid grid-cols-1 gap-4 @2xl/directory:grid-cols-2 @6xl/directory:grid-cols-4">
    @foreach (TaskStatus::cases() as $case)
        @php $columnTasks = $allTasks->where('status', $case)->values(); @endphp

        <div
            class="flex cursor-pointer flex-col gap-3 rounded-xl border p-3 transition-colors {{ $columnTone($case) }}"
            wire:key="task-board-column-{{ $case->value }}"
            data-test="task-board-column-{{ $case->value }}"
            x-on:click="$event.target.closest('[data-task-card]') || $wire.openCreateDrawer(null, '{{ $case->value }}')"
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
                            @if ($task->subtasks_count > 0)
                                <span class="inline-flex items-center gap-0.5 text-secondary-medium dark:text-secondary-soft">
                                    <flux:icon.rectangle-stack variant="micro" class="size-3.5" aria-hidden="true" />
                                    {{ $task->subtasks_count }}
                                </span>
                            @endif
                        </div>

                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium">
                            <div class="h-full rounded-full bg-primary" style="width: {{ $task->progress_percentage }}%"></div>
                        </div>

                        <x-ui.employee-chip :employee="$task->assignee" compact />

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
                    </div>
                @empty
                    <p class="px-1 py-4 text-center text-xs text-secondary-light" data-test="task-board-empty-{{ $case->value }}">{{ __('tasks.no_tasks_in_this_status') }}</p>
                @endforelse
            </div>
        </div>
    @endforeach
</div>
