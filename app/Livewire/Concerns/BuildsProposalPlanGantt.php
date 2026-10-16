<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Proposal;
use Carbon\CarbonImmutable;

/**
 * The read-only Gantt of one proposal's plan (T31, D-05): the same rows the
 * project page draws for this proposal, before any center filter, so the
 * planning team sees the shared and the center-specific work together.
 *
 * @phpstan-import-type GanttRow from BuildsProjectGantt
 */
trait BuildsProposalPlanGantt
{
    use BuildsProjectGantt;

    /**
     * @return array{rows: list<GanttRow>, links: list<array<string, mixed>>, from: string, to: string, today: string}
     */
    protected function proposalPlanGanttPayload(Proposal $proposal): array
    {
        $rows = $this->newGanttRows();

        $this->pushProposalPlanRows($proposal, $rows);

        $dates = [
            ...$rows->flatMap(fn (array $row): array => [$row['start'], $row['end'], $row['actualStart'], $row['actualEnd']])
                ->push($proposal->planned_date_begin?->toDateString(), $proposal->planned_date_end?->toDateString())
                ->filter()
                ->all(),
            CarbonImmutable::today()->toDateString(),
        ];

        return [
            'rows' => array_values($rows->all()),
            'links' => [],
            'from' => min($dates),
            'to' => max($dates),
            'today' => CarbonImmutable::today()->toDateString(),
        ];
    }
}
