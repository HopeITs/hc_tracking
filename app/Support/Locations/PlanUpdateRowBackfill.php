<?php

declare(strict_types=1);

namespace App\Support\Locations;

use App\Models\Activity;
use App\Models\ProposalLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Move every monthly plan update and every follow-up task it fired from its
 * project onto the location row that took the project's place (Y-15 slice 3,
 * D-93). The one definition behind `plan-updates:row-backfill` and the
 * migration that runs it.
 *
 * No date rule is needed (§8.1, D-94 does not apply): an activity plan has one
 * proposal, and a row is of one proposal, so a plan's centre goes to the row
 * of **the plan's proposal** that carries its project. Which rows carry a
 * project is `ProjectSuccessor::rowsFor()`'s answer and nothing else. "By
 * date" and "by most recent" are therefore always 0 (wave4/AGENT.md §8.7).
 *
 * A row whose project no row of the plan's proposal carries is listed by id
 * and left alone (D-80). Two rows of the same proposal carrying it should be
 * impossible (one row per city and program); it is listed too, for Dev 1.
 * `project_id` is left as it is: it is still written until Y-18.
 *
 * Records no activity and dispatches nothing. Safe to re-run: only a row with
 * no location yet is written.
 *
 * @deprecated Y-18 deletes this class with the `projects` table.
 */
final class PlanUpdateRowBackfill
{
    public function __construct(private ProjectSuccessor $successor) {}

    /** @var array<string, int> table => records seen */
    private array $seen = [];

    /** @var array<string, int> table => records moved */
    private array $moved = [];

    /** @var array<string, list<int>> table => ids left alone */
    private array $unmapped = [];

    /** @var array<int, int> */
    private array $rows = [];

    /** @var array<int, Collection<int, ProposalLocation>> */
    private array $rowsByProject = [];

    /**
     * Run the backfill, writing only when $apply is true; a dry run does the
     * same work inside a transaction it rolls back, so its counts are exact.
     *
     * @return array{
     *     updates: array{seen: int, moved: int, unmapped: list<int>},
     *     follow_up_tasks: array{seen: int, moved: int, unmapped: list<int>},
     *     by_own_proposal: int,
     *     by_date: int,
     *     by_most_recent: int,
     *     rows: array<int, int>,
     * }
     */
    public function run(bool $apply): array
    {
        $this->seen = ['plan_updates' => 0, 'activity_plan_follow_up_tasks' => 0];
        $this->moved = ['plan_updates' => 0, 'activity_plan_follow_up_tasks' => 0];
        $this->unmapped = ['plan_updates' => [], 'activity_plan_follow_up_tasks' => []];
        $this->rows = [];
        $this->rowsByProject = [];

        DB::beginTransaction();

        try {
            Activity::withoutRecording(function (): void {
                $this->moveAll('plan_updates', DB::table('plan_updates')
                    ->join('activity_plans', 'activity_plans.id', '=', 'plan_updates.activity_plan_id')
                    ->select(['plan_updates.id', 'plan_updates.project_id', 'activity_plans.proposal_id'])
                    ->whereNotNull('plan_updates.project_id')
                    ->whereNull('plan_updates.proposal_location_id')
                    ->orderBy('plan_updates.id')
                    ->get());

                $this->moveAll('activity_plan_follow_up_tasks', DB::table('activity_plan_follow_up_tasks')
                    ->join('activity_plan_follow_ups', 'activity_plan_follow_ups.id', '=', 'activity_plan_follow_up_tasks.activity_plan_follow_up_id')
                    ->join('activity_plans', 'activity_plans.id', '=', 'activity_plan_follow_ups.activity_plan_id')
                    ->select(['activity_plan_follow_up_tasks.id', 'activity_plan_follow_up_tasks.project_id', 'activity_plans.proposal_id'])
                    ->whereNotNull('activity_plan_follow_up_tasks.project_id')
                    ->whereNull('activity_plan_follow_up_tasks.proposal_location_id')
                    ->orderBy('activity_plan_follow_up_tasks.id')
                    ->get());
            });

            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return [
            'updates' => $this->countsOf('plan_updates'),
            'follow_up_tasks' => $this->countsOf('activity_plan_follow_up_tasks'),
            'by_own_proposal' => array_sum($this->moved),
            'by_date' => 0,
            'by_most_recent' => 0,
            'rows' => $this->rows,
        ];
    }

    /**
     * @param  Collection<int, \stdClass>  $records  id, project_id, proposal_id
     */
    private function moveAll(string $table, Collection $records): void
    {
        foreach ($records as $record) {
            $this->seen[$table]++;

            $candidates = $this->rowsCarrying((int) $record->project_id)->where('proposal_id', (int) $record->proposal_id);

            if ($candidates->count() !== 1) {
                $this->unmapped[$table][] = (int) $record->id;

                continue;
            }

            $rowId = (int) $candidates->first()->id;

            DB::table($table)->where('id', $record->id)->update(['proposal_location_id' => $rowId]);

            $this->moved[$table]++;
            $this->rows[$rowId] = ($this->rows[$rowId] ?? 0) + 1;
        }
    }

    /**
     * @return array{seen: int, moved: int, unmapped: list<int>}
     */
    private function countsOf(string $table): array
    {
        return ['seen' => $this->seen[$table], 'moved' => $this->moved[$table], 'unmapped' => $this->unmapped[$table]];
    }

    /**
     * @return Collection<int, ProposalLocation>
     */
    private function rowsCarrying(int $projectId): Collection
    {
        return $this->rowsByProject[$projectId] ??= $this->successor->rowsFor($projectId);
    }
}
