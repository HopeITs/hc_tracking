@php
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
            @include('pages.tasks._list-task-composer', ['group' => $group, 'groupKeyString' => $groupKeyString, 'priorityIconClasses' => $priorityIconClasses])
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
        @include('pages.tasks._list-task-composer-mobile', ['group' => $group, 'groupKeyString' => $groupKeyString])
    </div>
</div>
