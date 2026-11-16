<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Indicator;
use Illuminate\Database\Eloquent\Collection;

/**
 * The indicators a program's strategic plan may be measured by: those of the
 * program's own framework (not a proposal's), in framework order.
 */
final class StrategicPlanIndicators
{
    /**
     * @return Collection<int, Indicator>
     */
    public static function for(int $programId): Collection
    {
        return Indicator::query()
            ->select(['indicators.id', 'indicators.name', 'indicators.result_id', 'indicators.measure', 'indicators.unit', 'indicators.target'])
            ->whereHas('result', fn ($query) => $query->programFramework()->where('program_id', $programId))
            ->with('result:id,statement,type,program_id,proposal_id')
            ->orderBy('result_id')
            ->orderBy('id')
            ->get();
    }
}
