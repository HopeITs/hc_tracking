<?php

declare(strict_types=1);

namespace App\Support\Locations;

use App\Models\Activity;
use App\Models\ProposalLocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Move every indicator instance scoped to a project onto the location row
 * that took the project's place (Y-16 slice 1, D-93, contracts.md §11).
 *
 * The one definition behind `indicators:row-backfill` and the migration that
 * runs it.
 *
 * Two cases, and the first needs no date rule:
 *
 * - An instance under a **proposal's** framework sits beneath one proposal,
 *   and a row is of one proposal (D-93), so it goes to the row of **its own
 *   proposal** that carries its project. Exact, counted as `by_own_proposal`.
 * - An instance under the **program** framework has no proposal of its own,
 *   so D-94 decides: `ProjectSuccessor::rowAndReasonFor()` by the instance's
 *   `created_at`, counted as `by_date` or `by_most_recent`
 *   (wave4/AGENT.md §8.7).
 *
 * An instance whose project no row carries — or whose own proposal has no row
 * carrying it — is listed by id and left alone (D-80). `project_id` is left as
 * it is: it is still written until Y-18.
 *
 * Records no activity and dispatches nothing. Safe to re-run: only an instance
 * with no location yet is written.
 *
 * @deprecated Y-18 deletes this class with the `projects` table.
 */
final class IndicatorRowBackfill
{
    public function __construct(private ProjectSuccessor $successor) {}

    private int $seen = 0;

    private int $moved = 0;

    private int $byOwnProposal = 0;

    private int $byDate = 0;

    private int $byMostRecent = 0;

    /** @var list<int> */
    private array $unmapped = [];

    /** @var array<int, int> row id => instances moved onto it */
    private array $rows = [];

    /** @var array<int, EloquentCollection<int, ProposalLocation>> */
    private array $rowsByProject = [];

    /**
     * Run the backfill, writing only when $apply is true; a dry run does the
     * same work inside a transaction it rolls back, so its counts are exact.
     *
     * @return array{
     *     seen: int,
     *     moved: int,
     *     by_own_proposal: int,
     *     by_date: int,
     *     by_most_recent: int,
     *     unmapped: list<int>,
     *     rows: array<int, int>,
     * }
     */
    public function run(bool $apply): array
    {
        $this->seen = 0;
        $this->moved = 0;
        $this->byOwnProposal = 0;
        $this->byDate = 0;
        $this->byMostRecent = 0;
        $this->unmapped = [];
        $this->rows = [];
        $this->rowsByProject = [];

        DB::beginTransaction();

        try {
            Activity::withoutRecording(fn () => $this->moveAll($this->instances()));

            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        ksort($this->rows);

        return [
            'seen' => $this->seen,
            'moved' => $this->moved,
            'by_own_proposal' => $this->byOwnProposal,
            'by_date' => $this->byDate,
            'by_most_recent' => $this->byMostRecent,
            'unmapped' => $this->unmapped,
            'rows' => $this->rows,
        ];
    }

    /**
     * Every instance still waiting for a row, with its framework's proposal.
     *
     * @return Collection<int, \stdClass> id, project_id, proposal_id, created_at
     */
    private function instances(): Collection
    {
        return DB::table('indicators')
            ->join('results', 'results.id', '=', 'indicators.result_id')
            ->select(['indicators.id', 'indicators.project_id', 'results.proposal_id', 'indicators.created_at'])
            ->whereNotNull('indicators.project_id')
            ->whereNull('indicators.proposal_location_id')
            ->orderBy('indicators.id')
            ->get();
    }

    /**
     * @param  Collection<int, \stdClass>  $instances
     */
    private function moveAll(Collection $instances): void
    {
        foreach ($instances as $instance) {
            $this->seen++;

            $projectId = (int) $instance->project_id;

            [$row, $reason] = $instance->proposal_id === null
                ? $this->byDateRule($projectId, $instance->created_at)
                : [
                    $this->rowsCarrying($projectId)->firstWhere('proposal_id', (int) $instance->proposal_id),
                    'own_proposal',
                ];

            if (! $row instanceof ProposalLocation) {
                $this->unmapped[] = (int) $instance->id;

                continue;
            }

            DB::table('indicators')->where('id', $instance->id)->update(['proposal_location_id' => $row->id]);

            $this->moved++;
            $this->rows[$row->id] = ($this->rows[$row->id] ?? 0) + 1;

            match ($reason) {
                'own_proposal' => $this->byOwnProposal++,
                'date' => $this->byDate++,
                'most_recent' => $this->byMostRecent++,
            };
        }
    }

    /**
     * D-94 for an instance of the program framework: the row whose proposal
     * period covers the day it was recorded, else the most recent row.
     *
     * @return array{0: ?ProposalLocation, 1: string}
     */
    private function byDateRule(int $projectId, mixed $createdAt): array
    {
        $date = $createdAt === null ? null : CarbonImmutable::parse((string) $createdAt);

        [$row, $byDate] = $this->successor->rowAndReasonFor($projectId, $date);

        return [$row, $byDate ? 'date' : 'most_recent'];
    }

    /**
     * @return EloquentCollection<int, ProposalLocation>
     */
    private function rowsCarrying(int $projectId): EloquentCollection
    {
        return $this->rowsByProject[$projectId] ??= $this->successor->rowsFor($projectId);
    }
}
