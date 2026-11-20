<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use Illuminate\Database\Eloquent\Builder;

/**
 * How many targets a location row or a proposal carries, and how many of them
 * have reports (Y-10). The dialogs that remove a row, unlink a project or
 * delete a proposal say this before anything goes; a reported target is never
 * deleted. One query each.
 */
final class TargetCounts
{
    /**
     * @return array{count: int, reported: int}
     */
    public static function ofLocation(ProposalLocation $row): array
    {
        return self::count(PlanIndicator::query()->where('proposal_location_id', $row->id));
    }

    /**
     * @return array{count: int, reported: int}
     */
    public static function ofProposal(Proposal $proposal): array
    {
        return self::count(PlanIndicator::query()
            ->where('source_type', $proposal->getMorphClass())
            ->where('source_id', $proposal->getKey()));
    }

    /**
     * @param  Builder<PlanIndicator>  $targets
     * @return array{count: int, reported: int}
     */
    private static function count(Builder $targets): array
    {
        $row = $targets->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when exists (select 1 from reports where reports.plan_indicator_id = plan_indicators.id) then 1 else 0 end) as reported')
            ->first();

        return ['count' => (int) ($row->total ?? 0), 'reported' => (int) ($row->reported ?? 0)];
    }
}
