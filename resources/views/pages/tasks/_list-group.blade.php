@php
    use Illuminate\Support\Js;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Task> $groupTasks */
@endphp

<div class="@container" x-data="{ subtasksOpen: {} }">
    <table class="hidden w-full table-fixed border-collapse text-start text-sm @3xl:table">
        <thead class="bg-secondary-soft/30 dark:bg-secondary-medium/20">
            <tr class="[&>th]:px-3.5 [&>th]:py-2 [&>th]:text-start [&>th]:text-[11px] [&>th]:font-semibold [&>th]:tracking-wide [&>th]:text-secondary-medium [&>th]:uppercase [&>th]:dark:text-secondary-soft">
                <th class="w-9"></th>
                @include('pages.tasks._sortable-th', ['field' => 'title', 'label' => __('common.name')])
                @include('pages.tasks._sortable-th', ['field' => 'assignee', 'label' => __('tasks.assignee'), 'class' => 'w-28'])
                @include('pages.tasks._sortable-th', ['field' => 'due', 'label' => __('tasks.due_date_2'), 'class' => 'w-32'])
                @include('pages.tasks._sortable-th', ['field' => 'priority', 'label' => __('tasks.priority'), 'class' => 'w-24'])
                @include('pages.tasks._sortable-th', ['field' => 'status', 'label' => __('common.status'), 'class' => 'w-40'])
                <th class="w-20"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-secondary-soft dark:divide-secondary-medium">
            @foreach ($groupTasks as $task)
                @include('pages.tasks._list-row', ['task' => $task, 'today' => $today, 'priorityIconClasses' => $priorityIconClasses, 'isSubtask' => false])
                @foreach ($task->subtasks as $subtask)
                    @include('pages.tasks._list-row', ['task' => $subtask, 'today' => $today, 'priorityIconClasses' => $priorityIconClasses, 'isSubtask' => true])
                @endforeach
                @include('pages.tasks._list-subtask-composer', ['task' => $task, 'priorityIconClasses' => $priorityIconClasses])
            @endforeach
        </tbody>
    </table>

    {{-- Mobile / tablet fallback: stacked cards instead of a squeezed table. --}}
    <div class="divide-y divide-secondary-soft @3xl:hidden dark:divide-secondary-medium">
        @foreach ($groupTasks as $task)
            @include('pages.tasks._list-card', ['task' => $task, 'today' => $today, 'priorityIconClasses' => $priorityIconClasses, 'isSubtask' => false])
            @foreach ($task->subtasks as $subtask)
                @include('pages.tasks._list-card', ['task' => $subtask, 'today' => $today, 'priorityIconClasses' => $priorityIconClasses, 'isSubtask' => true])
            @endforeach
            @include('pages.tasks._list-subtask-composer-mobile', ['task' => $task])
        @endforeach
    </div>

    <div class="border-t border-secondary-soft px-3.5 py-2 dark:border-secondary-medium" wire:key="task-add-row-{{ $group }}-{{ $groupKeyString }}">
        <label class="flex items-center gap-2">
            <flux:icon.plus variant="micro" class="size-4 shrink-0 text-primary" aria-hidden="true" />
            <span class="sr-only">{{ __('tasks.add_task') }}</span>
            <input
                type="text"
                placeholder="{{ __('tasks.add_task') }}"
                class="w-full min-w-0 border-0 bg-transparent p-1 text-sm text-secondary-dark placeholder:text-secondary-light focus:ring-0 focus:outline-none dark:text-white"
                wire:keydown.enter="quickCreateTask($event.target.value, {{ $group === 'status' ? Js::from($groupKeyString) : 'null' }}, {{ $group === 'milestone' && $groupKeyString !== '' ? Js::from($groupKeyString) : 'null' }}); $event.target.value = ''"
                data-test="task-quick-add-{{ $group }}-{{ $groupKeyString }}"
            />
        </label>
    </div>
</div>
