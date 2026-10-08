<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Tasks;

use App\Enums\TaskStatus;
use App\Models\HrEmployee;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Support\Like;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The task directory's filters: the reset hooks, the stat-tile and toggle
 * filters, sorting, and the one filtered query every view reads through
 * (list, board, calendar and the stat tiles), so switching views never
 * changes what is shown.
 *
 * Used by tasks/index only. The page keeps the #[Url] filter properties and
 * uses WithPagination (resetPage) and ManagesTaskVisibility
 * (scopeTasksToDepartment); ReadsTaskViews calls filteredTasksQuery() and
 * applySort() from here.
 *
 * @property string $search
 * @property string $status
 * @property string $priority
 * @property string $assignee
 * @property string $sourceType
 * @property string $sourceId
 * @property string $departmentId
 * @property bool $myTasksOnly
 * @property string $dueFilter
 * @property string $sortField
 * @property string $sortDirection
 */
trait FiltersTaskDirectory
{
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
                'subtasks:id,parent_task_id,title,status,priority,due_date,created_by',
                'subtasks.taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('title', 'like', Like::contains($search))
                        ->orWhere('description', 'like', Like::contains($search))
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
            });
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
}
