<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Notifications\UnreadSubjects;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

/**
 * What the tasks page reads to draw its views: the paginated list, the
 * capped board/calendar/cards set, the stat-tile aggregate and the calendar
 * grid.
 *
 * Used by tasks/index only. filteredTasksQuery() and applySort() come from
 * FiltersTaskDirectory; activeMonth() and periodDays() from
 * NavigatesCalendarPeriods.
 *
 * @property string $viewMode
 * @property string $dueFilter
 * @property ?string $listLayout
 * @property string $group
 * @property-read EloquentCollection<int, Task> $boardTasks  #[Computed] boardTasks() below
 */
trait ReadsTaskViews
{
    /** Cards a board column draws before "Show more": the board stays light however long a column is. */
    public const int BoardCardsPerStep = 25;

    /**
     * How many cards each board column draws, keyed by status value; a
     * column missing here draws BoardCardsPerStep.
     *
     * @var array<string, int>
     */
    public array $boardShown = [];

    public function boardCardLimit(TaskStatus $status): int
    {
        return max(self::BoardCardsPerStep, (int) ($this->boardShown[$status->value] ?? 0));
    }

    /** Draw the next step of one column's cards (scrolling to its end, or its "Show more"). */
    public function showMoreBoard(string $status): void
    {
        $case = TaskStatus::tryFrom($status);

        if ($case !== null) {
            $this->boardShown[$case->value] = $this->boardCardLimit($case) + self::BoardCardsPerStep;
        }
    }

    /** How many undated tasks the calendar's Unscheduled panel lists at most. */
    protected const int UNSCHEDULED_LIMIT = 200;

    public function setListLayout(string $layout): void
    {
        $this->listLayout = in_array($layout, ['wide', 'narrow'], true) ? $layout : null;
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
        return $this->applySort($this->applyGroupOrder($this->filteredTasksQuery()))
            ->orderBy('id')
            ->paginate(20);
    }

    /**
     * Every list group's full size across all pages, keyed exactly as the
     * list view keys its groups — so a group header counts the whole group,
     * not only the rows that happen to fall on the current page.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function taskGroupCounts(): array
    {
        return match ($this->group) {
            'status' => $this->countFilteredTasksBy('status'),
            'priority' => $this->countFilteredTasksBy('priority'),
            'milestone' => $this->countFilteredTasksBy("coalesce(case when source_type = 'milestone' then cast(source_id as char) end, '')"),
            'assignee' => $this->assigneeGroupKeys($this->filteredTasksQuery())->countBy()->all(),
            default => [],
        };
    }

    /**
     * Filtered task counts per value of one fixed SQL expression.
     *
     * @param  literal-string  $expression
     * @return array<string, int>
     */
    protected function countFilteredTasksBy(string $expression): array
    {
        return $this->filteredTasksQuery()->toBase()
            ->select(DB::raw($expression.' as group_key, count(*) as aggregate'))
            ->groupBy(DB::raw($expression))
            ->pluck('aggregate', 'group_key')
            ->mapWithKeys(fn ($count, $key): array => [(string) $key => (int) $count])
            ->all();
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
     * The figures are counted without the Due filter (see
     * filteredTasksQuery()), so they stay steady while one is pressed;
     * `total` alone honours it, since it is the count of what is listed.
     *
     * @return array{total: int, all: int, done: int, overdue: int, missed: int, due_soon: int}
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

        $row = $this->filteredTasksQuery(applyDueFilter: false)->toBase()->select(DB::raw(
            "count(*) as total,
            sum(case when status = 'done' then 1 else 0 end) as done,
            sum(case when status not in ('todo', 'done') and due_date is not null and due_date < '{$today}' then 1 else 0 end) as overdue,
            sum(case when status = 'todo' and due_date is not null and due_date < '{$today}' then 1 else 0 end) as missed,
            sum(case when status <> 'done' and due_date is not null and due_date between '{$today}' and '{$dueSoonEnd}' then 1 else 0 end) as due_soon",
        ))->first();

        $all = (int) ($row->total ?? 0);

        return [
            'total' => $this->dueFilter === '' ? $all : $this->filteredTasksQuery()->count(),
            'all' => $all,
            'done' => (int) ($row->done ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'missed' => (int) ($row->missed ?? 0),
            'due_soon' => (int) ($row->due_soon ?? 0),
        ];
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
     * @return array{month: CarbonImmutable, weeks: ?array<int, array<int, CarbonImmutable>>, days: ?array<int, CarbonImmutable>, tasksByDay: Collection<string, EloquentCollection<int, Task>>, unscheduled: EloquentCollection<int, Task>, unscheduledTotal: int}
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

        // Queried for the visible range itself rather than sliced out of
        // boardTasks(): that set is capped at 500 rows ordered by due date, so
        // on a large backlog the later months (and every undated task) fell
        // off the end and the calendar silently showed empty days.
        $tasksByDay = $this->filteredTasksQuery()
            ->whereBetween('due_date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->orderBy('due_date')
            ->orderByRaw("status = 'done'")
            ->orderByRaw("case priority when 'high' then 0 when 'medium' then 1 else 2 end")
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Task $task): string => $task->due_date->toDateString());

        $unscheduledQuery = $this->filteredTasksQuery()->whereNull('due_date');
        $unscheduledTotal = (clone $unscheduledQuery)->count();
        $unscheduled = $unscheduledQuery
            ->orderByRaw("status = 'done'")
            ->orderByDesc('id')
            ->limit(self::UNSCHEDULED_LIMIT)
            ->get();

        return [
            'month' => $month,
            'weeks' => $weeks,
            'days' => $days,
            'tasksByDay' => $tasksByDay,
            'unscheduled' => $unscheduled,
            'unscheduledTotal' => $unscheduledTotal,
        ];
    }
}
