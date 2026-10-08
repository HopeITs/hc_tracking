<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Notifications\UnreadSubjects;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

/**
 * What the tasks page reads to draw its views: the paginated list, the
 * capped board/calendar set, the stat-tile aggregate, the "My Tasks" rail
 * and the calendar grid.
 *
 * Used by tasks/index only. filteredTasksQuery() and applySort() come from
 * FiltersTaskDirectory; activeMonth() and periodDays() from
 * NavigatesCalendarPeriods.
 *
 * @property string $viewMode
 * @property-read EloquentCollection<int, Task> $boardTasks  #[Computed] boardTasks() below
 */
trait ReadsTaskViews
{
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
}
