<?php

declare(strict_types=1);

namespace App\Support\Locations;

use App\Models\Activity;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Move every task raised against a project onto the location row that carries
 * it (Y-12, D-93, D-94). The one definition behind `tasks:row-backfill`.
 *
 * A task has a date, so it goes to exactly one row: the one
 * `ProjectSuccessor::rowFor()` picks from its due date, or from the day it was
 * recorded when it has none. A **subtask follows its parent**, whatever its
 * own date, so a task tree is never split across two rows — the same rule
 * `MoveTask` already applies when a task is moved by hand.
 *
 * A task whose project no row carries keeps its `project` source: it is
 * reported and left alone (D-80), never guessed. Those projects need a
 * proposal first (D-84, D-87), which is a human step.
 *
 * Records no activity and dispatches nothing: this is history, not news.
 * Safe to re-run — a task already on a row is no longer a `project` source.
 *
 * @deprecated Y-18 deletes this class with the `projects` table.
 */
final class TaskRowBackfill
{
    public function __construct(private ProjectSuccessor $successor) {}

    private int $tasks = 0;

    private int $moved = 0;

    private int $byDate = 0;

    private int $byMostRecent = 0;

    private int $subtasksFollowed = 0;

    /** @var list<int> */
    private array $unmapped = [];

    /** @var array<int, int> */
    private array $projectsWithoutRow = [];

    /**
     * Run the backfill, writing only when $apply is true; a dry run does the
     * same work inside a transaction it rolls back, so its counts are exact.
     *
     * @return array{
     *     tasks: int,
     *     moved: int,
     *     by_date: int,
     *     by_most_recent: int,
     *     subtasks_followed: int,
     *     unmapped: list<int>,
     *     projects_without_row: list<int>,
     * }
     */
    public function run(bool $apply): array
    {
        $this->tasks = 0;
        $this->moved = 0;
        $this->byDate = 0;
        $this->byMostRecent = 0;
        $this->subtasksFollowed = 0;
        $this->unmapped = [];
        $this->projectsWithoutRow = [];

        DB::beginTransaction();

        try {
            Activity::withoutRecording(fn () => $this->move());

            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return [
            'tasks' => $this->tasks,
            'moved' => $this->moved,
            'by_date' => $this->byDate,
            'by_most_recent' => $this->byMostRecent,
            'subtasks_followed' => $this->subtasksFollowed,
            'unmapped' => $this->unmapped,
            'projects_without_row' => array_values($this->projectsWithoutRow),
        ];
    }

    private function move(): void
    {
        // Roots first, so a subtask of a moved parent is already on the
        // parent's row by the time its own turn comes.
        $roots = Task::query()
            ->where('source_type', 'project')
            ->whereNull('parent_task_id')
            ->orderBy('id')
            ->get(['id', 'source_id', 'due_date', 'created_at']);

        $rowByTask = [];

        foreach ($roots as $task) {
            $this->tasks++;

            [$row, $byDate] = $this->rowFor($task);

            if ($row === null) {
                $this->unmapped[] = $task->id;
                $this->projectsWithoutRow[(int) $task->source_id] = (int) $task->source_id;

                continue;
            }

            $this->write($task->id, $row);
            $rowByTask[$task->id] = $row;

            $this->moved++;
            $byDate ? $this->byDate++ : $this->byMostRecent++;
        }

        // Children, each taking its parent's row.
        $children = Task::query()
            ->where('source_type', 'project')
            ->whereNotNull('parent_task_id')
            ->orderBy('id')
            ->get(['id', 'source_id', 'parent_task_id', 'due_date', 'created_at']);

        foreach ($children as $child) {
            $this->tasks++;

            $row = $rowByTask[$child->parent_task_id] ?? $this->rowOfTask((int) $child->parent_task_id);

            if ($row === null) {
                $this->unmapped[] = $child->id;
                $this->projectsWithoutRow[(int) $child->source_id] = (int) $child->source_id;

                continue;
            }

            $this->write($child->id, $row);
            $rowByTask[$child->id] = $row;

            $this->moved++;
            $this->subtasksFollowed++;
        }
    }

    /**
     * The row a root task belongs on, and whether its own date decided it.
     *
     * @return array{0: int|null, 1: bool}
     */
    private function rowFor(Task $task): array
    {
        $date = $task->due_date ?? $task->created_at;
        $date = $date === null ? null : CarbonImmutable::parse($date);

        [$row, $byDate] = $this->successor->rowAndReasonFor((int) $task->source_id, $date);

        return [$row?->id, $byDate];
    }

    /**
     * The row a parent already sits on, for a child whose parent this run did
     * not move (its parent was moved by an earlier run).
     */
    private function rowOfTask(int $taskId): ?int
    {
        $parent = DB::table('tasks')->where('id', $taskId)->first(['source_type', 'source_id']);

        if ($parent === null || $parent->source_type !== 'proposal_location') {
            return null;
        }

        return (int) $parent->source_id;
    }

    private function write(int $taskId, int $rowId): void
    {
        DB::table('tasks')->where('id', $taskId)->update([
            'source_type' => 'proposal_location',
            'source_id' => $rowId,
            'updated_at' => now(),
        ]);
    }
}
