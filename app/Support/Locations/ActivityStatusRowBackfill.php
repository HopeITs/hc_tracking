<?php

declare(strict_types=1);

namespace App\Support\Locations;

use App\Models\Activity;
use App\Models\ProjectActivityStatus;
use App\Models\ProposalLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Move every activity status confirmed by a monthly report (D-51) from its
 * project onto the location row that took the project's place (Y-15 slice 2,
 * D-93). The one definition behind `activity-statuses:row-backfill` and the
 * migration that runs it.
 *
 * No date rule is needed (§8.1, D-94 does not apply): a status is of one
 * activity, an activity is a result of one proposal, and a row is of one
 * proposal, so a status goes to the row of **the activity's own proposal**
 * that carries its project. Which rows carry a project is
 * `ProjectSuccessor::rowsFor()`'s answer and nothing else. "By date" and "by
 * most recent" are therefore always 0 (wave4/AGENT.md §8.7).
 *
 * A status whose project no row of its own proposal carries, or more than
 * one does, is listed by id and left alone (D-80); the tightening migration
 * refuses until none is left. `project_id` is left as it is: it is still
 * written until Y-18.
 *
 * Records no activity and dispatches nothing. Safe to re-run: only a status
 * with no row yet is written.
 *
 * @deprecated Y-18 deletes this class with the `projects` table.
 */
final class ActivityStatusRowBackfill
{
    public function __construct(private ProjectSuccessor $successor) {}

    private int $seen = 0;

    private int $moved = 0;

    /** @var array<int, int> */
    private array $rows = [];

    /** @var list<int> */
    private array $unmapped = [];

    /** @var array<int, Collection<int, ProposalLocation>> */
    private array $rowsByProject = [];

    /**
     * Run the backfill, writing only when $apply is true; a dry run does the
     * same work inside a transaction it rolls back, so its counts are exact.
     *
     * @return array{
     *     statuses_seen: int,
     *     moved: int,
     *     by_own_proposal: int,
     *     by_date: int,
     *     by_most_recent: int,
     *     rows: array<int, int>,
     *     unmapped: list<int>,
     * }
     */
    public function run(bool $apply): array
    {
        $this->reset();

        DB::beginTransaction();

        try {
            Activity::withoutRecording(function (): void {
                ProjectActivityStatus::query()
                    ->select(['project_activity_statuses.id', 'project_activity_statuses.project_id', 'results.proposal_id as activity_proposal_id'])
                    ->join('results', 'results.id', '=', 'project_activity_statuses.result_id')
                    ->whereNotNull('project_activity_statuses.project_id')
                    ->whereNull('project_activity_statuses.proposal_location_id')
                    ->orderBy('project_activity_statuses.id')
                    ->each(fn (ProjectActivityStatus $status) => $this->move($status));
            });

            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return [
            'statuses_seen' => $this->seen,
            'moved' => $this->moved,
            'by_own_proposal' => $this->moved,
            'by_date' => 0,
            'by_most_recent' => 0,
            'rows' => $this->rows,
            'unmapped' => $this->unmapped,
        ];
    }

    /**
     * Give one status the row of its activity's proposal carrying its
     * project, written straight through the query builder (no timestamps, no
     * events).
     */
    private function move(ProjectActivityStatus $status): void
    {
        $this->seen++;

        $proposalId = $status->getAttribute('activity_proposal_id');
        $candidates = $proposalId === null
            ? collect()
            : $this->rowsCarrying((int) $status->project_id)->where('proposal_id', (int) $proposalId);

        if ($candidates->count() !== 1) {
            $this->unmapped[] = $status->id;

            return;
        }

        $rowId = (int) $candidates->first()->id;

        DB::table('project_activity_statuses')->where('id', $status->id)->update(['proposal_location_id' => $rowId]);

        $this->moved++;
        $this->rows[$rowId] = ($this->rows[$rowId] ?? 0) + 1;
    }

    /**
     * @return Collection<int, ProposalLocation>
     */
    private function rowsCarrying(int $projectId): Collection
    {
        return $this->rowsByProject[$projectId] ??= $this->successor->rowsFor($projectId);
    }

    private function reset(): void
    {
        $this->seen = 0;
        $this->moved = 0;
        $this->rows = [];
        $this->unmapped = [];
        $this->rowsByProject = [];
    }
}
