@use('App\Enums\TaskStatus')
@use('App\Models\Project')
@use('App\Models\Task')
@use('Carbon\CarbonImmutable')
@php
    /*
     * The program's Tasks tab, a split view: stat cards that filter and a
     * search on top; on the left a short list of buckets (projects, due
     * dates or people) with their counts, on the right the picked bucket's
     * tasks ten at a time. Each row edits in place through the shared
     * status/priority menu, the shared assignee popover and the "more" panel.
     */
    $user = auth()->user();
    $employeeId = $user?->employee_id;
    $today = CarbonImmutable::today();
    $allTasks = $this->tasks;
    $isOverdue = fn (Task $task): bool => $task->status !== TaskStatus::Done && $task->due_date !== null && $task->due_date->lt($today);
    $canCreate = $user?->can('create', Task::class) === true;

    $counts = collect($this::TASK_FILTERS)
        ->mapWithKeys(fn (string $filterKey): array => [$filterKey => $allTasks->filter(fn (Task $task): bool => $this->taskPassesFilter($task, $employeeId, $filterKey))->count()])
        ->all();

    $needle = mb_strtolower(trim($this->taskSearch));
    $searching = $needle !== '';
    $matching = $searching
        ? $allTasks->filter(fn (Task $task): bool => str_contains(mb_strtolower($task->title), $needle) || str_contains(mb_strtolower($task->sourceLabel()), $needle))
        : $allTasks->filter(fn (Task $task): bool => $this->taskPassesFilter($task, $employeeId));
    $matchingIds = $matching->pluck('id')->flip();

    $ordered = $matching->sortBy([
        fn (Task $a, Task $b): int => ($a->status === TaskStatus::Done) <=> ($b->status === TaskStatus::Done),
        fn (Task $a, Task $b): int => ($a->due_date === null) <=> ($b->due_date === null),
        fn (Task $a, Task $b): int => ($a->due_date?->getTimestamp() ?? 0) <=> ($b->due_date?->getTimestamp() ?? 0),
        fn (Task $a, Task $b): int => $a->id <=> $b->id,
    ])->values();

    $subtasksOf = $allTasks->whereNotNull('parent_task_id')->groupBy('parent_task_id');
    $titles = $allTasks->pluck('title', 'id');
    // A subtask nests under its parent when the parent is listed too; otherwise it stands on its own.
    $rows = $ordered->filter(fn (Task $task): bool => $task->parent_task_id === null || ! $matchingIds->has($task->parent_task_id))->values();

    $groupKeys = fn (Task $task): array => match ($this->taskGroupBy) {
        'due' => [match (true) {
            $task->status === TaskStatus::Done => 'done',
            $task->due_date === null => 'none',
            $task->due_date->lt($today) => 'overdue',
            $task->due_date->lte($today->addDays(7)) => 'week',
            default => 'later',
        }],
        'assignee' => $task->taskAssignees->pluck('employee_id')->filter()->unique()->map(fn (int $id): string => 'employee-'.$id)->values()->all() ?: ['unassigned'],
        default => [($projectId = $this->taskProjectId($task)) === null ? 'program' : 'project-'.$projectId],
    };

    $groupMeta = [];
    if ($this->taskGroupBy === 'due') {
        foreach (['overdue', 'week', 'later', 'none', 'done'] as $dueKey) {
            $groupMeta[$dueKey] = ['label' => __('programs.tasks_due_'.$dueKey), 'href' => null, 'target' => null, 'photo' => null];
        }
    } elseif ($this->taskGroupBy === 'assignee') {
        $people = $allTasks->flatMap(fn (Task $task) => $task->taskAssignees->pluck('employee'))->filter()->unique('id')->sortBy('name');
        foreach ($people as $person) {
            $groupMeta['employee-'.$person->id] = ['label' => $person->name, 'href' => null, 'target' => null, 'photo' => $person->id];
        }
        $groupMeta['unassigned'] = ['label' => __('programs.tasks_unassigned_group'), 'href' => null, 'target' => null, 'photo' => null];
    } else {
        // Projects usually repeat their program's name; the side list drops it so the names differ at a glance.
        $programPrefix = mb_strtolower(trim((string) $this->program->name)).' ';
        foreach ($this->allProjects as $project) {
            $projectName = $project->headline();
            $short = $programPrefix !== ' ' && str_starts_with(mb_strtolower($projectName), $programPrefix) ? trim(mb_substr($projectName, mb_strlen($programPrefix))) : '';
            $groupMeta['project-'.$project->id] = ['label' => $projectName, 'short' => $short !== '' ? $short : $projectName, 'href' => route('projects.show', $project), 'target' => 'proposal_location:'.$project->id, 'photo' => null];
        }
        $groupMeta['program'] = ['label' => __('programs.work_not_tied_to_a_project'), 'href' => null, 'target' => 'program', 'photo' => null];
    }

    $grouped = [];
    foreach ($rows as $task) {
        foreach ($groupKeys($task) as $key) {
            $grouped[$key][] = $task;
        }
    }
    $urgentFirst = $this->taskGroupBy !== 'due';
    $buckets = collect($groupMeta)
        ->filter(fn (array $meta, string $key): bool => isset($grouped[$key]) || $key === $this->taskBucket)
        ->map(function (array $meta, string $key) use ($grouped, $isOverdue): array {
            $bucketTasks = collect($grouped[$key] ?? []);

            return $meta + ['key' => $key, 'tasks' => $bucketTasks, 'count' => $bucketTasks->count(), 'overdue' => $bucketTasks->filter($isOverdue)->count()];
        })
        ->when($urgentFirst, fn ($buckets) => $buckets->sortBy([
            fn (array $a, array $b): int => ($a['key'] === 'program' || $a['key'] === 'unassigned') <=> ($b['key'] === 'program' || $b['key'] === 'unassigned'),
            fn (array $a, array $b): int => $b['overdue'] <=> $a['overdue'],
            fn (array $a, array $b): int => $b['count'] <=> $a['count'],
            fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']),
        ]))
        ->values();
    $allBucket = ['key' => 'all', 'label' => __('programs.tasks_all_buckets_'.$this->taskGroupBy), 'href' => null, 'target' => 'program', 'photo' => null, 'tasks' => $rows, 'count' => $rows->count(), 'overdue' => $rows->filter($isOverdue)->count()];
    $selected = $buckets->firstWhere('key', $this->taskBucket) ?? $allBucket;
    $pageSize = $this::TASK_PAGE_SIZE;
    $pages = max(1, (int) ceil($selected['count'] / $pageSize));
    $page = min(max(1, $this->taskPage), $pages);
    $pageTasks = $selected['tasks']->slice(($page - 1) * $pageSize, $pageSize)->values();
    $selectedDone = $selected['tasks']->where('status', TaskStatus::Done)->count();

    $cards = [
        ['key' => 'open', 'label' => __('programs.tasks_filter_open'), 'tone' => null],
        ['key' => 'overdue', 'label' => __('programs.tasks_filter_overdue'), 'tone' => $counts['overdue'] > 0 ? 'bad' : null],
        ['key' => 'week', 'label' => __('programs.tasks_filter_week'), 'tone' => null],
        ['key' => 'mine', 'label' => __('programs.tasks_filter_mine'), 'tone' => null],
        ['key' => 'done', 'label' => __('programs.tasks_filter_done'), 'tone' => null],
    ];
@endphp

<section class="dk-ptk" data-test="program-tasks"
    x-data="{
        more: { open: false, id: null, href: '', canDelete: false, view: 'menu', top: 0, left: 0 },
        openMore(detail, rect, view = 'menu') {
            if (this.more.open && this.more.id === detail.id) { this.closeMore(); return }
            const left = Math.min(rect.right - 232, window.innerWidth - 248);
            this.more = { ...detail, open: true, view, top: rect.bottom + 4, left: Math.max(8, left) };
        },
        closeMore() { this.more.open = false; this.more.id = null },
        run(method, ...args) { const id = this.more.id; this.closeMore(); $wire[method](id, ...args) },
    }">
    {{-- 1. Title, search and the add button. --}}
    <header class="dk-ptk-h">
        <h2>{{ __('common.tasks') }} <span class="dk-num">{{ $allTasks->count() }}</span></h2>
        <label class="dk-prog-search">
            <span class="sr-only">{{ __('programs.tasks_find') }}</span>
            <x-desk.icon name="search" />
            <input type="search" wire:model.live.debounce.300ms="taskSearch" placeholder="{{ __('programs.tasks_find') }}" data-test="program-task-search" />
        </label>
        @if ($canCreate)
            <x-ui.button tone="primary" size="sm" icon="plus" wire:click="openAddTaskModal" data-test="add-program-task-button">{{ __('tasks.add_task') }}</x-ui.button>
        @endif
    </header>

    @if ($allTasks->isEmpty())
        <x-ui.empty-state icon="clipboard-document-list" :heading="__('common.no_tasks_yet')" :body="__('programs.tasks_for_this_program_will_appear_here')" />
    @else
        {{-- 2. Stat cards that are also the filters; clicking the active one goes back to "Open". --}}
        <div @class(['dk-ptk-cards', 'dk-prog-chips-off' => $searching]) role="group" aria-label="{{ __('programs.tasks_filters') }}">
            @foreach ($cards as $card)
                <button type="button" wire:key="task-filter-{{ $card['key'] }}" wire:click="pickTaskFilter('{{ $card['key'] }}')"
                    aria-pressed="{{ $this->taskFilter === $card['key'] ? 'true' : 'false' }}"
                    @class(['dk-ptk-card', 'dk-bad' => $card['tone'] === 'bad']) data-task-filter="{{ $card['key'] }}">
                    <span class="dk-ptk-card-k">{{ $card['label'] }}</span>
                    <span class="dk-ptk-card-n dk-num">
                        {{ $counts[$card['key']] }}@if ($card['key'] === 'done')<small>/{{ $allTasks->count() }}</small>@endif
                    </span>
                </button>
            @endforeach
        </div>

        {{-- 3. Group by. --}}
        <div class="dk-ptk-bar">
            <div class="dk-ptk-seg" role="group" aria-label="{{ __('programs.tasks_group_by') }}">
                @foreach ($this::TASK_GROUPINGS as $grouping)
                    <button type="button" wire:key="task-group-{{ $grouping }}" wire:click="$set('taskGroupBy', '{{ $grouping }}')" aria-pressed="{{ $this->taskGroupBy === $grouping ? 'true' : 'false' }}" data-task-grouping="{{ $grouping }}">{{ __('programs.tasks_group_'.$grouping) }}</button>
                @endforeach
            </div>
        </div>

        {{-- 4. The split view: buckets on the left, the picked bucket's tasks on the right. --}}
        <div class="dk-ptk-split">
            <aside class="dk-ptk-rail" x-data="{ find: '' }" aria-label="{{ __('programs.tasks_group_'.$this->taskGroupBy) }}">
                {{-- On a phone the side list is one dropdown. --}}
                <label class="dk-ptk-rail-pick">
                    <span class="sr-only">{{ __('programs.tasks_group_'.$this->taskGroupBy) }}</span>
                    <select wire:change="pickTaskBucket($event.target.value)" data-test="task-bucket-select">
                        @foreach ([$allBucket, ...$buckets] as $bucket)
                            <option value="{{ $bucket['key'] }}" @selected($bucket['key'] === $selected['key'])>{{ $bucket['short'] ?? $bucket['label'] }} · {{ $bucket['count'] }}</option>
                        @endforeach
                    </select>
                </label>

                @if ($buckets->count() > 8)
                    <label class="dk-ptk-rail-find">
                        <x-desk.icon name="search" />
                        <input type="search" x-model="find" placeholder="{{ __('programs.tasks_find_bucket_'.$this->taskGroupBy) }}" aria-label="{{ __('programs.tasks_find_bucket_'.$this->taskGroupBy) }}" />
                    </label>
                @endif

                <ul class="dk-ptk-rail-list">
                    @foreach ([$allBucket, ...$buckets] as $bucket)
                        <li wire:key="task-bucket-{{ $this->taskGroupBy }}-{{ $bucket['key'] }}" @if ($bucket['key'] !== 'all') x-show="find === '' || {{ \Illuminate\Support\Js::from(mb_strtolower($bucket['label'])) }}.includes(find.trim().toLowerCase())" @endif>
                            <button type="button" wire:click="pickTaskBucket('{{ $bucket['key'] }}')" @class(['dk-ptk-rail-i', 'dk-ptk-rail-all' => $bucket['key'] === 'all']) aria-current="{{ $bucket['key'] === $selected['key'] ? 'true' : 'false' }}" data-task-bucket="{{ $bucket['key'] }}">
                                @if ($bucket['photo'] !== null)
                                    <x-ui.avatar :src="\App\Models\HrEmployee::avatarUrl($bucket['photo'])" class="dk-av dk-av-xs" aria-hidden="true" />
                                @endif
                                <span class="dk-ptk-rail-name" title="{{ $bucket['label'] }}">{{ $bucket['short'] ?? $bucket['label'] }}</span>
                                {{-- One number: the tasks in this line; red when some of them are past due. --}}
                                <span @class(['dk-ptk-rail-n', 'dk-num', 'dk-bad' => $bucket['overdue'] > 0])
                                    title="{{ trans_choice('programs.tasks_count', $bucket['count'], ['count' => $bucket['count']]) }}@if ($bucket['overdue'] > 0) · {{ trans_choice('programs.ledger_past_due', $bucket['overdue'], ['count' => $bucket['overdue']]) }}@endif"
                                    data-bucket-overdue="{{ $bucket['overdue'] }}">{{ $bucket['count'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </aside>

            <div class="dk-ptk-pane" data-task-pane="{{ $selected['key'] }}">
                <header class="dk-ptk-pane-h">
                    <div class="min-w-0">
                        <h3 title="{{ $selected['label'] }}">{{ $selected['label'] }}</h3>
                        <p class="dk-ptk-pane-s">
                            <span>{{ trans_choice('programs.tasks_count', $selected['count'], ['count' => $selected['count']]) }}</span>
                            @if ($selected['overdue'] > 0)
                                <span class="dk-prog-pill dk-bad">{{ trans_choice('programs.ledger_past_due', $selected['overdue'], ['count' => $selected['overdue']]) }}</span>
                            @endif
                            @if ($selected['count'] > 0 && $selectedDone > 0)
                                <span class="dk-ptk-group-bar" title="{{ $selectedDone }}/{{ $selected['count'] }}" aria-hidden="true"><i style="width: {{ round($selectedDone / $selected['count'] * 100) }}%"></i></span>
                            @endif
                        </p>
                    </div>
                    @if ($selected['href'] !== null)
                        <a href="{{ $selected['href'] }}" wire:navigate class="dk-ptk-pane-link">{{ __('programs.tasks_open_bucket') }}<x-desk.icon name="arrow" class="dk-flip" /></a>
                    @endif
                </header>

                @if ($pageTasks->isEmpty())
                    <p class="dk-prog-clear dk-ptk-empty">{{ $searching ? __('programs.tasks_none_match') : __('programs.tasks_none_in_filter') }}</p>
                @else
                    <ul class="dk-ptk-rows" wire:loading.class="dk-ptk-busy" wire:target="pickTaskBucket, goToTaskPage, pickTaskFilter, taskGroupBy, taskSearch">
                        @foreach ($pageTasks as $task)
                            @include('pages.programs._task-row', [
                                'task' => $task,
                                'rowKey' => $selected['key'].'-'.$task->id,
                                'subtasks' => $task->parent_task_id === null ? ($subtasksOf->get($task->id) ?? collect()) : collect(),
                                'parentTitle' => $task->parent_task_id !== null ? $titles->get($task->parent_task_id) : null,
                            ])
                        @endforeach
                    </ul>
                @endif

                {{-- Only when there is something in it: page arrows or the add line. --}}
                @if ($pages > 1 || ($canCreate && $selected['target'] !== null))
                <footer class="dk-ptk-foot">
                    @if ($pages > 1)
                        <nav class="dk-ptk-pages" aria-label="{{ __('programs.tasks_pages') }}">
                            <button type="button" class="dk-ptk-icon-btn" wire:click="goToTaskPage({{ $page - 1 }})" @disabled($page === 1) aria-label="{{ __('programs.tasks_previous_page') }}"><x-desk.icon name="back" class="dk-flip" /></button>
                            <span class="dk-num">{{ __('programs.tasks_page_of', ['page' => $page, 'pages' => $pages]) }}</span>
                            <button type="button" class="dk-ptk-icon-btn" wire:click="goToTaskPage({{ $page + 1 }})" @disabled($page === $pages) aria-label="{{ __('programs.tasks_next_page') }}" data-test="task-next-page"><x-desk.icon name="arrow" class="dk-flip" /></button>
                        </nav>
                    @endif

                    @if ($canCreate && $selected['target'] !== null)
                        <form class="dk-ptk-add" x-data="{ title: '' }" @submit.prevent="if (title.trim() !== '') { $wire.addProgramTask(title, @js($selected['target'])); title = '' }" data-task-add="{{ $selected['target'] }}">
                            <x-desk.icon name="plus" />
                            <input type="text" x-model="title" maxlength="255" placeholder="{{ $selected['key'] === 'all' ? __('programs.tasks_add_to_program') : __('programs.tasks_add_here') }}" aria-label="{{ __('programs.tasks_add_here') }}" />
                        </form>
                    @endif
                </footer>
                @endif
            </div>
        </div>
    @endif

    {{-- The one "more" panel every row's ⋯ button opens: open and delete (subtasks have their own + by the title;
         moving a task to another project is not offered here).
         Teleported to <body>, so it carries dk-scope itself or the Desk icons lose their line style. --}}
    <template x-teleport="body">
        <div class="dk-scope dk-ptk-menu" x-show="more.open" x-cloak x-transition.opacity.duration.100ms
            @click.outside="closeMore()" @keydown.escape.window="closeMore()"
            :style="`top: ${more.top}px; left: ${more.left}px;`" role="menu" data-test="task-more-menu">
            <div x-show="more.view === 'menu'">
                <a :href="more.href" wire:navigate role="menuitem" class="dk-ptk-menu-i"><x-desk.icon name="expand" />{{ __('programs.tasks_open_task') }}</a>
                <div class="dk-ptk-menu-sep" role="separator" x-show="more.canDelete"></div>
                <button type="button" role="menuitem" class="dk-ptk-menu-i dk-bad" x-show="more.canDelete" @click="more.view = 'delete'" data-test="task-more-delete"><x-desk.icon name="x" />{{ __('programs.tasks_delete') }}</button>
            </div>

            <div x-show="more.view === 'delete'" class="dk-ptk-menu-confirm">
                <p>{{ __('programs.tasks_delete_confirm') }}<small>{{ __('programs.tasks_delete_hint') }}</small></p>
                <div>
                    <button type="button" class="dk-ptk-btn" @click="closeMore()">{{ __('programs.tasks_cancel') }}</button>
                    <button type="button" class="dk-ptk-btn dk-bad" @click="run('deleteProgramTask')" data-test="task-delete-confirm">{{ __('programs.tasks_delete') }}</button>
                </div>
            </div>
        </div>
    </template>

    @include('pages.tasks._task-option-menu')
    @include('pages.tasks._assignee-popover')
</section>
