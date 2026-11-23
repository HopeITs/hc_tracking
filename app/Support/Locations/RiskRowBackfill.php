<?php

declare(strict_types=1);

namespace App\Support\Locations;

use App\Models\Activity;
use App\Models\ProposalLocation;
use App\Models\Risk;
use Illuminate\Support\Facades\DB;

/**
 * Move every register risk onto the location row that took its project's
 * place (Y-13, D-93, D-94). The one definition behind `risks:row-backfill`
 * and the migration that runs it.
 *
 * It invents no rule of its own: which row carries a project on a given day
 * is `ProjectSuccessor`'s answer and nothing else (wave4/AGENT.md §8.1).
 * `risks` carries no identification date — none of the four risk migrations
 * added one — so D-94's "the day it was identified, else the day it was
 * recorded" is `created_at` for every risk.
 *
 * A risk that already names a proposal of its own takes that proposal's row
 * carrying the project, with no date rule: the risk was recorded against that
 * proposal, so the date cannot overrule it.
 *
 * Logframe risks (a proposal's result, no project) are not register risks and
 * are never touched. A risk whose project no row carries, or whose own
 * proposal carries the project through no row, is listed by id and left alone
 * (D-80). `project_id` is left as it is: it is still written until Y-18.
 *
 * Records no activity and dispatches nothing: this is history, not news. Safe
 * to re-run — only a risk that has no row yet is written.
 *
 * @deprecated Y-18 deletes this class with the `projects` table.
 */
final class RiskRowBackfill
{
    public function __construct(private ProjectSuccessor $successor) {}

    private int $risksMoved = 0;

    private int $byDate = 0;

    private int $byMostRecent = 0;

    private int $byOwnProposal = 0;

    /** @var array<int, int> */
    private array $rows = [];

    /** @var list<int> */
    private array $unmapped = [];

    /**
     * Run the backfill, writing only when $apply is true; a dry run does the
     * same work inside a transaction it rolls back, so its counts are exact
     * (the shape `LocationBackfill` established for Y-11).
     *
     * @return array{
     *     risks_moved: int,
     *     by_date: int,
     *     by_most_recent: int,
     *     by_own_proposal: int,
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
                Risk::query()
                    ->whereNotNull('project_id')
                    ->whereNull('proposal_location_id')
                    ->orderBy('id')
                    ->each(fn (Risk $risk) => $this->moveRisk($risk));
            });

            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return [
            'risks_moved' => $this->risksMoved,
            'by_date' => $this->byDate,
            'by_most_recent' => $this->byMostRecent,
            'by_own_proposal' => $this->byOwnProposal,
            'rows' => $this->rows,
            'unmapped' => $this->unmapped,
        ];
    }

    /**
     * Give one register risk its row, and the row's proposal when the risk has
     * none of its own.
     */
    private function moveRisk(Risk $risk): void
    {
        $row = $risk->proposal_id === null
            ? $this->rowByDate($risk)
            : $this->rowOfOwnProposal($risk);

        if ($row === null) {
            $this->unmapped[] = $risk->id;

            return;
        }

        $risk->forceFill([
            'proposal_location_id' => $row->id,
            'proposal_id' => $risk->proposal_id ?? $row->proposal_id,
        ])->saveQuietly(['timestamps' => false]);

        $this->risksMoved++;
        $this->rows[$row->id] = ($this->rows[$row->id] ?? 0) + 1;
    }

    /**
     * The row carrying the project on the day the risk was recorded (D-94),
     * counting the two cases apart (wave4/AGENT.md §8.7).
     */
    private function rowByDate(Risk $risk): ?ProposalLocation
    {
        [$row, $decidedByDate] = $this->successor->rowAndReasonFor((int) $risk->project_id, $risk->created_at);

        if ($row === null) {
            return null;
        }

        $decidedByDate ? $this->byDate++ : $this->byMostRecent++;

        return $row;
    }

    /**
     * The row through which the risk's own proposal carries the project.
     */
    private function rowOfOwnProposal(Risk $risk): ?ProposalLocation
    {
        $row = ProposalLocation::query()
            ->where('proposal_id', $risk->proposal_id)
            ->where('project_id', $risk->project_id)
            ->orderBy('id')
            ->first();

        if ($row === null) {
            return null;
        }

        $this->byOwnProposal++;

        return $row;
    }

    private function reset(): void
    {
        $this->risksMoved = 0;
        $this->byDate = 0;
        $this->byMostRecent = 0;
        $this->byOwnProposal = 0;
        $this->rows = [];
        $this->unmapped = [];
    }
}
