<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\ManagesTaskVisibility;
use App\Models\HrDepartment;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Notifications\UnreadSubjects;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.tasks')] class extends Component
{
    use ManagesTaskVisibility, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $priority = '';

    #[Url(except: '')]
    public string $assignee = '';

    /**
     * Filters tasks down to one source type, and (once a type is chosen)
     * one specific record of that type — the filter bar's own pair,
     * independent of the drawer's taskSourceType/taskSourceId below.
     */
    #[Url(except: '')]
    public string $sourceType = '';

    #[Url(except: '')]
    public string $sourceId = '';

    #[Url(as: 'view', except: 'board')]
    public string $view = 'board';

    /**
     * Only meaningful for a bypass role (see ManagesTaskVisibility) — a
     * department-scoped role never sees this filter at all, since their
     * scope is fixed to their own department.
     */
    #[Url(except: '')]
    public string $departmentId = '';

    /**
     * Assigned to, or created by, the viewer — always available regardless
     * of department scope, since it's inherently already about "me".
     */
    #[Url(except: false)]
    public bool $myTasksOnly = false;

    /**
     * Set by clicking the "Overdue" or "Due soon" stat tile — a quick filter
     * alongside status/priority/assignee rather than a fifth dropdown.
     */
    #[Url(except: '')]
    public string $dueFilter = '';

    #[Url(as: 'sort', except: 'due')]
    public string $sortField = 'due';

    #[Url(as: 'dir', except: 'asc')]
    public string $sortDirection = 'asc';

    #[Url(except: 'none')]
    public string $group = 'none';

    #[Url(except: '')]
    public string $month = '';

    #[Url(except: '')]
    public string $anchorDate = '';

    #[Url(as: 'period', except: 'month')]
    public string $viewMode = 'month';

    public bool $showTaskDrawer = false;

    public ?int $editingTaskId = null;

    public ?int $editingTaskCreatedBy = null;

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

    public string $taskSourceType = '';

    public string $taskSourceId = '';

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

    public function updatedDepartmentId(): void
    {
        $this->resetPage();
    }

    public function updatedMyTasksOnly(): void
    {
        $this->resetPage();
    }

    public function toggleMyTasksOnly(): void
    {
        $this->myTasksOnly = ! $this->myTasksOnly;
        $this->resetPage();
    }

    /**
     * Toggles a stat tile's own quick filter off when clicked again, exactly
     * like setSort() toggles direction — a second click on the same tile
     * clears it rather than being a no-op.
     */
    public function toggleDueFilter(string $filter): void
    {
        $this->dueFilter = $this->dueFilter === $filter ? '' : $filter;
        $this->resetPage();
    }

    public function toggleStatusFilter(string $status): void
    {
        $this->status = $this->status === $status ? '' : $status;
        $this->resetPage();
    }

    public function updatedSourceType(): void
    {
        $this->sourceId = '';
        $this->resetPage();
    }

    public function updatedSourceId(): void
    {
        $this->resetPage();
    }

    /**
     * Changing the drawer's source type invalidates whichever record was
     * picked under the old type, so the id select always starts empty
     * rather than silently keeping a now-meaningless numeric value.
     */
    public function updatedTaskSourceType(): void
    {
        $this->taskSourceId = '';
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
        $this->sourceType = '';
        $this->sourceId = '';
        $this->departmentId = '';
        $this->myTasksOnly = false;
        $this->dueFilter = '';
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
        $this->anchorDate = '';
    }

    /**
     * Switch between the month grid and the ClickUp-style day/4-days/week
     * time-grid views, mirroring the "My Calendar" page. Only the four
     * values below are valid view modes; any other value (e.g. a stale
     * bookmarked URL) is silently ignored so the component falls back to
     * whatever $viewMode already holds.
     */
    public function setViewMode(string $viewMode): void
    {
        if (! in_array($viewMode, ['day', 'four_days', 'week', 'month'], true)) {
            return;
        }

        $this->viewMode = $viewMode;
    }

    public function previousPeriod(): void
    {
        $this->anchorDate = $this->activeAnchor()->subDays($this->periodLengthInDays())->format('Y-m-d');
    }

    public function nextPeriod(): void
    {
        $this->anchorDate = $this->activeAnchor()->addDays($this->periodLengthInDays())->format('Y-m-d');
    }

    protected function periodLengthInDays(): int
    {
        return match ($this->viewMode) {
            'day' => 1,
            'four_days' => 4,
            'week' => 7,
            default => 1,
        };
    }

    protected function activeAnchor(): CarbonImmutable
    {
        if ($this->anchorDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->anchorDate) === 1) {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $this->anchorDate);

            if ($parsed !== false) {
                return $parsed->startOfDay();
            }
        }

        return CarbonImmutable::now()->startOfDay();
    }

    /**
     * The visible days for the day/4-days/week views. Week always starts on
     * the anchor's Sunday, so navigating never lands mid-week.
     *
     * @return array<int, CarbonImmutable>
     */
    protected function periodDays(): array
    {
        $anchor = $this->viewMode === 'week'
            ? $this->activeAnchor()->startOfWeek(CarbonImmutable::SUNDAY)
            : $this->activeAnchor();

        return array_map(
            fn (int $i): CarbonImmutable => $anchor->addDays($i),
            range(0, $this->periodLengthInDays() - 1),
        );
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
            ->select(['id', 'slug', 'parent_task_id', 'source_type', 'source_id', 'title', 'description', 'status', 'priority', 'start_date', 'due_date', 'completed_date', 'progress_percentage', 'created_by'])
            // Subtasks are shown nested under their parent (each parent's own
            // `subtasks` eager load below), never as their own top-level row —
            // so the list/board/calendar and the stat tiles all count and
            // display only top-level tasks.
            ->whereNull('parent_task_id')
            ->withCount('subtasks')
            ->with([
                'source',
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
            ->when($this->sourceType !== '', fn (Builder $query) => $query->where('source_type', $this->sourceType))
            ->when($this->sourceType !== '' && $this->sourceId !== '', fn (Builder $query) => $query->where('source_id', (int) $this->sourceId))
            ->when($this->dueFilter === 'overdue', fn (Builder $query) => $query
                ->whereNotIn('status', [TaskStatus::Todo->value, TaskStatus::Done->value])
                ->whereNotNull('due_date')
                ->where('due_date', '<', CarbonImmutable::today()))
            ->when($this->dueFilter === 'missed', fn (Builder $query) => $query
                ->where('status', TaskStatus::Todo->value)
                ->whereNotNull('due_date')
                ->where('due_date', '<', CarbonImmutable::today()))
            ->when($this->dueFilter === 'due_soon', fn (Builder $query) => $query
                ->where('status', '!=', TaskStatus::Done->value)
                ->whereNotNull('due_date')
                ->whereBetween('due_date', [CarbonImmutable::today(), CarbonImmutable::today()->addDays(7)]))
            ->when(
                $this->departmentId !== '' && auth()->user()->canViewAllTaskDepartments(),
                fn (Builder $query) => $this->scopeTasksToDepartment($query, (int) $this->departmentId),
            )
            ->when($this->myTasksOnly, function (Builder $query): void {
                $user = auth()->user();
                $employeeId = $user->employee_id;

                $query->where(fn (Builder $query) => $query
                    ->when($employeeId !== null, fn (Builder $query) => $query->orWhereHas(
                        'taskAssignees',
                        fn (Builder $query) => $query->where('employee_id', $employeeId),
                    ))
                    ->orWhere('created_by', $user->id));
            })
            ->tap(fn (Builder $query) => $this->applyViewerDepartmentScope($query));
    }

    /**
     * Records with unread notifications for me, for the row dots.
     *
     * @return list<int>
     */
    #[Computed]
    public function unreadIds(): array
    {
        return app(UnreadSubjects::class)->ids(auth()->user(), 'task');
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
     * @return array{total: int, done: int, overdue: int, missed: int, due_soon: int}
     */
    #[Computed]
    public function taskStats(): array
    {
        // Dates are server-generated (never user input), so it's safe to
        // inline them literally instead of passing bindings through a raw
        // select expression — Eloquent's select() replaces the column list
        // rather than appending to it, which selectRaw()/addSelect() would.
        // Mirrors Task::isOverdue()/isMissed(): overdue is a task actively
        // being worked (not todo, not done) past its due date, missed is a
        // task still untouched in todo past its due date.
        $today = CarbonImmutable::today()->toDateString();
        $dueSoonEnd = CarbonImmutable::today()->addDays(7)->toDateString();

        $row = $this->filteredTasksQuery()->toBase()->select(DB::raw(
            "count(*) as total,
            sum(case when status = 'done' then 1 else 0 end) as done,
            sum(case when status not in ('todo', 'done') and due_date is not null and due_date < '{$today}' then 1 else 0 end) as overdue,
            sum(case when status = 'todo' and due_date is not null and due_date < '{$today}' then 1 else 0 end) as missed,
            sum(case when status <> 'done' and due_date is not null and due_date between '{$today}' and '{$dueSoonEnd}' then 1 else 0 end) as due_soon",
        ))->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'done' => (int) ($row->done ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'missed' => (int) ($row->missed ?? 0),
            'due_soon' => (int) ($row->due_soon ?? 0),
        ];
    }

    /**
     * A capped preview of the viewer's own tasks (assigned to or created by
     * them) for the "My Tasks" rail — independent of every other filter
     * (status, priority, department, …) so it always reflects "what's mine"
     * regardless of what the rest of the page is currently narrowed to.
     * Bypasses the base filteredTasksQuery() entirely rather than layering
     * myTasksOnly on top of it, for exactly that reason.
     *
     * @return EloquentCollection<int, Task>
     */
    #[Computed]
    public function myTasksPreview(): EloquentCollection
    {
        $user = auth()->user();
        $employeeId = $user->employee_id;

        return Task::query()
            ->select(['id', 'title', 'status', 'priority', 'due_date', 'created_by'])
            ->whereNull('parent_task_id')
            ->where(fn (Builder $query) => $query
                ->when($employeeId !== null, fn (Builder $query) => $query->orWhereHas(
                    'taskAssignees',
                    fn (Builder $query) => $query->where('employee_id', $employeeId),
                ))
                ->orWhere('created_by', $user->id))
            ->where('status', '!=', TaskStatus::Done->value)
            ->with('taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
            ->orderByRaw('due_date is null, due_date asc')
            ->limit(8)
            ->get();
    }

    /**
     * The calendar grid for the calendar view, built from the same filtered
     * tasks as the other views. Tasks without a due date are returned
     * separately for the "unscheduled" panel. In month view, full weeks
     * covering the active month (padded with the tail of adjacent months).
     * In day/4-days/week view, the plain list of visible days instead —
     * `weeks` is null then and `days` is populated, and vice versa in month
     * view.
     *
     * @return array{month: CarbonImmutable, weeks: ?array<int, array<int, CarbonImmutable>>, days: ?array<int, CarbonImmutable>, tasksByDay: Collection<string, EloquentCollection<int, Task>>, unscheduled: EloquentCollection<int, Task>}
     */
    #[Computed]
    public function calendarGrid(): array
    {
        if ($this->viewMode === 'month') {
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

            $days = null;
        } else {
            $days = $this->periodDays();
            $month = $days[0];
            $gridStart = $days[0];
            $gridEnd = end($days);
            $weeks = null;
        }

        $tasks = $this->boardTasks;

        $tasksByDay = $tasks
            ->filter(fn (Task $task): bool => $task->due_date !== null && $task->due_date->between($gridStart, $gridEnd))
            ->groupBy(fn (Task $task): string => $task->due_date->toDateString());

        $unscheduled = $tasks->filter(fn (Task $task): bool => $task->due_date === null)->values();

        return [
            'month' => $month,
            'weeks' => $weeks,
            'days' => $days,
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
    /**
     * The department filter's options — only ever queried/rendered for a
     * bypass role (see ManagesTaskVisibility); a department-scoped role's
     * view never needs this list.
     *
     * @return array<int, array{id: int, name: string}>
     */
    #[Computed(persist: true, seconds: 300)]
    public function departmentOptions(): array
    {
        if (! auth()->user()->canViewAllTaskDepartments()) {
            return [];
        }

        return HrDepartment::options()
            ->map(fn (HrDepartment $department): array => ['id' => $department->id, 'name' => $department->name])
            ->all();
    }

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

    /**
     * The five source types a task can be raised against — static, so it
     * needs no query. Mirrors the morph keys registered in
     * AppServiceProvider::configureMorphMap() for the `tasks.source_type`
     * column.
     *
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function sourceTypeOptions(): array
    {
        return [
            ['value' => 'milestone', 'label' => __('tasks.source_type_milestone')],
            ['value' => 'proposal', 'label' => __('tasks.source_type_proposal')],
            ['value' => 'project', 'label' => __('tasks.source_type_project')],
            ['value' => 'program', 'label' => __('tasks.source_type_program')],
            ['value' => 'department', 'label' => __('tasks.source_type_department')],
        ];
    }

    /**
     * The records the drawer's own Source select offers, for whichever
     * source type is currently chosen there. Deliberately NOT
     * `persist`-cached like userOptions() above: this depends on
     * $this->taskSourceType, so a per-instance cache keyed only by method
     * name would go stale (or serve the wrong type's records) the moment
     * the type select changes. It costs one cheap query either way.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    #[Computed]
    public function sourceOptions(): Collection
    {
        return $this->optionsForSourceType($this->taskSourceType);
    }

    /**
     * Same shape as sourceOptions(), but scoped by the filter bar's own
     * source type instead of the drawer's — a separate computed method so
     * switching one never invalidates the other's Livewire request-scoped
     * cache.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    #[Computed]
    public function filterSourceOptions(): Collection
    {
        return $this->optionsForSourceType($this->sourceType);
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    protected function optionsForSourceType(string $type): Collection
    {
        return match ($type) {
            'milestone' => Milestone::query()
                ->select(['id', 'name', 'project_id'])
                ->with('project:id,name')
                ->orderBy('name')
                ->orderBy('id')
                ->get()
                ->map(fn (Milestone $milestone): array => [
                    'id' => $milestone->id,
                    'name' => $milestone->name.' · '.$milestone->project->name,
                ]),
            'proposal' => Proposal::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get()
                ->map(fn (Proposal $proposal): array => ['id' => $proposal->id, 'name' => $proposal->name]),
            'project' => Project::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get()
                ->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name]),
            'program' => Program::options()
                ->map(fn (Program $program): array => ['id' => $program->id, 'name' => $program->name]),
            'department' => HrDepartment::query()->select(['id', 'name'])->orderBy('name')->get()
                ->map(fn (HrDepartment $department): array => ['id' => $department->id, 'name' => $department->name]),
            default => collect(),
        };
    }

    /**
     * The Eloquent class backing one of the five task source types, or null
     * for an unrecognized type — used both to validate a submitted
     * taskSourceId and to build each type's own option list.
     *
     * @return class-string<Model>|null
     */
    protected function sourceModelClassFor(string $type): ?string
    {
        return match ($type) {
            'milestone' => Milestone::class,
            'proposal' => Proposal::class,
            'project' => Project::class,
            'program' => Program::class,
            'department' => HrDepartment::class,
            default => null,
        };
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
            $this->taskSourceType = 'milestone';
            $this->taskSourceId = $milestoneId;
        }

        $this->showTaskDrawer = true;
    }

    /**
     * A group's inline "+ Add task" row only captures a title, but a task
     * always needs a source and an assignee. Both are inferred from context
     * so the row can create the task directly, with no drawer:
     *  - source: the row's own milestone (its own milestoneId, when the row
     *    is grouped or scheduled under one), falling back to the active
     *    source-type/source-id filter — no milestone context and no active
     *    single-record filter is genuinely ambiguous.
     *  - assignee: whichever employees the row's own draft (see taskDraft())
     *    has picked, falling back to the signed-in user's own HR employee
     *    record when the row hasn't been touched.
     * Due date, priority and status also come from that same draft, same as
     * addSubtaskQuick()'s own draft. When the source or every assignee can't
     * be resolved, this falls back to the drawer exactly as before,
     * pre-filled with the title and whatever the row's own context and
     * draft already had, so nothing is ever silently dropped.
     */
    public function quickCreateTask(string $title, ?string $status = null, ?string $milestoneId = null, ?string $rowKey = null, ?string $dueDate = null): void
    {
        $title = trim($title);

        if ($title === '') {
            return;
        }

        $draft = $rowKey !== null ? $this->taskDraft($rowKey, $status) : null;

        $source = $this->resolveInlineSource($milestoneId);
        $employeeIds = $draft !== null && $draft['assignees'] !== []
            ? HrEmployee::query()->whereIn('id', collect($draft['assignees'])->map(fn (string $id): int => (int) $id))->pluck('id')
            : collect(array_filter([$this->resolveInlineAssigneeId()]));

        if ($source === null || $employeeIds->isEmpty()) {
            $this->openCreateDrawer(dueDate: $dueDate, status: $draft !== null ? $draft['status'] : $status, milestoneId: $milestoneId);
            $this->title = $title;

            if ($draft !== null) {
                $this->taskPriority = $draft['priority'];
                $this->assignedTo = $draft['assignees'];

                if ($dueDate === null && $draft['due_date'] !== '') {
                    $this->dueDate = $draft['due_date'];
                }
            }

            return;
        }

        $statusCase = TaskStatus::tryFrom(($draft !== null ? $draft['status'] : $status) ?? '') ?? TaskStatus::Todo;
        $priorityCase = $draft !== null ? (TaskPriority::tryFrom($draft['priority']) ?? TaskPriority::Medium) : TaskPriority::Medium;

        // A calendar day's row passes its own day as $dueDate, which always
        // wins — that row has no due-date field of its own precisely because
        // the cell it's in already fixes the date. Every other row's due
        // date, if any, comes from its own draft.
        $resolvedDueDate = $dueDate ?? ($draft !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $draft['due_date']) === 1 ? $draft['due_date'] : null);

        DB::transaction(function () use ($title, $statusCase, $priorityCase, $resolvedDueDate, $source, $employeeIds): void {
            $task = Task::query()->create([
                'title' => $title,
                'status' => $statusCase,
                'priority' => $priorityCase,
                'due_date' => $resolvedDueDate,
                'progress_percentage' => $statusCase === TaskStatus::Done ? 100 : 0,
                'completed_date' => $statusCase === TaskStatus::Done ? CarbonImmutable::today() : null,
                'source_type' => $source['type'],
                'source_id' => $source['id'],
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
     * Resolves the source a group's inline "+ Add task" row can safely
     * default to, or null when that would be a guess: its own milestone
     * context when given (the row is grouped or scheduled under one),
     * otherwise the active filter bar's source type/id pair when it names
     * a single record.
     *
     * @return array{type: string, id: int}|null
     */
    protected function resolveInlineSource(?string $milestoneId): ?array
    {
        if ($milestoneId !== null) {
            return Milestone::query()->whereKey((int) $milestoneId)->exists()
                ? ['type' => 'milestone', 'id' => (int) $milestoneId]
                : null;
        }

        if ($this->sourceType !== '' && $this->sourceId !== '') {
            return ['type' => $this->sourceType, 'id' => (int) $this->sourceId];
        }

        return null;
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
                'source_type' => $parent->source_type,
                'source_id' => $parent->source_id,
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
            ->select(['id', 'parent_task_id', 'source_type', 'source_id', 'title', 'description', 'status', 'priority', 'start_date', 'due_date', 'completed_date', 'progress_percentage', 'created_by'])
            ->with('taskAssignees:id,task_id,employee_id')
            ->findOrFail($taskId);

        $this->resetValidation();
        $this->editingTaskId = $task->id;
        $this->editingTaskCreatedBy = $task->created_by;
        $this->title = $task->title;
        $this->description = $task->description ?? '';
        $this->taskStatus = $task->status->value;
        $this->taskPriority = $task->priority->value;
        $this->startDate = $task->start_date?->toDateString() ?? '';
        $this->dueDate = $task->due_date?->toDateString() ?? '';
        $this->completedDate = $task->completed_date?->toDateString() ?? '';
        $this->progressPercentage = (string) $task->progress_percentage;
        $this->assignedTo = $task->taskAssignees->pluck('employee_id')->map(fn (int $id): string => (string) $id)->all();
        $this->taskSourceType = $task->source_type;
        $this->taskSourceId = (string) $task->source_id;
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
            'taskSourceType' => ['required', Rule::in(['milestone', 'proposal', 'project', 'program', 'department'])],
            'taskSourceId' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $modelClass = $this->sourceModelClassFor($this->taskSourceType);

                    if ($modelClass !== null && ! $modelClass::query()->whereKey((int) $value)->exists()) {
                        $fail(__('tasks.the_selected_source_is_no_longer_available'));
                    }
                },
            ],
        ], [
            'title.required' => __('tasks.enter_a_task_title'),
            'assignedTo.required' => __('tasks.assign_this_task_to_someone'),
            'assignedTo.min' => __('tasks.assign_this_task_to_someone'),
            'assignedTo.*.exists' => __('tasks.the_selected_assignee_is_no_longer_available'),
            'taskSourceType.required' => __('tasks.select_the_source_this_task_is_raised_against'),
            'taskSourceId.required' => __('tasks.select_the_source_this_task_is_raised_against'),
            'dueDate.after_or_equal' => __('tasks.the_due_date_cannot_fall_before_the_start_date'),
            'progressPercentage.min' => __('tasks.progress_cannot_be_negative'),
            'progressPercentage.max' => __('tasks.progress_cannot_exceed_100_percent'),
        ]);

        $isCreate = $this->editingTaskId === null;
        $task = $isCreate ? new Task : Task::query()->findOrFail($this->editingTaskId);

        if (! $isCreate && $task->status === TaskStatus::Done) {
            Flux::toast(variant: 'danger', text: __('tasks.completed_tasks_cannot_change_status'));

            return;
        }

        $dueDate = $validated['dueDate'] === '' ? null : $validated['dueDate'];

        if (! $isCreate && $task->created_by !== auth()->id() && $dueDate !== $task->due_date?->toDateString()) {
            Flux::toast(variant: 'danger', text: __('tasks.only_the_task_creator_can_change_the_due_date'));

            $dueDate = $task->due_date?->toDateString();
        }

        $attributes = [
            'title' => $validated['title'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
            'status' => $validated['taskStatus'],
            'priority' => $validated['taskPriority'],
            'start_date' => $validated['startDate'] === '' ? null : $validated['startDate'],
            'due_date' => $dueDate,
            'completed_date' => $validated['completedDate'] === '' ? null : $validated['completedDate'],
            'progress_percentage' => (int) $validated['progressPercentage'],
            'source_type' => $validated['taskSourceType'],
            'source_id' => (int) $validated['taskSourceId'],
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
            Flux::toast(variant: 'danger', text: __('tasks.completed_tasks_cannot_change_status'));

            return;
        }

        $task->status = TaskStatus::Done;
        $task->completed_date = CarbonImmutable::today();
        $task->progress_percentage = 100;

        $task->save();

        Flux::toast(variant: 'success', text: __('tasks.task_marked_done'));
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

        if ($task->status === TaskStatus::Done) {
            Flux::toast(variant: 'danger', text: __('tasks.completed_tasks_cannot_change_status'));

            return;
        }

        $task->status = $newStatus;

        if ($newStatus === TaskStatus::InProgress) {
            $task->start_date ??= CarbonImmutable::today();
        }

        if ($newStatus === TaskStatus::Done) {
            $task->completed_date ??= CarbonImmutable::today();
            $task->progress_percentage = 100;
        } elseif ($task->completed_date !== null) {
            $task->completed_date = null;
        }

        $task->save();

        Flux::toast(variant: 'success', text: __('tasks.task_status_changed', ['status' => $newStatus->label()]));
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

        Flux::toast(variant: 'success', text: __('tasks.task_priority_changed', ['priority' => $newPriority->label()]));
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

            Flux::toast(variant: 'success', text: __('tasks.assignee_removed'));

            return;
        }

        $task->taskAssignees()->create(['employee_id' => $employeeId]);

        Flux::toast(variant: 'success', text: __('tasks.assignee_added'));
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

        if ($task->created_by !== auth()->id()) {
            Flux::toast(variant: 'danger', text: __('tasks.only_the_task_creator_can_change_the_due_date'));

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
        $this->editingTaskCreatedBy = null;
        $this->title = '';
        $this->description = '';
        $this->taskStatus = TaskStatus::Todo->value;
        $this->taskPriority = TaskPriority::Medium->value;
        $this->startDate = '';
        $this->dueDate = '';
        $this->completedDate = '';
        $this->progressPercentage = '0';
        $this->assignedTo = [];
        $this->taskSourceType = '';
        $this->taskSourceId = '';
    }

    protected function trimTaskForm(): void
    {
        foreach (['title', 'description', 'taskStatus', 'taskPriority', 'startDate', 'dueDate', 'completedDate', 'progressPercentage', 'taskSourceType', 'taskSourceId'] as $property) {
            $this->{$property} = trim($this->{$property});
        }
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $status !== '' || $priority !== '' || $assignee !== '' || $sourceType !== '' || $departmentId !== '' || $dueFilter !== '' || $myTasksOnly;
    $canPickDepartment = auth()->user()->canViewAllTaskDepartments();
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
            @if ($canPickDepartment)
                <x-ui.header-filter-select :label="__('tasks.department')" wire:model.live="departmentId" :value="$departmentId" data-test="task-department-filter">
                    <flux:select.option value="">{{ __('tasks.every_department') }}</flux:select.option>
                    @foreach ($this->departmentOptions as $departmentOption)
                        <flux:select.option :value="$departmentOption['id']" :wire:key="'task-department-filter-'.$departmentOption['id']">{{ $departmentOption['name'] }}</flux:select.option>
                    @endforeach
                </x-ui.header-filter-select>
            @endif
            <x-ui.header-filter-select :label="__('tasks.source_type')" wire:model.live="sourceType" :value="$sourceType">
                <flux:select.option value="">{{ __('common.select') }}</flux:select.option>
                @foreach ($this->sourceTypeOptions as $sourceTypeOption)
                    <flux:select.option :value="$sourceTypeOption['value']" :wire:key="'task-source-type-filter-'.$sourceTypeOption['value']">{{ $sourceTypeOption['label'] }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
            @if ($sourceType !== '')
                <x-ui.header-filter-select :label="__('tasks.source')" wire:model.live="sourceId" :value="$sourceId">
                    <flux:select.option value="">{{ __('common.select') }}</flux:select.option>
                    @foreach ($this->filterSourceOptions as $sourceOption)
                        <flux:select.option :value="$sourceOption['id']" :wire:key="'task-source-filter-'.$sourceOption['id']">{{ $sourceOption['name'] }}</flux:select.option>
                    @endforeach
                </x-ui.header-filter-select>
            @endif
        </x-slot:filters>
        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateDrawer" data-test="create-task-button">{{ __('tasks.add_task') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-3 @2xl/directory:grid-cols-2 @4xl/directory:grid-cols-3 @6xl/directory:grid-cols-5">
        <button type="button" wire:click="clearFilters" data-test="task-stat-total" @class(['text-left transition-transform', 'scale-[0.98]' => ! $filtersAreActive])>
            <x-ui.stat-tile :label="__('tasks.total_tasks')" :value="$this->taskStats['total']" icon="clipboard-document-check" />
        </button>
        <button type="button" wire:click="toggleStatusFilter('{{ TaskStatus::Done->value }}')" data-test="task-stat-done" @class(['text-left transition-transform', 'scale-[0.98] ring-2 ring-status-good rounded-xl' => $status === TaskStatus::Done->value])>
            <x-ui.stat-tile :label="TaskStatus::Done->label()" :value="$this->taskStats['done']" icon="check-circle" tone="good" />
        </button>
        <button type="button" wire:click="toggleDueFilter('overdue')" data-test="task-stat-overdue" @class(['text-left transition-transform', 'scale-[0.98] ring-2 ring-status-critical rounded-xl' => $dueFilter === 'overdue'])>
            <x-ui.stat-tile :label="__('tasks.overdue')" :value="$this->taskStats['overdue']" icon="exclamation-triangle" tone="critical" />
        </button>
        <button type="button" wire:click="toggleDueFilter('missed')" data-test="task-stat-missed" @class(['text-left transition-transform', 'scale-[0.98] ring-2 ring-status-critical rounded-xl' => $dueFilter === 'missed'])>
            <x-ui.stat-tile :label="__('tasks.missed')" :value="$this->taskStats['missed']" icon="exclamation-circle" tone="critical" />
        </button>
        <button type="button" wire:click="toggleDueFilter('due_soon')" data-test="task-stat-due-soon" @class(['text-left transition-transform', 'scale-[0.98] ring-2 ring-status-warn rounded-xl' => $dueFilter === 'due_soon'])>
            <x-ui.stat-tile :label="__('tasks.due_within_7_days')" :value="$this->taskStats['due_soon']" icon="clock" tone="warn" />
        </button>
    </div>

    <div class="flex flex-col gap-3 rounded-2xl border border-secondary-soft bg-secondary-soft/20 p-4 dark:border-secondary-medium dark:bg-secondary-medium/10" data-test="my-tasks-rail">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <flux:icon name="user-circle" variant="micro" class="size-5 text-primary" aria-hidden="true" />
                <h2 class="text-sm font-bold text-secondary-dark dark:text-white">{{ __('tasks.my_tasks') }}</h2>
            </div>
            @if ($this->myTasksPreview->isNotEmpty())
                <button type="button" wire:click="toggleMyTasksOnly" class="text-xs font-semibold text-primary hover:underline" data-test="my-tasks-view-all">
                    {{ $myTasksOnly ? __('tasks.showing_my_tasks_only') : __('tasks.view_all') }}
                </button>
            @endif
        </div>
        @if ($this->myTasksPreview->isNotEmpty())
            @php $viewerEmployeeId = auth()->user()->employee_id; @endphp
            <div class="flex gap-3 overflow-x-auto pb-1">
                @foreach ($this->myTasksPreview as $myTask)
                    @php $isAssignedToMe = $viewerEmployeeId !== null && $myTask->taskAssignees->pluck('employee_id')->contains($viewerEmployeeId); @endphp
                    <div
                        role="button"
                        tabindex="0"
                        wire:click="openEditDrawer({{ $myTask->id }})"
                        wire:key="my-task-{{ $myTask->id }}"
                        data-test="my-task-card-{{ $myTask->id }}"
                        x-on:keydown="if (! $event.target.closest('a') && ($event.key === 'Enter' || $event.key === ' ')) { $event.preventDefault(); $wire.openEditDrawer({{ $myTask->id }}) }"
                        @class([
                            'flex w-64 shrink-0 flex-col gap-2 rounded-xl border p-3 text-left shadow-sm transition-shadow hover:shadow-md dark:bg-secondary-dark',
                            'border-primary/30 bg-white dark:border-status-critical/30' => $isAssignedToMe,
                            'border-dashed border-secondary-light bg-secondary-soft/30 dark:border-secondary-medium dark:bg-secondary-medium/10' => ! $isAssignedToMe,
                        ])
                    >
                        <div class="flex items-center justify-between gap-2">
                            <x-ui.status-pill :case="$myTask->status" />
                            @if ($isAssignedToMe)
                                <span class="inline-flex shrink-0 items-center gap-1 text-[10px] font-bold text-primary dark:text-status-critical" data-test="my-task-role-assigned-{{ $myTask->id }}">
                                    <flux:icon.user variant="micro" class="size-3" aria-hidden="true" />
                                    {{ __('tasks.assigned_to_you') }}
                                </span>
                            @endif
                        </div>
                        @unless ($isAssignedToMe)
                            @php $assigneeName = $myTask->taskAssignees->first()?->employee?->name; @endphp
                            @if ($assigneeName !== null)
                                <span class="inline-flex items-start gap-1 text-[10px] font-semibold text-secondary-medium dark:text-secondary-soft" data-test="my-task-role-delegated-{{ $myTask->id }}">
                                    <flux:icon.paper-airplane variant="micro" class="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                                    <span class="break-words">{{ __('tasks.assigned_to_name', ['name' => $assigneeName]) }}</span>
                                </span>
                            @endif
                        @endunless
                        <a
                            href="{{ route('tasks.show', $myTask) }}"
                            wire:navigate
                            title="{{ $myTask->title }}"
                            x-on:click.stop
                            class="truncate text-sm font-semibold text-secondary-dark hover:text-primary hover:underline dark:text-white dark:hover:text-status-critical"
                            data-test="my-task-title-{{ $myTask->id }}"
                        >{{ $myTask->title }}</a>
                        <div class="flex items-center justify-between text-xs text-secondary-medium dark:text-secondary-soft">
                            <span @class(['font-semibold text-status-critical' => $myTask->isOverdue() || $myTask->isMissed()])>
                                {{ $myTask->due_date?->translatedFormat('M j') ?? __('tasks.no_due_date') }}
                            </span>
                            <x-ui.enum-badge :case="$myTask->priority" />
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-secondary-medium dark:text-secondary-soft" data-test="my-tasks-empty">{{ __('common.no_tasks_yet') }}</p>
        @endif
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

            <button
                type="button"
                wire:click="toggleMyTasksOnly"
                aria-pressed="{{ $myTasksOnly ? 'true' : 'false' }}"
                data-test="task-filter-my-tasks-only"
                @class([
                    'inline-flex shrink-0 items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-primary',
                    'border-primary bg-primary text-white' => $myTasksOnly,
                    'border-secondary-light text-secondary-medium hover:bg-secondary-soft dark:text-secondary-soft dark:hover:bg-secondary-medium' => ! $myTasksOnly,
                ])
            >
                <flux:icon.user variant="micro" class="size-3.5" />
                {{ __('tasks.my_tasks_only') }}
            </button>
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

    @php
        $isStatusLocked = $editingTaskId !== null && $taskStatus === TaskStatus::Done->value;
        $isDueDatePast = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) === 1
            && \Carbon\CarbonImmutable::parse($dueDate)->lt(\Carbon\CarbonImmutable::today());
        $isMissedDraft = $isDueDatePast && $taskStatus === TaskStatus::Todo->value;
        $isOverdueDraft = $isDueDatePast && ! $isMissedDraft && $taskStatus !== TaskStatus::Done->value;
        $isDueDateLocked = $editingTaskId !== null && $editingTaskCreatedBy !== auth()->id();
    @endphp

    {{-- Same form, sections and fields as the calendar page's task modal; the tasks page adds its own locks (done status, creator-only due date). --}}
    <x-ui.modal
        wire:model="showTaskDrawer"
        size="xl"
        :title="$editingTaskId === null ? __('tasks.add_task') : __('tasks.edit_task')"
        :description="__('tasks.every_task_delivers_against_a_proposal_a_milestone_is_option')"
        submit="saveTask"
        close="closeTaskDrawer"
        required-note
    >
        <x-ui.form-section :title="__('common.section_details')" :fields="['title', 'description']">
            <div class="grid gap-y-3">
                <x-ui.form-input wire:model="title" :label="__('tasks.task_title')" maxlength="255" required autofocus />
                <x-ui.form-textarea wire:model="description" :label="__('common.description')" rows="2" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('common.section_placement')" :fields="['taskSourceType', 'taskSourceId']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-select wire:model.live="taskSourceType" :label="__('tasks.source_type')" :placeholder="__('common.select')" required>
                    @foreach ($this->sourceTypeOptions as $sourceTypeOption)
                        <flux:select.option :value="$sourceTypeOption['value']">{{ $sourceTypeOption['label'] }}</flux:select.option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-select wire:model="taskSourceId" :label="__('tasks.source')" :placeholder="__('common.select')" required>
                    @foreach ($this->sourceOptions as $sourceOption)
                        <flux:select.option :value="$sourceOption['id']">{{ $sourceOption['name'] }}</flux:select.option>
                    @endforeach
                </x-ui.form-select>
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('tasks.section_progress')" :fields="['taskStatus', 'taskPriority', 'progressPercentage', 'assignedTo']">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                <x-ui.form-segmented
                    wire:model="taskStatus"
                    :label="__('common.status')"
                    required
                    class="col-span-2"
                    :disabled="$isStatusLocked"
                    :help="$isStatusLocked ? __('tasks.completed_tasks_cannot_change_status') : null"
                    :options="[
                        ['value' => TaskStatus::Todo->value, 'label' => TaskStatus::Todo->label(), 'dot' => 'border border-secondary-light', 'test' => 'task-drawer-status-'.TaskStatus::Todo->value],
                        ['value' => TaskStatus::InProgress->value, 'label' => TaskStatus::InProgress->label(), 'dot' => 'bg-status-active', 'test' => 'task-drawer-status-'.TaskStatus::InProgress->value],
                        ['value' => TaskStatus::UnderReview->value, 'label' => TaskStatus::UnderReview->label(), 'dot' => 'bg-status-warn', 'test' => 'task-drawer-status-'.TaskStatus::UnderReview->value],
                        ['value' => TaskStatus::Done->value, 'label' => TaskStatus::Done->label(), 'dot' => 'bg-status-good', 'test' => 'task-drawer-status-'.TaskStatus::Done->value],
                    ]"
                />

                <x-ui.form-segmented
                    wire:model="taskPriority"
                    :label="__('tasks.priority')"
                    required
                    :options="[
                        ['value' => TaskPriority::Low->value, 'label' => TaskPriority::Low->label(), 'icon' => 'flag', 'iconClass' => 'text-secondary-light', 'test' => 'task-drawer-priority-'.TaskPriority::Low->value],
                        ['value' => TaskPriority::Medium->value, 'label' => TaskPriority::Medium->label(), 'icon' => 'flag', 'iconClass' => 'text-status-warn', 'test' => 'task-drawer-priority-'.TaskPriority::Medium->value],
                        ['value' => TaskPriority::High->value, 'label' => TaskPriority::High->label(), 'icon' => 'flag', 'iconClass' => 'text-status-critical', 'test' => 'task-drawer-priority-'.TaskPriority::High->value],
                    ]"
                />

                <x-ui.form-input wire:model="progressPercentage" :label="__('tasks.progress_2')" type="number" min="0" max="100" suffix="%" required data-test="task-drawer-progress-input" />

                <x-ui.form-multiselect wire:model="assignedTo" :label="__('tasks.assignees')" :placeholder="__('common.select')" class="col-span-2" data-test="task-assignee-picker">
                    @foreach ($this->userOptions as $userOption)
                        <flux:select.option :value="$userOption['id']">{{ $userOption['name'] }} · {{ $userOption['secondary_name'] }}</flux:select.option>
                    @endforeach
                </x-ui.form-multiselect>
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('common.section_schedule')" :fields="['startDate', 'dueDate', 'completedDate']">
            @if ($isMissedDraft || $isOverdueDraft)
                <x-slot:hint>
                    <span class="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-bold tracking-wide text-status-critical uppercase">{{ $isMissedDraft ? __('tasks.missed') : __('tasks.overdue') }}</span>
                </x-slot:hint>
            @endif

            <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-3">
                <x-ui.form-input wire:model="startDate" :label="__('common.start_date')" type="date" />
                <x-ui.form-input wire:model="dueDate" :label="__('tasks.due_date_2')" type="date" :disabled="$isDueDateLocked" :help="$isDueDateLocked ? __('tasks.only_the_task_creator_can_change_the_due_date') : null" />
                <x-ui.form-input wire:model="completedDate" :label="__('tasks.completed_date')" type="date" />
            </div>
        </x-ui.form-section>

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-task-button">{{ $editingTaskId === null ? __('tasks.create_task') : __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('tasks.delete_this_task')"
        :message="$this->deletingTask !== null
            ? __('tasks.are_you_sure_you_want_to_delete_this_task', ['title' => $this->deletingTask->title])
            : __('tasks.its_attachment_records_are_removed_with_it_nothing_outside')"
        confirm="deleteTask"
        :confirm-label="__('tasks.delete_task')"
        confirm-test="confirm-delete-task"
    />
</section>
