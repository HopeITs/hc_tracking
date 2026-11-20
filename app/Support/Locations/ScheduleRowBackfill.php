<?php

declare(strict_types=1);

namespace App\Support\Locations;

use App\Models\Activity;
use App\Models\ScheduleBaseline;
use App\Models\ScheduleDependency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Move schedule dependencies and baselines onto location rows (Y-12, D-93).
 * The one definition behind `schedule:row-backfill`.
 *
 * **A dependency goes to the row of its successor.** Both ends must be on the
 * same row for the link to mean anything: a dependency whose two ends land on
 * different rows cannot be honoured, so it is **deleted and listed** rather
 * than quietly pointed at one of them. An end that is not a task — the dead
 * `milestone` type, say, whose table was dropped on 17 Nov — makes the
 * dependency unresolvable, and it is listed and left alone (D-80).
 *
 * **A baseline goes to the row its own `created_at` picks** (D-94). An item of
 * that baseline whose task is no longer on that row is dropped from the
 * baseline and counted: a baseline is a snapshot of one row's schedule.
 *
 * `project_id` is kept and still written on both tables until Y-18, so the
 * Gantt and every reader not yet moved keep working.
 *
 * Records no activity and dispatches nothing: this is history, not news.
 *
 * @deprecated Y-18 deletes this class with the `projects` table.
 */
final class ScheduleRowBackfill
{
    public function __construct(private ProjectSuccessor $successor) {}

    private int $dependencies = 0;

    private int $dependenciesMoved = 0;

    /** @var list<int> */
    private array $dependenciesDeleted = [];

    /** @var list<int> */
    private array $dependenciesUnresolved = [];

    private int $baselines = 0;

    private int $baselinesMoved = 0;

    private int $baselinesByDate = 0;

    private int $baselinesByMostRecent = 0;

    private int $baselineItemsDropped = 0;

    /** @var list<int> */
    private array $baselinesUnmapped = [];

    /**
     * @return array{
     *     dependencies: int,
     *     dependencies_moved: int,
     *     dependencies_deleted: list<int>,
     *     dependencies_unresolved: list<int>,
     *     baselines: int,
     *     baselines_moved: int,
     *     baselines_by_date: int,
     *     baselines_by_most_recent: int,
     *     baseline_items_dropped: int,
     *     baselines_unmapped: list<int>,
     * }
     */
    public function run(bool $apply): array
    {
        $this->dependencies = 0;
        $this->dependenciesMoved = 0;
        $this->dependenciesDeleted = [];
        $this->dependenciesUnresolved = [];
        $this->baselines = 0;
        $this->baselinesMoved = 0;
        $this->baselinesByDate = 0;
        $this->baselinesByMostRecent = 0;
        $this->baselineItemsDropped = 0;
        $this->baselinesUnmapped = [];

        DB::beginTransaction();

        try {
            Activity::withoutRecording(function (): void {
                $this->moveDependencies();
                $this->moveBaselines();
            });

            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return [
            'dependencies' => $this->dependencies,
            'dependencies_moved' => $this->dependenciesMoved,
            'dependencies_deleted' => $this->dependenciesDeleted,
            'dependencies_unresolved' => $this->dependenciesUnresolved,
            'baselines' => $this->baselines,
            'baselines_moved' => $this->baselinesMoved,
            'baselines_by_date' => $this->baselinesByDate,
            'baselines_by_most_recent' => $this->baselinesByMostRecent,
            'baseline_items_dropped' => $this->baselineItemsDropped,
            'baselines_unmapped' => $this->baselinesUnmapped,
        ];
    }

    private function moveDependencies(): void
    {
        $dependencies = ScheduleDependency::query()
            ->whereNull('proposal_location_id')
            ->orderBy('id')
            ->get();

        foreach ($dependencies as $dependency) {
            $this->dependencies++;

            $successorRow = $this->rowOfItem($dependency->successor_type, (int) $dependency->successor_id);
            $predecessorRow = $this->rowOfItem($dependency->predecessor_type, (int) $dependency->predecessor_id);

            if ($successorRow === null || $predecessorRow === null) {
                $this->dependenciesUnresolved[] = $dependency->id;

                continue;
            }

            if ($successorRow !== $predecessorRow) {
                // Two rows cannot be linked: the dependency says "this starts
                // after that" about work that no longer shares a schedule.
                $this->dependenciesDeleted[] = $dependency->id;
                $dependency->delete();

                continue;
            }

            DB::table('schedule_dependencies')->where('id', $dependency->id)->update([
                'proposal_location_id' => $successorRow,
                'updated_at' => now(),
            ]);

            $this->dependenciesMoved++;
        }
    }

    private function moveBaselines(): void
    {
        $baselines = ScheduleBaseline::query()
            ->whereNull('proposal_location_id')
            ->orderBy('id')
            ->get();

        foreach ($baselines as $baseline) {
            $this->baselines++;

            $date = $baseline->created_at === null ? null : CarbonImmutable::parse($baseline->created_at);
            [$row, $byDate] = $this->successor->rowAndReasonFor((int) $baseline->project_id, $date);

            if ($row === null) {
                $this->baselinesUnmapped[] = $baseline->id;

                continue;
            }

            DB::table('schedule_baselines')->where('id', $baseline->id)->update([
                'proposal_location_id' => $row->id,
                'updated_at' => now(),
            ]);

            $this->baselinesMoved++;
            $byDate ? $this->baselinesByDate++ : $this->baselinesByMostRecent++;

            $this->baselineItemsDropped += $this->dropForeignItems($baseline, $row->id);
        }
    }

    /**
     * Items of the baseline whose task is not on the baseline's row are no
     * longer part of this snapshot.
     *
     * @return int the items dropped
     */
    private function dropForeignItems(ScheduleBaseline $baseline, int $rowId): int
    {
        $items = DB::table('schedule_baseline_items')
            ->where('schedule_baseline_id', $baseline->id)
            ->get(['id', 'item_type', 'item_id']);

        $foreign = [];

        foreach ($items as $item) {
            if ($this->rowOfItem((string) $item->item_type, (int) $item->item_id) !== $rowId) {
                $foreign[] = $item->id;
            }
        }

        if ($foreign === []) {
            return 0;
        }

        DB::table('schedule_baseline_items')->whereIn('id', $foreign)->delete();

        return count($foreign);
    }

    /**
     * The row a schedule item sits on, or null when it cannot be read.
     *
     * Only a task is a schedule item today. A task already moved by
     * `tasks:row-backfill` carries its row; one still on a `project` source is
     * mapped by its own date, exactly as that backfill would.
     */
    private function rowOfItem(string $type, int $id): ?int
    {
        if ($type !== 'task') {
            return null;
        }

        $task = DB::table('tasks')->where('id', $id)->first(['source_type', 'source_id', 'due_date', 'created_at']);

        if ($task === null) {
            return null;
        }

        if ($task->source_type === 'proposal_location') {
            return (int) $task->source_id;
        }

        if ($task->source_type !== 'project') {
            return null;
        }

        $date = $task->due_date ?? $task->created_at;

        return $this->successor->rowFor((int) $task->source_id, $date === null ? null : CarbonImmutable::parse($date))?->id;
    }
}
