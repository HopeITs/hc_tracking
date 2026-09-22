<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\DependencyType;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ScheduleDependency;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The scheduling maths behind the project Gantt, done the MS Project way on a
 * working-day calendar:
 *
 * - links: FS / SS / FF / SF with a lag (or lead, when negative) in working days;
 * - auto-scheduling: a successor that no longer satisfies its links is pushed
 *   later (never pulled earlier) keeping its working-day duration, and the push
 *   ripples down the chain;
 * - critical path: a backward pass from the project finish gives every item its
 *   total float; zero or negative float is critical.
 *
 * Dates are worked as integer day numbers (days since 1970-01-01 UTC), the
 * same unit the chart's JavaScript uses, so both sides agree to the day.
 *
 * Schedule items are milestones and tasks with both dates set. Their row ids
 * ("milestone-4", "task-12") are the keys used everywhere.
 */
trait SchedulesProjectGantt
{
    /** Weekdays each preset takes off; 0 = Sunday … 6 = Saturday. */
    public const WEEKEND_PRESETS = [
        'sat-sun' => [6, 0],
        'fri-sat' => [5, 6],
        'fri' => [5],
        'sun' => [0],
        'none' => [],
    ];

    /**
     * @return list<int>
     */
    protected function weekendDays(Project $project): array
    {
        return self::WEEKEND_PRESETS[$project->schedule_weekend] ?? self::WEEKEND_PRESETS['sat-sun'];
    }

    /**
     * Every dated milestone and task of the project, keyed by row id.
     *
     * @return array<string, array{start: int, end: int, model: Milestone|Task}>
     */
    protected function scheduleItems(int $projectId): array
    {
        $items = [];

        $milestones = Milestone::query()
            ->where('project_id', $projectId)
            ->get(['id', 'project_id', 'parent_milestone_id', 'planned_start_date', 'planned_end_date', 'status', 'sequence']);

        foreach ($milestones as $milestone) {
            $end = $milestone->planned_end_date ?? $milestone->planned_start_date;
            $items['milestone-'.$milestone->id] = ['start' => $this->dayNumber($milestone->planned_start_date), 'end' => $this->dayNumber($end), 'model' => $milestone];
        }

        $tasks = Task::query()
            ->where('source_type', 'milestone')
            ->whereIn('source_id', $milestones->modelKeys())
            ->get(['id', 'parent_task_id', 'source_type', 'source_id', 'status', 'start_date', 'due_date', 'progress_percentage']);

        foreach ($tasks as $task) {
            $start = $task->start_date ?? $task->due_date;

            if ($start === null) {
                continue;
            }

            $items['task-'.$task->id] = ['start' => $this->dayNumber($start), 'end' => $this->dayNumber($task->due_date ?? $start), 'model' => $task];
        }

        return $items;
    }

    /**
     * @return Collection<int, ScheduleDependency>
     */
    protected function scheduleLinks(int $projectId): Collection
    {
        return ScheduleDependency::query()->where('project_id', $projectId)->orderBy('id')->get();
    }

    /**
     * Whether linking $from → $to would close a loop in the project's links.
     */
    protected function linkWouldCycle(int $projectId, string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        $next = [];
        foreach ($this->scheduleLinks($projectId) as $link) {
            $next[$link->predecessorRowId()][] = $link->successorRowId();
        }

        // A cycle exists if $from is already reachable from $to.
        $stack = [$to];
        $seen = [];

        while ($stack !== []) {
            $current = array_pop($stack);

            if ($current === $from) {
                return true;
            }

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            array_push($stack, ...($next[$current] ?? []));
        }

        return false;
    }

    /**
     * Push every successor that breaks one of its links to the earliest date
     * that satisfies all of them, and save the moved rows.
     *
     * @return list<array{id: string, start: string, end: string}> the moved rows as they stood BEFORE the push
     */
    protected function autoSchedule(Project $project): array
    {
        $weekend = $this->weekendDays($project);
        $items = $this->scheduleItems($project->id);
        $links = $this->scheduleLinks($project->id)->filter(
            fn (ScheduleDependency $link): bool => isset($items[$link->predecessorRowId()], $items[$link->successorRowId()])
        );

        $original = array_map(fn (array $item): array => ['start' => $item['start'], 'end' => $item['end']], $items);

        foreach ($this->topologicalOrder(array_keys($items), $links) as $rowId) {
            $incoming = $links->filter(fn (ScheduleDependency $link): bool => $link->successorRowId() === $rowId);

            if ($incoming->isEmpty()) {
                continue;
            }

            $item = $items[$rowId];
            $duration = $this->workingSpan($item['start'], $item['end'], $weekend);
            $earliestStart = $item['start'];

            foreach ($incoming as $link) {
                $earliestStart = max($earliestStart, $this->requiredStart($items[$link->predecessorRowId()], $link, $duration, $weekend));
            }

            if ($earliestStart > $item['start']) {
                $items[$rowId]['start'] = $this->snapForward($earliestStart, $weekend);
                $items[$rowId]['end'] = max($items[$rowId]['start'], $this->addWorkingDays($items[$rowId]['start'], $duration - 1, $weekend));
            }
        }

        $moved = [];

        foreach ($items as $rowId => $item) {
            if ($item['start'] === $original[$rowId]['start'] && $item['end'] === $original[$rowId]['end']) {
                continue;
            }

            $this->writeDates($item['model'], $this->isoDate($item['start']), $this->isoDate($item['end']));
            $moved[] = ['id' => $rowId, 'start' => $this->isoDate($original[$rowId]['start']), 'end' => $this->isoDate($original[$rowId]['end'])];
        }

        return $moved;
    }

    /**
     * Total float for every dated item: how many working days it can slip
     * before the project finish (or a successor's latest start) moves.
     *
     * @param  array<string, array{start: int, end: int}>  $items
     * @param  Collection<int, ScheduleDependency>  $links
     * @param  list<int>  $weekend
     * @return array<string, int>
     */
    protected function totalFloat(array $items, Collection $links, array $weekend): array
    {
        if ($items === []) {
            return [];
        }

        $links = $links->filter(fn (ScheduleDependency $link): bool => isset($items[$link->predecessorRowId()], $items[$link->successorRowId()]));
        $projectFinish = max(array_column($items, 'end'));
        $latestStart = [];
        $latestFinish = [];

        foreach (array_reverse($this->topologicalOrder(array_keys($items), $links)) as $rowId) {
            $item = $items[$rowId];
            $duration = $this->workingSpan($item['start'], $item['end'], $weekend);
            $finish = $this->snapBackward($projectFinish, $weekend);

            foreach ($links->filter(fn (ScheduleDependency $link): bool => $link->predecessorRowId() === $rowId) as $link) {
                $successor = $link->successorRowId();
                $lag = $link->lag_days;

                $finish = min($finish, match ($link->type) {
                    DependencyType::FinishToStart => $this->addWorkingDays($latestStart[$successor], -1 - $lag, $weekend),
                    DependencyType::StartToStart => $this->addWorkingDays($this->addWorkingDays($latestStart[$successor], -$lag, $weekend), $duration - 1, $weekend),
                    DependencyType::FinishToFinish => $this->addWorkingDays($latestFinish[$successor], -$lag, $weekend),
                    DependencyType::StartToFinish => $this->addWorkingDays($this->addWorkingDays($latestFinish[$successor], -$lag, $weekend), $duration - 1, $weekend),
                });
            }

            $latestFinish[$rowId] = $finish;
            $latestStart[$rowId] = $this->addWorkingDays($finish, 1 - $duration, $weekend);
        }

        $float = [];
        foreach ($items as $rowId => $item) {
            $float[$rowId] = $this->signedWorkingDays($this->snapForward($item['start'], $weekend), $latestStart[$rowId], $weekend);
        }

        return $float;
    }

    /**
     * The earliest start a successor may take under one link.
     *
     * @param  array{start: int, end: int}  $predecessor
     * @param  list<int>  $weekend
     */
    private function requiredStart(array $predecessor, ScheduleDependency $link, int $duration, array $weekend): int
    {
        $lag = $link->lag_days;

        return match ($link->type) {
            DependencyType::FinishToStart => $this->addWorkingDays($this->snapBackward($predecessor['end'], $weekend), 1 + $lag, $weekend),
            DependencyType::StartToStart => $this->addWorkingDays($predecessor['start'], $lag, $weekend),
            DependencyType::FinishToFinish => $this->addWorkingDays($this->addWorkingDays($this->snapBackward($predecessor['end'], $weekend), $lag, $weekend), 1 - $duration, $weekend),
            DependencyType::StartToFinish => $this->addWorkingDays($this->addWorkingDays($predecessor['start'], $lag, $weekend), 1 - $duration, $weekend),
        };
    }

    /**
     * Row ids ordered so every predecessor comes before its successors. Rows
     * caught in a cycle (which linking refuses, but data can still hold) are
     * appended at the end rather than dropped.
     *
     * @param  list<string>  $rowIds
     * @param  Collection<int, ScheduleDependency>  $links
     * @return list<string>
     */
    private function topologicalOrder(array $rowIds, Collection $links): array
    {
        $incoming = array_fill_keys($rowIds, 0);
        $next = [];

        foreach ($links as $link) {
            $from = $link->predecessorRowId();
            $to = $link->successorRowId();

            if (! isset($incoming[$from], $incoming[$to])) {
                continue;
            }

            $next[$from][] = $to;
            $incoming[$to]++;
        }

        $queue = array_keys(array_filter($incoming, fn (int $count): bool => $count === 0));
        $order = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            $order[] = $current;

            foreach ($next[$current] ?? [] as $successor) {
                if (--$incoming[$successor] === 0) {
                    $queue[] = $successor;
                }
            }
        }

        return array_values(array_unique([...$order, ...$rowIds]));
    }

    protected function writeDates(Milestone|Task $model, string $start, string $end): void
    {
        if ($model instanceof Milestone) {
            $model->update(['planned_start_date' => $start, 'planned_end_date' => $end]);
        } else {
            $model->update(['start_date' => $start, 'due_date' => $end]);
        }
    }

    /* Working-day calendar ---------------------------------------------- */

    protected function dayNumber(CarbonImmutable|string $date): int
    {
        $iso = $date instanceof CarbonImmutable ? $date->toDateString() : $date;
        [$year, $month, $day] = array_map('intval', explode('-', $iso));

        return intdiv(gmmktime(0, 0, 0, $month, $day, $year), 86400);
    }

    protected function isoDate(int $dayNumber): string
    {
        return gmdate('Y-m-d', $dayNumber * 86400);
    }

    /**
     * @param  list<int>  $weekend
     */
    protected function isWorkingDay(int $dayNumber, array $weekend): bool
    {
        return ! in_array(($dayNumber + 4) % 7, $weekend, true);
    }

    /**
     * @param  list<int>  $weekend
     */
    protected function snapForward(int $dayNumber, array $weekend): int
    {
        while (count($weekend) < 7 && ! $this->isWorkingDay($dayNumber, $weekend)) {
            $dayNumber++;
        }

        return $dayNumber;
    }

    /**
     * @param  list<int>  $weekend
     */
    protected function snapBackward(int $dayNumber, array $weekend): int
    {
        while (count($weekend) < 7 && ! $this->isWorkingDay($dayNumber, $weekend)) {
            $dayNumber--;
        }

        return $dayNumber;
    }

    /**
     * Move $count working days from a date (backwards when negative). With a
     * count of zero the date is only snapped onto a working day.
     *
     * @param  list<int>  $weekend
     */
    protected function addWorkingDays(int $dayNumber, int $count, array $weekend): int
    {
        $step = $count < 0 ? -1 : 1;
        $dayNumber = $step > 0 ? $this->snapForward($dayNumber, $weekend) : $this->snapBackward($dayNumber, $weekend);

        for ($moved = 0; $moved < abs($count); $moved++) {
            $dayNumber += $step;

            while (! $this->isWorkingDay($dayNumber, $weekend)) {
                $dayNumber += $step;
            }
        }

        return $dayNumber;
    }

    /**
     * Working days from start to end, both inclusive; never less than one.
     *
     * @param  list<int>  $weekend
     */
    protected function workingSpan(int $start, int $end, array $weekend): int
    {
        $count = 0;

        for ($day = $start; $day <= $end; $day++) {
            $count += $this->isWorkingDay($day, $weekend) ? 1 : 0;
        }

        return max(1, $count);
    }

    /**
     * Signed count of working-day steps from $from to $to.
     *
     * @param  list<int>  $weekend
     */
    private function signedWorkingDays(int $from, int $to, array $weekend): int
    {
        if ($from === $to) {
            return 0;
        }

        $sign = $to > $from ? 1 : -1;
        $count = 0;

        for ($day = $from; $day !== $to; $day += $sign) {
            $count += $this->isWorkingDay($day + $sign, $weekend) ? 1 : 0;
        }

        return $sign * $count;
    }
}
