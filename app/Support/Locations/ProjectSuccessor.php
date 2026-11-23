<?php

declare(strict_types=1);

namespace App\Support\Locations;

use App\Models\Proposal;
use App\Models\ProposalLocation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * The one project → location row mapper of Stage B (D-94, wave4/AGENT.md §8.1).
 *
 * A project may be carried by rows of several proposals. Anything that has a
 * date belongs to exactly one of them: the row whose proposal period covers
 * that date, and otherwise the row of the most recent proposal. Every Stage B
 * backfill maps a project through this class and nothing else, so the rule
 * lives in one place and no migration invents its own.
 *
 * The period is read the same way as App\Support\MonthlyReportGenerator's
 * window: `actual_date_begin ?? planned_date_begin` to
 * `effectiveDateEnd() ?? planned_date_end` (W-08's extension included).
 *
 * @see docs/lifecycle/contracts.md §7
 * @deprecated Y-18 deletes this class with the `projects` table.
 */
final class ProjectSuccessor
{
    /**
     * The proposal columns a period needs. Mirrors
     * MonthlyReportGenerator::WindowColumns, plus the row join key.
     */
    private const ProposalColumns = [
        'proposals.id',
        'proposals.planned_date_begin',
        'proposals.planned_date_end',
        'proposals.actual_date_begin',
        'proposals.actual_date_end',
        'proposals.extended_date_end',
    ];

    /**
     * The row that carries the project on $date (D-94).
     *
     * The row whose proposal period covers $date; when none or several cover
     * it, or $date is null, the row of the most recent proposal (latest
     * begin, then highest id). Null only when no row carries the project.
     */
    public function rowFor(int $projectId, ?CarbonInterface $date = null): ?ProposalLocation
    {
        return $this->rowAndReasonFor($projectId, $date)[0];
    }

    /**
     * The row, and whether the date alone decided it.
     *
     * A backfill has to report the two cases apart (wave4/AGENT.md §8.7), so
     * the reason comes from the rule itself rather than being inferred again
     * by the caller. `true` means exactly one proposal period covered the
     * date; `false` means the row of the most recent proposal was taken.
     *
     * @return array{0: ?ProposalLocation, 1: bool}
     */
    public function rowAndReasonFor(int $projectId, ?CarbonInterface $date = null): array
    {
        $rows = $this->rowsFor($projectId);

        if ($rows->isEmpty()) {
            return [null, false];
        }

        if ($date === null) {
            return [$rows->first(), false];
        }

        $covering = $rows->filter(fn (ProposalLocation $row): bool => $this->covers($row->proposal, $date));

        return $covering->count() === 1 ? [$covering->first(), true] : [$rows->first(), false];
    }

    /**
     * Every row carrying the project, the most recent proposal first (latest
     * begin, then highest id), each with its proposal's period loaded.
     *
     * @return Collection<int, ProposalLocation>
     */
    public function rowsFor(int $projectId): Collection
    {
        /** @var Collection<int, ProposalLocation> $rows */
        $rows = ProposalLocation::query()
            ->where('project_id', $projectId)
            ->with(['proposal' => fn ($query) => $query->select(self::ProposalColumns)])
            ->get();

        return $rows
            ->sortByDesc(fn (ProposalLocation $row): string => sprintf(
                '%s|%020d',
                $this->beginOf($row->proposal)?->toDateString() ?? '0000-00-00',
                $row->proposal->id,
            ))
            ->values();
    }

    /**
     * Whether the proposal's period covers the date. A proposal missing
     * either end of its period covers nothing: it cannot be compared.
     */
    private function covers(?Proposal $proposal, CarbonInterface $date): bool
    {
        $begin = $this->beginOf($proposal);
        $end = $this->endOf($proposal);

        if ($begin === null || $end === null) {
            return false;
        }

        return $date->betweenIncluded($begin, $end);
    }

    private function beginOf(?Proposal $proposal): ?CarbonImmutable
    {
        if ($proposal === null) {
            return null;
        }

        return $proposal->actual_date_begin ?? $proposal->planned_date_begin;
    }

    private function endOf(?Proposal $proposal): ?CarbonImmutable
    {
        if ($proposal === null) {
            return null;
        }

        return $proposal->effectiveDateEnd() ?? $proposal->planned_date_end;
    }
}
