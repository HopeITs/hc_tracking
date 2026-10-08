{{--
    The unscheduled-tasks panel of calendar/index, a drop target that clears
    a task's due date. Included by ⚡index.blade.php, whose top PHP block
    defines $calendar.
--}}
        <x-ui.panel :title="__('tasks.unscheduled')" :count="$calendar['unscheduledTasks']->count()" :subtitle="__('tasks.unscheduled_tasks_have_no_due_date_drag_one_onto_a_day_to_sch')">
            <div class="flex min-h-24 flex-col gap-2 p-2" x-data="taskDropList('my-calendar', (wire, id) => wire.rescheduleTask(id, ''))" data-test="calendar-unscheduled">
                @forelse ($calendar['unscheduledTasks'] as $task)
                    <div
                        class="flex cursor-grab flex-col gap-1.5 rounded-xl border border-secondary-soft bg-white p-2.5 shadow-hairline transition-all duration-150 hover:-translate-y-0.5 hover:shadow-md active:cursor-grabbing dark:border-line dark:bg-surface-1"
                        data-task-id="{{ $task->id }}"
                        wire:key="calendar-unscheduled-{{ $task->id }}"
                    >
                        <button type="button" wire:click.stop="openEditTaskModal({{ $task->id }})" class="truncate text-start text-xs font-semibold hover:text-primary hover:underline dark:hover:text-status-critical" data-test="calendar-unscheduled-{{ $task->id }}">
                            {{ $task->title }}
                        </button>
                        <x-ui.enum-badge :case="$task->priority" class="w-fit" />
                    </div>
                @empty
                    <p class="px-1 py-6 text-center text-xs text-secondary-light">{{ __('tasks.drop_a_task_here_to_unschedule_it') }}</p>
                @endforelse
            </div>
        </x-ui.panel>
