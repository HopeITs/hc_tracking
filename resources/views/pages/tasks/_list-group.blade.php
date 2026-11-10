@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Task> $groupTasks */
@endphp

<div class="@container" x-data="taskSubtaskScope">
    @if ($this->listLayout !== 'narrow')
    <table class="hidden w-full table-fixed border-collapse text-start text-sm @3xl:table">
        <thead class="border-b border-secondary-soft dark:border-line">
            <tr class="[&>th]:px-2 [&>th]:py-2.5 [&>th]:text-start [&>th]:text-xs [&>th]:font-semibold [&>th]:text-secondary-medium [&>th]:dark:text-ink-muted">
                <th class="w-11"><span class="sr-only">{{ __('tasks.mark_complete') }}</span></th>
                @include('pages.tasks._sortable-th', ['field' => 'title', 'label' => __('common.name')])
                @include('pages.tasks._sortable-th', ['field' => 'assignee', 'label' => __('tasks.assignee'), 'class' => 'w-32'])
                @include('pages.tasks._sortable-th', ['field' => 'due', 'label' => __('tasks.due_date_2'), 'class' => 'w-40'])
                @include('pages.tasks._sortable-th', ['field' => 'priority', 'label' => __('tasks.priority'), 'class' => 'w-28'])
                @include('pages.tasks._sortable-th', ['field' => 'status', 'label' => __('common.status'), 'class' => 'w-36'])
                <th class="w-20"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-secondary-soft dark:divide-line">
            @foreach ($groupTasks as $task)
                @include('pages.tasks._list-row', ['task' => $task, 'today' => $today, 'priorityIconClasses' => $priorityIconClasses, 'isSubtask' => false])
                @foreach ($task->subtasks as $subtask)
                    @include('pages.tasks._list-row', ['task' => $subtask, 'today' => $today, 'priorityIconClasses' => $priorityIconClasses, 'isSubtask' => true])
                @endforeach
                @can('create', App\Models\Task::class)
                    @include('pages.tasks._list-subtask-composer', ['task' => $task, 'priorityIconClasses' => $priorityIconClasses])
                @endcan
            @endforeach
            @can('create', App\Models\Task::class)
                @include('pages.tasks._list-task-composer', ['group' => $group, 'groupKeyString' => $groupKeyString, 'priorityIconClasses' => $priorityIconClasses])
            @endcan
        </tbody>
    </table>
    @endif

    {{-- Mobile / tablet fallback: stacked cards instead of a squeezed table. --}}
    @if ($this->listLayout !== 'wide')
    <div class="divide-y divide-secondary-soft @3xl:hidden dark:divide-line">
        @foreach ($groupTasks as $task)
            @include('pages.tasks._list-card', ['task' => $task, 'today' => $today, 'priorityIconClasses' => $priorityIconClasses, 'isSubtask' => false])
            @foreach ($task->subtasks as $subtask)
                @include('pages.tasks._list-card', ['task' => $subtask, 'today' => $today, 'priorityIconClasses' => $priorityIconClasses, 'isSubtask' => true])
            @endforeach
            @can('create', App\Models\Task::class)
                @include('pages.tasks._list-subtask-composer-mobile', ['task' => $task])
            @endcan
        @endforeach
        @can('create', App\Models\Task::class)
            @include('pages.tasks._list-task-composer-mobile', ['group' => $group, 'groupKeyString' => $groupKeyString])
        @endcan
    </div>
    @endif
</div>
