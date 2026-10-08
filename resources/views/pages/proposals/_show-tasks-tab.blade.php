{{--
    The Tasks tab of proposals/show: the status overview and the tasks
    grouped by funded project. Included by ⚡show.blade.php, whose top PHP
    block defines $mutedText, $inkText, $hairline, $amount, $term,
    $chronology and $arrivals.
--}}
@use('App\Enums\TaskStatus')
@use('App\Models\Task')
            <div class="mb-6 flex flex-wrap items-center justify-end gap-2">
                @can('create', App\Models\Task::class)<x-ui.button tone="primary" size="sm" icon="plus" class="min-h-10 touch-manipulation" wire:click="openAddTaskModal" data-test="add-proposal-task-button">{{ __('tasks.add_task') }}</x-ui.button>@endcan
            </div>

            @if ($this->tasks->isEmpty())
                <x-ui.empty-state icon="clipboard-document-list" :heading="__('common.no_tasks_yet')" :body="__('proposals.tasks_delivering_against_this_proposal_will_appear_here')">
                    <x-slot:action>
                        @can('create', App\Models\Task::class)<x-ui.button size="sm" tone="primary" icon="plus" wire:click="openAddTaskModal">{{ __('tasks.add_task') }}</x-ui.button>@endcan
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                @php
                    $fundedProjectIds = $this->projects->modelKeys();
                    // Open work first, soonest due first; finished work sinks to the bottom of its group.
                    $ordered = $this->tasks->sortBy([
                        fn (Task $a, Task $b): int => ($a->status === TaskStatus::Done) <=> ($b->status === TaskStatus::Done),
                        fn (Task $a, Task $b): int => ($a->due_date === null) <=> ($b->due_date === null),
                        fn (Task $a, Task $b): int => ($a->due_date?->getTimestamp() ?? 0) <=> ($b->due_date?->getTimestamp() ?? 0),
                        fn (Task $a, Task $b): int => $a->id <=> $b->id,
                    ]);
                    $groups = $this->projects
                        ->map(fn ($project): array => [
                            'project' => $project,
                            'tasks' => $ordered->filter(fn (Task $task): bool => $task->sourceProjectId() === $project->id)->values(),
                        ])
                        ->filter(fn (array $group): bool => $group['tasks']->isNotEmpty())
                        ->values();
                    $looseTasks = $ordered->filter(fn (Task $task): bool => $task->sourceProjectId() === null || ! in_array($task->sourceProjectId(), $fundedProjectIds, true))->values();
                    $taskTotal = $this->tasks->count();
                    $statusCounts = collect(TaskStatus::cases())->map(fn (TaskStatus $status): array => [
                        'status' => $status,
                        'count' => $this->tasks->where('status', $status)->count(),
                    ]);
                    $overdueCount = $this->tasks->filter(fn (Task $task): bool => $task->status !== TaskStatus::Done && $task->due_date !== null && $task->due_date->lt(today()))->count();
                    $unassignedCount = $this->tasks->filter(fn (Task $task): bool => $task->taskAssignees->isEmpty())->count();
                    $undatedCount = $this->tasks->whereNull('due_date')->count();
                    $statusTone = [
                        TaskStatus::Done->value => 'bg-secondary-dark dark:bg-ink',
                        TaskStatus::UnderReview->value => 'bg-secondary-medium dark:bg-ink-muted',
                        TaskStatus::InProgress->value => 'bg-secondary-light',
                        TaskStatus::Todo->value => 'bg-secondary-soft dark:bg-line-strong',
                    ];
                @endphp

                {{-- Where the work stands: one bar split by status, then the three gaps worth chasing. --}}
                <div class="flex flex-col gap-5 border-b pb-6 {{ $hairline }} @3xl/detail:flex-row @3xl/detail:items-end @3xl/detail:justify-between @3xl/detail:gap-10" data-task-overview>
                    <div class="min-w-0 flex-1">
                        <div class="flex h-2 w-full gap-0.5 overflow-hidden rounded-full" role="img" aria-label="{{ $statusCounts->map(fn (array $line): string => $line['status']->label().' '.$line['count'])->implode(', ') }}">
                            @foreach ($statusCounts->reverse() as $line)
                                @if ($line['count'] > 0)
                                    <span class="money-grow h-full first:rounded-s-full last:rounded-e-full {{ $statusTone[$line['status']->value] }}" style="width: {{ round($line['count'] / $taskTotal * 100, 2) }}%"></span>
                                @endif
                            @endforeach
                        </div>
                        <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-2">
                            @foreach ($statusCounts as $line)
                                <div class="flex items-center gap-2" wire:key="task-status-{{ $line['status']->value }}" data-status-count="{{ $line['status']->value }}">
                                    <span class="size-2 rounded-full {{ $statusTone[$line['status']->value] }}" aria-hidden="true"></span>
                                    <dt class="text-xs {{ $mutedText }}">{{ $line['status']->label() }}</dt>
                                    <dd @class(['text-sm font-bold tabular-nums', $inkText => $line['count'] > 0, $mutedText => $line['count'] === 0])>{{ $line['count'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>

                    <dl class="flex shrink-0 gap-x-8">
                        @foreach ([
                            ['key' => 'overdue', 'label' => __('proposals.overdue'), 'count' => $overdueCount, 'alarm' => $overdueCount > 0],
                            ['key' => 'unassigned', 'label' => __('common.unassigned'), 'count' => $unassignedCount, 'alarm' => false],
                            ['key' => 'undated', 'label' => __('proposals.no_due_date'), 'count' => $undatedCount, 'alarm' => false],
                        ] as $gap)
                            <div class="min-w-0" data-task-gap="{{ $gap['key'] }}">
                                <dt class="type-label {{ $mutedText }}">{{ $gap['label'] }}</dt>
                                <dd @class([
                                    'mt-1 text-2xl leading-none font-extrabold tracking-tight tabular-nums',
                                    'text-status-critical' => $gap['alarm'],
                                    $inkText => ! $gap['alarm'] && $gap['count'] > 0,
                                    $mutedText => $gap['count'] === 0,
                                ])>{{ $gap['count'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                {{-- Column names, shown only once the rows spread into columns. --}}
                <div class="mt-4 hidden grid-cols-[1.25rem_minmax(0,1fr)_11rem_6rem_7.5rem_8rem_7rem] gap-x-4 @3xl/detail:grid" aria-hidden="true">
                    <span></span>
                    @foreach ([__('tasks.task'), __('tasks.assignee'), __('tasks.priority'), __('tasks.due'), __('tasks.progress'), __('common.status')] as $column)
                        <span @class(['type-label', $mutedText, 'text-end' => $loop->last])>{{ $column }}</span>
                    @endforeach
                </div>

                <div class="mt-2 flex flex-col gap-8">
                    @foreach ($groups as $group)
                        @php
                            $groupDone = $group['tasks']->where('status', TaskStatus::Done)->count();
                            $groupTotal = $group['tasks']->count();
                        @endphp
                        <section class="min-w-0" wire:key="task-group-{{ $group['project']->id }}" data-task-group="{{ $group['project']->id }}">
                            <header class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-b pt-4 pb-3 {{ $hairline }}">
                                <a href="{{ route('projects.show', $group['project']) }}" wire:navigate class="min-w-0 truncate text-base font-bold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $inkText }}">{{ $group['project']->name }}</a>
                                <span class="flex shrink-0 items-center gap-3 text-xs {{ $mutedText }}">
                                    <span class="h-1 w-24 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" aria-hidden="true">
                                        <span class="block h-full rounded-full bg-secondary-dark dark:bg-ink-muted" style="width: {{ round($groupDone / $groupTotal * 100, 2) }}%"></span>
                                    </span>
                                    <span class="tabular-nums">{{ __('proposals.done_of_total', ['done' => $groupDone, 'total' => $groupTotal]) }}</span>
                                </span>
                            </header>
                            <ul class="divide-y {{ $hairline }}">
                                @foreach ($group['tasks'] as $task)
                                    @include('pages.proposals._task-row', ['task' => $task, 'inkText' => $inkText, 'mutedText' => $mutedText])
                                @endforeach
                            </ul>
                        </section>
                    @endforeach

                    @if ($looseTasks->isNotEmpty())
                        <section class="min-w-0" data-loose-tasks>
                            <header class="border-b pt-4 pb-3 {{ $hairline }}">
                                <p class="text-base font-bold {{ $inkText }}">{{ __('proposals.work_not_tied_to_a_funded_project') }}</p>
                                <p class="mt-0.5 text-xs {{ $mutedText }}">{{ __('proposals.tasks_with_no_milestone_on_a_funded_project') }}</p>
                            </header>
                            <ul class="divide-y {{ $hairline }}">
                                @foreach ($looseTasks as $task)
                                    @include('pages.proposals._task-row', ['task' => $task, 'inkText' => $inkText, 'mutedText' => $mutedText])
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    @if ($undatedCount > 0)
                        <p class="text-xs {{ $mutedText }}" data-undated>
                            {{ trans_choice('proposals.count_tasks_carry_no_due_date', $undatedCount, ['count' => $undatedCount]) }}
                        </p>
                    @endif
                </div>
            @endif
