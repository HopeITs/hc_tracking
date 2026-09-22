<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TaskAssignee;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.tasks')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $priority = '';

    #[Url(except: '')]
    public string $assignee = '';

    /** Accepts a milestone id, or the literal 'none' for tasks without one. */
    #[Url(except: '')]
    public string $milestone = '';

    #[Url(as: 'view', except: 'list')]
    public string $view = 'list';

    #[Url(as: 'sort', except: 'due')]
    public string $sortField = 'due';

    #[Url(as: 'dir', except: 'asc')]
    public string $sortDirection = 'asc';

    #[Url(except: 'none')]
    public string $group = 'none';

    #[Url(except: '')]
    public string $month = '';

    public bool $showTaskDrawer = false;
    public ?int $editingTaskId = null;
    public ?int $deletingTaskId = null;
    public bool $showDeleteModal = false;
    public string $title = '';
    public string $description = '';
    public string $taskStatus = 'todo';
    public string $taskPriority = 'medium';
    public string $startDate = '';
    public string $dueDate = '';
    public string $completedDate = '';
    public string $progressPercentage = '0';

    /** @var array<int, string> */
    public array $assignedTo = [];

    public string $taskProposalId = '';
    public string $taskMilestoneId = '';

    /**
     * Per-parent-task draft values for the list view's inline "add subtask"
     * row — its due date, priority, status and assignees. Bound with plain
     * (non-live) wire:model so filling them out costs no network round
     * trips; they're only read once, when addSubtaskQuick() actually creates
     * the subtask in the same request as the Enter keypress that submits
     * the title.
     *
     * @var array<int, array{status?: string, priority?: string, due_date?: string, assignees?: array<int, string>}>
     */
    public array $subtaskDrafts = [];

    /**
     * Per-group draft values for the list view's inline "+ Add task" row —
     * one per group heading (keyed by "{group}|{groupKeyString}", since each
     * heading renders its own composer row independently), covering the
     * same due date, priority, status and assignees a real row edits. Mirrors
     * subtaskDrafts in every other respect.
     *
     * @var array<string, array{status?: string, priority?: string, due_date?: string, assignees?: array<int, string>}>
     */
    public array $taskDrafts = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPriority(): void
    {
        $this->resetPage();
    }

    public function updatedAssignee(): void
    {
        $this->resetPage();
    }

    public function updatedMilestone(): void
    {
        $this->resetPage();
    }

    public function updatedSortField(): void
    {
        $this->resetPage();
    }

    public function updatedSortDirection(): void
    {
        $this->resetPage();
    }

    /**
     * Toggles direction when the same column is clicked again, otherwise
     * switches to the new column with a predictable ascending default.
     */
    public function setSort(string $field): void
    {
        if (! in_array($field, ['title', 'status', 'priority', 'due', 'assignee', 'created'], true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->priority = '';
        $this->assignee = '';
        $this->milestone = '';
        $this->resetPage();
    }

    public function previousMonth(): void
    {
        $this->month = $this->activeMonth()->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->activeMonth()->addMonthNoOverflow()->format('Y-m');
    }

    public function goToToday(): void
    {
        $this->month = '';
    }

    protected function activeMonth(): CarbonImmutable
    {
        if ($this->month !== '' && preg_match('/^\d{4}-\d{2}$/', $this->month) === 1) {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01');

            if ($parsed !== false) {
                return $parsed->startOfMonth();
            }
        }

        return CarbonImmutable::now()->startOfMonth();
    }

    /**
     * The base filtered query, shared by every view so switching views never
     * changes what's shown — just how.
     *
     * @return Builder<Task>
     */
    protected function filteredTasksQuery(): Builder
    {
        $search = trim($this->search);

        return Task::query()
            ->select(['id', 'milestone_id', 'parent_task_id', 'proposal_id', 'title', 'description', 'status', 'priority', 'start_date', 'due_date', 'completed_date', 'progress_percentage'])
            // Subtasks are shown nested under their parent (each parent's own
            // `subtasks` eager load below), never as their own top-level row —
            // so the list/board/calendar and the stat tiles all count and
            // display only top-level tasks.
            ->whereNull('parent_task_id')
            ->withCount('subtasks')
            ->with([
                'milestone:id,name,project_id',
                'milestone.project:id,name',
                'proposal:id,name',
                'taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                // Loaded alongside the count above so the list/board rows can expand
                // a subtask panel in place (client-side, no extra round trip) instead
                // of sending people to the parent task's own page to see them. Each
                // subtask's own assignees are loaded the same way as the parent's.
                'subtasks:id,parent_task_id,title,status,priority,due_date',
                'subtasks.taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('title', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%')
                        ->orWhereHas('taskAssignees', fn (Builder $query) => $query->whereIn('employee_id', HrEmployee::idsMatchingName($search)));
                });
            })
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->priority !== '', fn (Builder $query) => $query->where('priority', $this->priority))
            ->when($this->assignee !== '', fn (Builder $query) => $query->whereHas('taskAssignees', fn (Builder $query) => $query->where('employee_id', (int) $this->assignee)))
            ->when($this->milestone === 'none', fn (Builder $query) => $query->whereNull('milestone_id'))
            ->when($this->milestone !== '' && $this->milestone !== 'none', fn (Builder $query) => $query->where('milestone_id', (int) $this->milestone));
    }

    /** @return LengthAwarePaginator<int, Task> */
    #[Computed]
    public function tasks(): LengthAwarePaginator
    {
        return $this->applySort($this->filteredTasksQuery())
            ->orderBy('id')
            ->paginate(20);
    }

    /**
     * Applies the active column/direction to a filtered query. Every branch
     * pushes ordering to the database so it stays correct under pagination —
     * never sort the already-paginated page in PHP.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    protected function applySort(Builder $query): Builder
    {
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        return match ($this->sortField) {
            'title' => $query->orderBy('title', $direction),
            'priority' => $this->orderByEnumSequence($query, 'priority', ['low', 'medium', 'high'], $direction),
            'status' => $this->orderByEnumSequence($query, 'status', ['todo', 'in progress', 'under review', 'done'], $direction),
            'assignee' => $this->orderByAssigneeName($query, $direction),
            'created' => $query->orderBy('created_at', $direction),
            default => $query->orderByRaw('due_date is null, due_date '.$direction),
        };
    }

    /**
     * Orders by a fixed, meaningful sequence (workflow stage / severity)
     * rather than alphabetically, since 'in progress' < 'todo' alphabetically
     * would be a confusing default.
     *
     * @param  Builder<Task>  $query
     * @param  array<int, string>  $orderedValues
     * @return Builder<Task>
     */
    protected function orderByEnumSequence(Builder $query, string $column, array $orderedValues, string $direction): Builder
    {
        $cases = collect($orderedValues)
            ->map(fn (string $value, int $index): string => "when '{$value}' then {$index}")
            ->implode(' ');

        return $query->orderByRaw("(case {$column} {$cases} end) {$direction}");
    }

    /**
     * A task can have several assignees now, so "sort by assignee" orders by
     * each task's alphabetically-first assignee name — a deterministic,
     * if necessarily simplified, choice once there's more than one name to
     * pick from. Assignees live in the read-only HR database (a separate
     * connection), so names can't be joined in SQL: resolve the small set of
     * (task, employee) pairs in the current filter, compute one representative
     * name per task in PHP, then order the query by that task-id sequence —
     * one extra lightweight query instead of a cross-database join. Tasks
     * with no assignee always sort last.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    protected function orderByAssigneeName(Builder $query, string $direction): Builder
    {
        $taskIds = (clone $query)->pluck('id');

        if ($taskIds->isEmpty()) {
            return $query;
        }

        $pairs = TaskAssignee::query()
            ->whereIn('task_id', $taskIds)
            ->get(['task_id', 'employee_id']);

        if ($pairs->isEmpty()) {
            return $query;
        }

        $employeeNamesById = HrEmployee::query()
            ->whereIn('id', $pairs->pluck('employee_id')->unique())
            ->get()
            ->pluck('name', 'id');

        $orderedTaskIds = $pairs
            ->groupBy('task_id')
            ->map(fn (Collection $assignees): ?string => $assignees
                ->map(fn (TaskAssignee $assignee): ?string => $employeeNamesById->get($assignee->employee_id))
                ->filter()
                ->sort()
                ->first())
            ->filter()
            ->sort()
            ->when($direction === 'desc', fn (Collection $names) => $names->reverse())
            ->keys();

        if ($orderedTaskIds->isEmpty()) {
            return $query;
        }

        $cases = $orderedTaskIds
            ->values()
            ->map(fn ($id, int $index): string => 'when '.(int) $id." then {$index}")
            ->implode(' ');

        return $query
            ->orderByRaw('id not in ('.$orderedTaskIds->map(fn ($id): string => (string) (int) $id)->implode(',').')')
            ->orderByRaw("(case id {$cases} end)");
    }

    /**
     * Every task matching the current filters, unpaginated, for the board and
     * calendar views (and the stats built from them) — capped at a sane
     * ceiling so a very large backlog can't make either view unusable.
     *
     * @return EloquentCollection<int, Task>
     */
    #[Computed]
    public function boardTasks(): EloquentCollection
    {
        return $this->filteredTasksQuery()
            ->orderByRaw('due_date is null, due_date')
            ->orderBy('id')
            ->limit(500)
            ->get();
    }

    /**
     * Delivery health across the currently filtered tasks, computed as a
     * single aggregate query rather than hydrating every matching task (and
     * its milestone/proposal/assignee relations) just to count them — this
     * ran on every list-view page load via boardTasks() before, which is a
     * full board/calendar-sized query the list view never otherwise needs.
     *
     * @return array{total: int, done: int, overdue: int, due_soon: int}
     */
    #[Computed]
    public function taskStats(): array
    {
        // Dates are server-generated (never user input), so it's safe to
        // inline them literally instead of passing bindings through a raw
        // select expression — Eloquent's select() replaces the column list
        // rather than appending to it, which selectRaw()/addSelect() would.
        $today = CarbonImmutable::today()->toDateString();
        $dueSoonEnd = CarbonImmutable::today()->addDays(7)->toDateString();

        $row = $this->filteredTasksQuery()->toBase()->select(DB::raw(
            "count(*) as total,
            sum(case when status = 'done' then 1 else 0 end) as done,
            sum(case when status <> 'done' and due_date is not null and due_date < '{$today}' then 1 else 0 end) as overdue,
            sum(case when status <> 'done' and due_date is not null and due_date between '{$today}' and '{$dueSoonEnd}' then 1 else 0 end) as due_soon",
        ))->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'done' => (int) ($row->done ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'due_soon' => (int) ($row->due_soon ?? 0),
        ];
    }

    /**
     * The month grid for the calendar view, built from the same filtered
     * tasks as the other views. Tasks without a due date are returned
     * separately for the "unscheduled" panel.
     *
     * @return array{month: CarbonImmutable, weeks: array<int, array<int, CarbonImmutable>>, tasksByDay: Collection<string, EloquentCollection<int, Task>>, unscheduled: EloquentCollection<int, Task>}
     */
    #[Computed]
    public function calendarGrid(): array
    {
        $month = $this->activeMonth();
        $gridStart = $month->startOfWeek(CarbonImmutable::SUNDAY);
        $gridEnd = $month->endOfMonth()->endOfWeek(CarbonImmutable::SATURDAY);

        $weeks = [];
        $cursor = $gridStart;
        while ($cursor->lte($gridEnd)) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $week[] = $cursor;
                $cursor = $cursor->addDay();
            }
            $weeks[] = $week;
        }

        $tasks = $this->boardTasks;

        $tasksByDay = $tasks
            ->filter(fn (Task $task): bool => $task->due_date !== null && $task->due_date->between($gridStart, $gridEnd))
            ->groupBy(fn (Task $task): string => $task->due_date->toDateString());

        $unscheduled = $tasks->filter(fn (Task $task): bool => $task->due_date === null)->values();

        return [
            'month' => $month,
            'weeks' => $weeks,
            'tasksByDay' => $tasksByDay,
            'unscheduled' => $unscheduled,
        ];
    }

    /**
     * These three option lists back every filter, drawer field, and quick-set
     * popover, so without caching they were re-queried in full on every
     * single quick action (toggle complete, quick status, reschedule, …)
     * even though nothing about the employees/proposals/milestones changed.
     * `persist` keys the cache to this component instance, so it never leaks
     * across users and expires with the page (5 minutes is just a safety
     * bound against an unusually long-lived tab, not a real staleness risk).
     *
     * Returned as plain arrays, not Eloquent collections/models: the cache
     * store's `serializable_classes` is `false` (intentionally, to block
     * gadget-chain attacks — see config/cache.php), so any object graph
     * silently fails to unserialize on every read. `persist` would then
     * re-run the query AND re-write the cache on every single request,
     * which is slower than no caching at all — this is what actually made
     * every quick action take seconds.
     *
     * @return array<int, array{id: int, name: string, secondary_name: string}>
     */
    #[Computed(persist: true, seconds: 300)]
    public function userOptions(): array
    {
        return HrEmployee::pickerOptions()
            ->map(fn (HrEmployee $employee): array => [
                'id' => $employee->id,
                'name' => $employee->name,
                'secondary_name' => $employee->secondary_name,
            ])
            ->all();
    }

    /** @return array<int, array{id: int, name: string}> */
    #[Computed(persist: true, seconds: 300)]
    public function proposalOptions(): array
    {
        return Proposal::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get()
            ->map(fn (Proposal $proposal): array => ['id' => $proposal->id, 'name' => $proposal->name])
            ->all();
    }

    /** @return array<int, array{id: int, name: string, project_name: string}> */
    #[Computed(persist: true, seconds: 300)]
    public function milestoneOptions(): array
    {
        return Milestone::query()
            ->select(['id', 'name', 'project_id'])
            ->with('project:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Milestone $milestone): array => [
                'id' => $milestone->id,
                'name' => $milestone->name,
                'project_name' => $milestone->project->name,
            ])
            ->all();
    }

    #[Computed]
    public function deletingTask(): ?Task
    {
        if ($this->deletingTaskId === null) {
            return null;
        }

        return Task::query()->select(['id', 'title'])->find($this->deletingTaskId);
    }

    public function openCreateDrawer(?string $dueDate = null, ?string $status = null, ?string $milestoneId = null): void
    {
        $this->resetTaskForm();

        if ($dueDate !== null) {
            $this->dueDate = $dueDate;
        }

        if ($status !== null && TaskStatus::tryFrom($status) !== null) {
            $this->taskStatus = $status;
        }

        if ($milestoneId !== null && Milestone::query()->whereKey((int) $milestoneId)->exists()) {
            $this->taskMilestoneId = $milestoneId;
        }

        $this->showTaskDrawer = true;
    }

    /**
     * A group's inline "+ Add task" row only captures a title, but a task
     * always needs a proposal and an assignee. The proposal is inferred from
     * context so the row can create the task directly, with no drawer:
     *  - proposal: the one proposal funding the resolved milestone's project
     *    (its own milestoneId, falling back to the active milestone filter),
     *    only when that project is funded by exactly one — a project funded
     *    by several, or no milestone context at all, is genuinely ambiguous.
     *  - assignee: whichever employees the row's own draft (see taskDraft())
     *    has picked, falling back to the signed-in user's own HR employee
     *    record when the row hasn't been touched.
     * Due date, priority and status also come from that same draft, same as
     * addSubtaskQuick()'s own draft. When the proposal or every assignee
     * can't be resolved, this falls back to the drawer exactly as before,
     * pre-filled with the title and whatever the row's own context and
     * draft already had, so nothing is ever silently dropped.
     */
    public function quickCreateTask(string $title, ?string $status = null, ?string $milestoneId = null, ?string $rowKey = null): void
    {
        $title = trim($title);

        if ($title === '') {
            return;
        }

        $draft = $rowKey !== null ? $this->taskDraft($rowKey, $status) : null;

        $proposalId = $this->resolveInlineProposalId($milestoneId);
        $employeeIds = $draft !== null && $draft['assignees'] !== []
            ? HrEmployee::query()->whereIn('id', collect($draft['assignees'])->map(fn (string $id): int => (int) $id))->pluck('id')
            : collect(array_filter([$this->resolveInlineAssigneeId()]));

        if ($proposalId === null || $employeeIds->isEmpty()) {
            $this->openCreateDrawer(status: $draft !== null ? $draft['status'] : $status, milestoneId: $milestoneId);
            $this->title = $title;

            if ($draft !== null) {
                $this->taskPriority = $draft['priority'];
                $this->dueDate = $draft['due_date'];
                $this->assignedTo = $draft['assignees'];
            }

            return;
        }

        $statusCase = TaskStatus::tryFrom(($draft !== null ? $draft['status'] : $status) ?? '') ?? TaskStatus::Todo;
        $priorityCase = $draft !== null ? (TaskPriority::tryFrom($draft['priority']) ?? TaskPriority::Medium) : TaskPriority::Medium;
        $dueDate = $draft !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $draft['due_date']) === 1 ? $draft['due_date'] : null;

        DB::transaction(function () use ($title, $statusCase, $priorityCase, $dueDate, $milestoneId, $proposalId, $employeeIds): void {
            $task = Task::query()->create([
                'title' => $title,
                'status' => $statusCase,
                'priority' => $priorityCase,
                'due_date' => $dueDate,
                'progress_percentage' => $statusCase === TaskStatus::Done ? 100 : 0,
                'completed_date' => $statusCase === TaskStatus::Done ? CarbonImmutable::today() : null,
                'proposal_id' => $proposalId,
                'milestone_id' => $milestoneId !== null ? (int) $milestoneId : null,
                'created_by' => (int) auth()->id(),
            ]);

            foreach ($employeeIds as $employeeId) {
                $task->taskAssignees()->create(['employee_id' => $employeeId]);
            }
        });

        if ($rowKey !== null) {
            unset($this->taskDrafts[$rowKey]);
        }

        $this->resetPage();

        Flux::toast(variant: 'success', text: __('tasks.task_created'));
    }

    /**
     * The list view's inline "+ Add task" row's current draft values for one
     * group heading, merged with sensible defaults so the row always renders
     * something sane before the user touches it. $defaultStatus lets a
     * status-grouped heading default its own row to that column's status
     * instead of always "todo" — mirrors subtaskDraft() in every other
     * respect.
     *
     * @return array{status: string, priority: string, due_date: string, assignees: array<int, string>}
     */
    public function taskDraft(string $rowKey, ?string $defaultStatus = null): array
    {
        $defaults = [
            'status' => $defaultStatus ?? TaskStatus::Todo->value,
            'priority' => TaskPriority::Medium->value,
            'due_date' => '',
            'assignees' => [],
        ];

        return array_merge($defaults, $this->taskDrafts[$rowKey] ?? []);
    }

    /**
     * Adds or removes one employee from a group heading's "+ Add task" draft
     * — mirrors toggleSubtaskDraftAssignee() but for a not-yet-created
     * top-level task instead of a subtask.
     */
    public function toggleTaskDraftAssignee(string $rowKey, int $employeeId): void
    {
        $ids = $this->taskDraft($rowKey)['assignees'];
        $employeeIdString = (string) $employeeId;

        $this->taskDrafts[$rowKey]['assignees'] = in_array($employeeIdString, $ids, true)
            ? array_values(array_diff($ids, [$employeeIdString]))
            : [...$ids, $employeeIdString];
    }

    /**
     * Resolves the single proposal a group's inline "+ Add task" row can
     * safely default to, or null when that would be a guess.
     *
     * @return int|null
     */
    protected function resolveInlineProposalId(?string $milestoneId): ?int
    {
        $resolvedMilestoneId = $milestoneId ?? (
            $this->milestone !== '' && $this->milestone !== 'none' ? $this->milestone : null
        );

        if ($resolvedMilestoneId === null) {
            return null;
        }

        $milestone = Milestone::query()->select(['id', 'project_id'])->find((int) $resolvedMilestoneId);

        if ($milestone === null) {
            return null;
        }

        $proposalIds = Proposal::query()
            ->whereHas('projects', fn (Builder $query) => $query->whereKey($milestone->project_id))
            ->pluck('id');

        return $proposalIds->count() === 1 ? $proposalIds->first() : null;
    }

    /** The signed-in user's own HR employee id, when their account is linked to one. */
    protected function resolveInlineAssigneeId(): ?int
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        return $user->employee_id;
    }

    /**
     * The list view's inline "add subtask" row's current draft values for a
     * parent task, merged with sensible defaults — todo/medium, no due date,
     * and the parent's own assignees — so the row always renders something
     * sane before the user touches it. Takes the parent (already loaded with
     * its assignees by filteredTasksQuery()) rather than an id, so reading
     * this once per row costs no extra query.
     *
     * @return array{status: string, priority: string, due_date: string, assignees: array<int, string>}
     */
    public function subtaskDraft(Task $parent): array
    {
        $defaults = [
            'status' => TaskStatus::Todo->value,
            'priority' => TaskPriority::Medium->value,
            'due_date' => '',
            'assignees' => $parent->taskAssignees->pluck('employee_id')->map(fn (int $id): string => (string) $id)->all(),
        ];

        return array_merge($defaults, $this->subtaskDrafts[$parent->id] ?? []);
    }

    /**
     * Adds or removes one employee from a parent task's subtask draft, for
     * the "add subtask" row's own assignee popover — mirrors toggleAssignee()
     * but writes to the not-yet-saved draft instead of a real task.
     */
    public function toggleSubtaskDraftAssignee(int $parentTaskId, int $employeeId): void
    {
        $parent = Task::query()->with('taskAssignees:id,task_id,employee_id')->find($parentTaskId);

        if ($parent === null) {
            return;
        }

        $ids = $this->subtaskDraft($parent)['assignees'];
        $employeeIdString = (string) $employeeId;

        $this->subtaskDrafts[$parentTaskId]['assignees'] = in_array($employeeIdString, $ids, true)
            ? array_values(array_diff($ids, [$employeeIdString]))
            : [...$ids, $employeeIdString];
    }

    /**
     * Adds a one-line subtask under an already-visible parent task, from the
     * inline row in its expanded panel — no drawer. Proposal and milestone
     * always match the parent (a subtask delivers the same thing its parent
     * does), while due date, priority, status and assignees come from the
     * row's own draft fields, defaulting to the parent's own assignees when
     * none were chosen. Mirrors the show page's own addSubtask(), kept
     * separate because this is a different Livewire component instance.
     */
    public function addSubtaskQuick(int $parentTaskId, string $title): void
    {
        $title = trim($title);

        if ($title === '') {
            return;
        }

        $parent = Task::query()->with('taskAssignees:id,task_id,employee_id')->find($parentTaskId);

        if ($parent === null || $parent->parent_task_id !== null) {
            return;
        }

        $draft = $this->subtaskDraft($parent);
        $status = TaskStatus::tryFrom($draft['status']) ?? TaskStatus::Todo;
        $priority = TaskPriority::tryFrom($draft['priority']) ?? TaskPriority::Medium;
        $dueDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $draft['due_date']) === 1 ? $draft['due_date'] : null;

        $employeeIds = HrEmployee::query()
            ->whereIn('id', collect($draft['assignees'])->map(fn (string $id): int => (int) $id))
            ->pluck('id');

        if ($employeeIds->isEmpty()) {
            $employeeIds = $parent->taskAssignees->pluck('employee_id');
        }

        DB::transaction(function () use ($parent, $title, $status, $priority, $dueDate, $employeeIds): void {
            $subtask = Task::query()->create([
                'parent_task_id' => $parent->id,
                'proposal_id' => $parent->proposal_id,
                'milestone_id' => $parent->milestone_id,
                'title' => $title,
                'status' => $status,
                'priority' => $priority,
                'due_date' => $dueDate,
                'progress_percentage' => $status === TaskStatus::Done ? 100 : 0,
                'completed_date' => $status === TaskStatus::Done ? CarbonImmutable::today() : null,
                'created_by' => (int) auth()->id(),
            ]);

            foreach ($employeeIds as $employeeId) {
                $subtask->taskAssignees()->create(['employee_id' => $employeeId]);
            }
        });

        unset($this->subtaskDrafts[$parentTaskId]);

        Flux::toast(variant: 'success', text: __('tasks.subtask_added'));
    }

    public function openEditDrawer(int $taskId): void
    {
        $task = Task::query()
            ->select(['id', 'milestone_id', 'proposal_id', 'title', 'description', 'status', 'priority', 'start_date', 'due_date', 'completed_date', 'progress_percentage'])
            ->with('taskAssignees:id,task_id,employee_id')
            ->findOrFail($taskId);

        $this->resetValidation();
        $this->editingTaskId = $task->id;
        $this->title = $task->title;
        $this->description = $task->description ?? '';
        $this->taskStatus = $task->status->value;
        $this->taskPriority = $task->priority->value;
        $this->startDate = $task->start_date?->toDateString() ?? '';
        $this->dueDate = $task->due_date?->toDateString() ?? '';
        $this->completedDate = $task->completed_date?->toDateString() ?? '';
        $this->progressPercentage = (string) $task->progress_percentage;
        $this->assignedTo = $task->taskAssignees->pluck('employee_id')->map(fn (int $id): string => (string) $id)->all();
        $this->taskProposalId = (string) $task->proposal_id;
        $this->taskMilestoneId = (string) ($task->milestone_id ?? '');
        $this->showTaskDrawer = true;
    }

    public function saveTask(): void
    {
        $this->trimTaskForm();

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'taskStatus' => ['required', new Enum(TaskStatus::class)],
            'taskPriority' => ['required', new Enum(TaskPriority::class)],
            'startDate' => ['nullable', 'date'],
            'dueDate' => ['nullable', 'date', Rule::when($this->startDate !== '', ['after_or_equal:startDate'])],
            'completedDate' => ['nullable', 'date'],
            'progressPercentage' => ['required', 'integer', 'min:0', 'max:100'],
            'assignedTo' => ['required', 'array', 'min:1'],
            'assignedTo.*' => ['integer', Rule::exists(HrEmployee::class, 'id')],
            'taskProposalId' => ['required', 'integer', Rule::exists(Proposal::class, 'id')],
            'taskMilestoneId' => ['nullable', 'integer', Rule::exists(Milestone::class, 'id')],
        ], [
            'title.required' => __('tasks.enter_a_task_title'),
            'assignedTo.required' => __('tasks.assign_this_task_to_someone'),
            'assignedTo.min' => __('tasks.assign_this_task_to_someone'),
            'assignedTo.*.exists' => __('tasks.the_selected_assignee_is_no_longer_available'),
            'taskProposalId.required' => __('tasks.select_the_proposal_this_task_delivers_against'),
            'taskProposalId.exists' => __('common.the_selected_proposal_is_no_longer_available'),
            'taskMilestoneId.exists' => __('tasks.the_selected_milestone_is_no_longer_available'),
            'dueDate.after_or_equal' => __('tasks.the_due_date_cannot_fall_before_the_start_date'),
            'progressPercentage.min' => __('tasks.progress_cannot_be_negative'),
            'progressPercentage.max' => __('tasks.progress_cannot_exceed_100_percent'),
        ]);

        $isCreate = $this->editingTaskId === null;
        $task = $isCreate ? new Task : Task::query()->findOrFail($this->editingTaskId);
        $attributes = [
            'title' => $validated['title'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
            'status' => $validated['taskStatus'],
            'priority' => $validated['taskPriority'],
            'start_date' => $validated['startDate'] === '' ? null : $validated['startDate'],
            'due_date' => $validated['dueDate'] === '' ? null : $validated['dueDate'],
            'completed_date' => $validated['completedDate'] === '' ? null : $validated['completedDate'],
            'progress_percentage' => (int) $validated['progressPercentage'],
            'proposal_id' => (int) $validated['taskProposalId'],
            'milestone_id' => $validated['taskMilestoneId'] === '' || $validated['taskMilestoneId'] === null ? null : (int) $validated['taskMilestoneId'],
        ];

        if ($isCreate) {
            $attributes['created_by'] = (int) auth()->id();
        }

        DB::transaction(function () use ($task, $attributes, $validated): void {
            $task->fill($attributes)->save();

            $employeeIds = collect($validated['assignedTo'])->map(fn ($id): int => (int) $id)->unique()->values();

            $task->taskAssignees()->whereNotIn('employee_id', $employeeIds)->delete();
            $existingIds = $task->taskAssignees()->pluck('employee_id');

            $employeeIds->diff($existingIds)->each(fn (int $employeeId) => $task->taskAssignees()->create([
                'employee_id' => $employeeId,
            ]));
        });

        $this->showTaskDrawer = false;
        $this->resetTaskForm();
        $this->resetPage();

        Flux::toast(variant: 'success', text: $isCreate ? __('tasks.task_created') : __('tasks.task_updated'));
    }

    public function confirmDelete(int $taskId): void
    {
        $this->deletingTaskId = $taskId;
        $this->showDeleteModal = true;
        unset($this->deletingTask);
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deletingTaskId = null;
        unset($this->deletingTask);
    }

    public function deleteTask(): void
    {
        if ($this->deletingTaskId === null) {
            return;
        }

        $taskId = $this->deletingTaskId;
        $deleted = DB::transaction(function () use ($taskId): bool {
            $task = Task::query()->lockForUpdate()->find($taskId);

            if ($task === null || $task->subtasks()->exists()) {
                return false;
            }

            return (bool) $task->delete();
        });

        $this->showDeleteModal = false;
        $this->deletingTaskId = null;
        unset($this->deletingTask);

        if (! $deleted) {
            Flux::toast(variant: 'danger', text: __('tasks.this_task_still_has_subtasks_move_or_delete_them_first'));

            return;
        }

        $this->resetPage();

        Flux::toast(variant: 'success', text: __('tasks.task_deleted'));
    }

    public function toggleComplete(int $taskId): void
    {
        $task = Task::query()->find($taskId);

        if ($task === null) {
            return;
        }

        if ($task->status === TaskStatus::Done) {
            $task->status = TaskStatus::Todo;
            $task->completed_date = null;
        } else {
            $task->status = TaskStatus::Done;
            $task->completed_date = CarbonImmutable::today();
            $task->progress_percentage = 100;
        }

        $task->save();
    }

    public function quickSetStatus(int $taskId, string $status): void
    {
        $newStatus = TaskStatus::tryFrom($status);
        $task = Task::query()->find($taskId);

        if ($task === null) {
            // Most likely dropped after being deleted elsewhere — the board
            // re-renders from the database regardless, so the card already
            // "rolls back" to wherever the data actually says it is; this
            // just explains why the drop didn't visibly do anything.
            Flux::toast(variant: 'danger', text: __('tasks.this_task_no_longer_exists'));

            return;
        }

        if ($newStatus === null) {
            return;
        }

        $task->status = $newStatus;

        if ($newStatus === TaskStatus::Done) {
            $task->completed_date ??= CarbonImmutable::today();
            $task->progress_percentage = 100;
        } elseif ($task->completed_date !== null) {
            $task->completed_date = null;
        }

        $task->save();
    }

    public function quickSetPriority(int $taskId, string $priority): void
    {
        $newPriority = TaskPriority::tryFrom($priority);
        $task = Task::query()->find($taskId);

        if ($newPriority === null || $task === null) {
            return;
        }

        $task->priority = $newPriority;
        $task->save();
    }

    /**
     * Adds or removes one assignee from a task's list, for the inline
     * multi-select popover on the list row — the drawer's own checkbox list
     * covers the same ground for a full edit, this is the quick path. A
     * task must always keep at least one assignee, matching saveTask()'s
     * own validation, so the last one can't be toggled off here either.
     */
    public function toggleAssignee(int $taskId, int $employeeId): void
    {
        $task = Task::query()->find($taskId);

        if ($task === null || ! HrEmployee::query()->whereKey($employeeId)->exists()) {
            return;
        }

        $existing = $task->taskAssignees()->where('employee_id', $employeeId)->first();

        if ($existing !== null) {
            if ($task->taskAssignees()->count() <= 1) {
                Flux::toast(variant: 'danger', text: __('tasks.assign_this_task_to_someone'));

                return;
            }

            $existing->delete();

            return;
        }

        $task->taskAssignees()->create(['employee_id' => $employeeId]);
    }

    public function rescheduleTask(int $taskId, string $date): void
    {
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }

        $task = Task::query()->find($taskId);

        if ($task === null) {
            return;
        }

        $task->due_date = $date === '' ? null : CarbonImmutable::parse($date);
        $task->save();

        Flux::toast(variant: 'success', text: __('tasks.task_rescheduled'));
    }

    public function closeTaskDrawer(): void
    {
        $this->showTaskDrawer = false;
        $this->resetTaskForm();
    }

    protected function resetTaskForm(): void
    {
        $this->resetValidation();
        $this->editingTaskId = null;
        $this->title = '';
        $this->description = '';
        $this->taskStatus = TaskStatus::Todo->value;
        $this->taskPriority = TaskPriority::Medium->value;
        $this->startDate = '';
        $this->dueDate = '';
        $this->completedDate = '';
        $this->progressPercentage = '0';
        $this->assignedTo = [];
        $this->taskProposalId = '';
        $this->taskMilestoneId = '';
    }

    protected function trimTaskForm(): void
    {
        foreach (['title', 'description', 'taskStatus', 'taskPriority', 'startDate', 'dueDate', 'completedDate', 'progressPercentage', 'taskProposalId', 'taskMilestoneId'] as $property) {
            $this->{$property} = trim($this->{$property});
        }
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $status !== '' || $priority !== '' || $assignee !== '' || $milestone !== '';
@endphp

<section class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-5 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="task-directory-heading">
    <x-ui.page-header heading-id="task-directory-heading" :title="__('common.tasks')" :count="$this->taskStats['total']">
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('tasks.search_tasks')" :placeholder="__('tasks.title_description_or_assignee')" wire:model.live.debounce.300ms="search" clearable />
            <x-ui.header-filter-select :label="__('common.status')" wire:model.live="status" :value="$status">
                <flux:select.option value="">{{ __('common.all_statuses') }}</flux:select.option>
                @foreach (TaskStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'task-status-filter-'.$statusCase->value">{{ $statusCase->label() }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
            <x-ui.header-filter-select :label="__('tasks.priority')" wire:model.live="priority" :value="$priority">
                <flux:select.option value="">{{ __('tasks.all_priorities') }}</flux:select.option>
                @foreach (TaskPriority::cases() as $priorityCase)
                    <flux:select.option :value="$priorityCase->value" :wire:key="'task-priority-filter-'.$priorityCase->value">{{ $priorityCase->label() }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
            <x-ui.header-filter-select :label="__('tasks.assignee')" wire:model.live="assignee" :value="$assignee">
                <flux:select.option value="">{{ __('common.anyone') }}</flux:select.option>
                @foreach ($this->userOptions as $userOption)
                    <flux:select.option :value="$userOption['id']" :wire:key="'task-assignee-filter-'.$userOption['id']">{{ $userOption['name'] }} · {{ $userOption['secondary_name'] }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
            <x-ui.header-filter-select :label="__('tasks.milestone')" wire:model.live="milestone" :value="$milestone">
                <flux:select.option value="">{{ __('tasks.any_milestone') }}</flux:select.option>
                <flux:select.option value="none">{{ __('tasks.no_milestone') }}</flux:select.option>
                @foreach ($this->milestoneOptions as $milestoneOption)
                    <flux:select.option :value="$milestoneOption['id']" :wire:key="'task-milestone-filter-'.$milestoneOption['id']">{{ $milestoneOption['name'] }} · {{ $milestoneOption['project_name'] }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>
        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateDrawer" data-test="create-task-button">{{ __('tasks.add_task') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-3 @2xl/directory:grid-cols-2 @5xl/directory:grid-cols-4">
        <x-ui.stat-tile :label="__('tasks.total_tasks')" :value="$this->taskStats['total']" icon="clipboard-document-check" wire:key="task-stat-total" />
        <x-ui.stat-tile :label="TaskStatus::Done->label()" :value="$this->taskStats['done']" icon="check-circle" tone="good" wire:key="task-stat-done" />
        <x-ui.stat-tile :label="__('tasks.overdue')" :value="$this->taskStats['overdue']" icon="exclamation-triangle" tone="critical" wire:key="task-stat-overdue" />
        <x-ui.stat-tile :label="__('tasks.due_within_7_days')" :value="$this->taskStats['due_soon']" icon="clock" tone="warn" wire:key="task-stat-due-soon" />
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex flex-wrap gap-1 rounded-lg border border-secondary-soft bg-secondary-soft/40 p-1 dark:border-secondary-medium dark:bg-secondary-medium/40" role="group" aria-label="{{ __('tasks.view') }}">
            @foreach ([
                'list' => ['icon' => 'queue-list', 'label' => __('tasks.list_view')],
                'board' => ['icon' => 'view-columns', 'label' => __('tasks.board_view')],
                'calendar' => ['icon' => 'calendar-days', 'label' => __('tasks.calendar_view')],
            ] as $viewOption => $viewMeta)
                <button
                    type="button"
                    wire:click="$set('view', '{{ $viewOption }}')"
                    aria-pressed="{{ $view === $viewOption ? 'true' : 'false' }}"
                    data-test="task-view-{{ $viewOption }}"
                    @class([
                        'inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-md px-3 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                        'bg-white text-secondary-dark shadow-sm dark:bg-secondary-dark dark:text-white' => $view === $viewOption,
                        'text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white' => $view !== $viewOption,
                    ])
                >
                    <flux:icon :name="$viewMeta['icon']" variant="micro" class="size-4" aria-hidden="true" />
                    {{ $viewMeta['label'] }}
                </button>
            @endforeach
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            @if ($view === 'list')
                <x-ui.header-filter-select :label="__('tasks.group_by')" wire:model.live="group" :value="$group" class="w-40!">
                    <flux:select.option value="none">{{ __('tasks.no_grouping') }}</flux:select.option>
                    <flux:select.option value="status">{{ __('common.status') }}</flux:select.option>
                    <flux:select.option value="priority">{{ __('tasks.priority') }}</flux:select.option>
                    <flux:select.option value="assignee">{{ __('tasks.assignee') }}</flux:select.option>
                    <flux:select.option value="milestone">{{ __('tasks.milestone') }}</flux:select.option>
                </x-ui.header-filter-select>
                <x-ui.header-filter-select :label="__('tasks.sort_by')" wire:model.live="sortField" :value="$sortField" class="w-40!">
                    <flux:select.option value="due">{{ __('tasks.due_date_2') }}</flux:select.option>
                    <flux:select.option value="title">{{ __('common.name') }}</flux:select.option>
                    <flux:select.option value="status">{{ __('common.status') }}</flux:select.option>
                    <flux:select.option value="priority">{{ __('tasks.priority') }}</flux:select.option>
                    <flux:select.option value="assignee">{{ __('tasks.assignee') }}</flux:select.option>
                    <flux:select.option value="created">{{ __('tasks.created_date') }}</flux:select.option>
                </x-ui.header-filter-select>
                <x-ui.button
                    size="sm"
                    wire:click="$set('sortDirection', '{{ $sortDirection === 'asc' ? 'desc' : 'asc' }}')"
                    :aria-label="$sortDirection === 'asc' ? __('tasks.sort_ascending') : __('tasks.sort_descending')"
                    data-test="task-sort-direction-toggle"
                >
                    <flux:icon :name="$sortDirection === 'asc' ? 'bars-arrow-up' : 'bars-arrow-down'" variant="micro" class="size-4" aria-hidden="true" />
                </x-ui.button>
            @endif

            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="self-end" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
            @endif
        </div>
    </div>

    @if ($this->taskStats['total'] === 0)
        <x-ui.panel>
            <x-ui.empty-state icon="clipboard-document-check" :heading="$filtersAreActive ? __('tasks.no_matching_tasks') : __('common.no_tasks_yet')" :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('tasks.add_the_first_task_against_a_proposal')">
                <x-slot:action>
                    @if ($filtersAreActive)
                        <x-ui.button size="sm" wire:click="clearFilters">{{ __('common.reset_filters') }}</x-ui.button>
                    @else
                        <x-ui.button size="sm" tone="primary" icon="plus" wire:click="openCreateDrawer">{{ __('tasks.add_task') }}</x-ui.button>
                    @endif
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.panel>
    @else
        @switch ($view)
            @case ('board')
                @include('pages.tasks._board')
                @break

            @case ('calendar')
                @include('pages.tasks._calendar')
                @break

            @default
                @include('pages.tasks._list')
        @endswitch
    @endif

    <x-ui.drawer wire:model="showTaskDrawer" :title="$editingTaskId === null ? __('tasks.add_task') : __('tasks.edit_task')" :subtitle="__('tasks.every_task_delivers_against_a_proposal_a_milestone_is_option')">
        <form wire:submit="saveTask" id="task-drawer-form" class="flex flex-col gap-5">
            <x-ui.input wire:model="title" :label="__('tasks.task_title')" type="text" maxlength="255" required autofocus />
            <flux:textarea wire:model="description" :label="__('common.description')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model="taskProposalId" :label="__('common.proposal')" required>
                    <flux:select.option value="">{{ __('common.select_a_proposal') }}</flux:select.option>
                    @foreach ($this->proposalOptions as $proposalOption)
                        <flux:select.option :value="$proposalOption['id']" :wire:key="'task-proposal-option-'.$proposalOption['id']">{{ $proposalOption['name'] }}</flux:select.option>
                    @endforeach
                </x-ui.select>
                <x-ui.select wire:model="taskMilestoneId" :label="__('tasks.milestone')">
                    <flux:select.option value="">{{ __('tasks.no_milestone') }}</flux:select.option>
                    @foreach ($this->milestoneOptions as $milestoneOption)
                        <flux:select.option :value="$milestoneOption['id']" :wire:key="'task-milestone-option-'.$milestoneOption['id']">{{ $milestoneOption['name'] }} · {{ $milestoneOption['project_name'] }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model="taskStatus" :label="__('common.status')" required>
                    @foreach (TaskStatus::cases() as $statusCase)
                        <flux:select.option :value="$statusCase->value" :wire:key="'task-status-option-'.$statusCase->value">{{ $statusCase->label() }}</flux:select.option>
                    @endforeach
                </x-ui.select>
                <x-ui.select wire:model="taskPriority" :label="__('tasks.priority')" required>
                    @foreach (TaskPriority::cases() as $priorityCase)
                        <flux:select.option :value="$priorityCase->value" :wire:key="'task-priority-option-'.$priorityCase->value">{{ $priorityCase->label() }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <x-ui.input wire:model="progressPercentage" :label="__('tasks.progress_2')" type="number" min="0" max="100" required />

            <div class="min-w-0">
                <label class="mb-1.5 block text-[11px] font-semibold tracking-[0.1em] text-secondary-medium uppercase dark:text-secondary-soft">{{ __('tasks.assignees') }}</label>
                @php
                    $selectedAssigneeOptions = collect($this->userOptions)->whereIn('id', collect($assignedTo)->map(fn ($id) => (int) $id));
                    $assigneeTriggerLabel = match (true) {
                        $selectedAssigneeOptions->isEmpty() => __('common.select'),
                        $selectedAssigneeOptions->count() <= 2 => $selectedAssigneeOptions->pluck('name')->implode(', '),
                        default => __('common.count_selected', ['count' => $selectedAssigneeOptions->count()]),
                    };
                @endphp
                <x-ui.multi-select-popover
                    :label="__('tasks.assignees')"
                    class="flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg border border-secondary-light bg-white px-3 py-2 text-start text-sm dark:border-secondary-light dark:bg-secondary-medium"
                    data-test="task-assignee-picker"
                >
                    <x-slot:trigger>
                        <span class="min-w-0 truncate">{{ $assigneeTriggerLabel }}</span>
                        <flux:icon.chevron-up-down class="size-4 shrink-0 text-secondary-medium dark:text-secondary-soft" aria-hidden="true" />
                    </x-slot:trigger>

                    @foreach ($this->userOptions as $userOption)
                        <label
                            x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"
                            class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-secondary-soft dark:hover:bg-secondary-medium"
                        >
                            <input type="checkbox" wire:model.live="assignedTo" value="{{ $userOption['id'] }}" class="rounded border-secondary-light text-primary focus:ring-primary" data-test="task-assignee-checkbox-{{ $userOption['id'] }}" />
                            <span class="min-w-0 truncate">{{ $userOption['name'] }} · {{ $userOption['secondary_name'] }}</span>
                        </label>
                    @endforeach
                </x-ui.multi-select-popover>
                <flux:error name="assignedTo" class="mt-1.5" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input wire:model="startDate" :label="__('common.start_date')" type="date" />
                <x-ui.input wire:model="dueDate" :label="__('tasks.due_date_2')" type="date" />
                <x-ui.input wire:model="completedDate" :label="__('tasks.completed_date')" type="date" />
            </div>
        </form>

        <x-slot:footer>
            <div class="grid grid-cols-2 gap-2 sm:flex sm:justify-end">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="closeTaskDrawer">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="task-drawer-form" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" data-test="save-task-button">{{ $editingTaskId === null ? __('tasks.create_task') : __('common.save_changes') }}</x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.drawer>

    <flux:modal wire:model="showDeleteModal" wire:close="cancelDelete" class="w-full max-w-md border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        @php $pendingDeleteTask = $this->deletingTask; @endphp
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('tasks.delete_this_task') }}</flux:heading>
            <flux:subheading>
                {{ $pendingDeleteTask !== null
                    ? __('tasks.are_you_sure_you_want_to_delete_this_task', ['title' => $pendingDeleteTask->title])
                    : __('tasks.its_attachment_records_are_removed_with_it_nothing_outside') }}
            </flux:subheading>
            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="cancelDelete">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="deleteTask" data-test="confirm-delete-task">{{ __('tasks.delete_task') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
