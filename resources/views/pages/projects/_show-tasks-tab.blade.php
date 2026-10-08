@use('App\Enums\TaskStatus')
@use('App\Models\Task')
        <x-ui.panel :title="__('common.tasks')" :count="$this->tasks->count()" :subtitle="__('projects.tasks_raised_against_this_project_or_its_milestones')">
            <x-slot:toolbar>
                @can('create', App\Models\Task::class)<x-ui.button tone="primary" size="sm" icon="plus" wire:click="openAddTaskModal" data-test="add-project-task-button">{{ __('tasks.add_task') }}</x-ui.button>@endcan
            </x-slot:toolbar>

            @if ($this->tasks->isEmpty())
                <x-ui.empty-state icon="clipboard-document-list" :heading="__('common.no_tasks_yet')" :body="__('projects.tasks_raised_against_this_project_will_appear_here')">
                    <x-slot:action>
                        @can('create', App\Models\Task::class)<x-ui.button size="sm" tone="primary" icon="plus" wire:click="openAddTaskModal">{{ __('tasks.add_task') }}</x-ui.button>@endcan
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                @php
                    $taskDone = $this->tasks->where('status', TaskStatus::Done)->count();
                    $taskTotal = $this->tasks->count();
                    $taskOverdue = $this->tasks->filter(fn (Task $task): bool => $task->status !== TaskStatus::Done && $task->due_date !== null && $task->due_date->lt(today()))->count();
                    $taskUnassigned = $this->tasks->filter(fn (Task $task): bool => $task->taskAssignees->isEmpty())->count();
                    $taskInkText = 'text-secondary-dark dark:text-ink';
                    $taskMutedText = 'text-secondary-medium dark:text-ink-muted';
                @endphp

                <div class="grid gap-3 border-b border-secondary-soft p-3.5 @lg/detail:grid-cols-3 dark:border-line">
                    <x-ui.stat-tile :label="__('projects.stat_tasks_done')" :value="$taskDone" :denominator="$taskTotal" />
                    <x-ui.stat-tile :label="__('tasks.overdue')" :value="$taskOverdue" :tone="$taskOverdue > 0 ? 'warn' : null" />
                    <x-ui.stat-tile :label="__('common.unassigned')" :value="$taskUnassigned" />
                </div>

                <div class="hidden grid-cols-[1.25rem_minmax(0,1fr)_11rem_6rem_7.5rem_8rem_7rem] gap-x-4 px-3.5 pt-4 @3xl/detail:grid" aria-hidden="true">
                    <span></span>
                    @foreach ([__('tasks.task'), __('tasks.assignee'), __('tasks.priority'), __('tasks.due'), __('tasks.progress'), __('common.status')] as $column)
                        <span @class(['type-label', $taskMutedText, 'text-end' => $loop->last])>{{ $column }}</span>
                    @endforeach
                </div>

                <ul class="divide-y divide-secondary-soft px-3.5 pb-3.5 @6xl/detail:px-5 @6xl/detail:pb-5 dark:divide-line">
                    @foreach ($this->tasks as $task)
                        @include('pages.projects._task-row', ['task' => $task, 'inkText' => $taskInkText, 'mutedText' => $taskMutedText])
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>
