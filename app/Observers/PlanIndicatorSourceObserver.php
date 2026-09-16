<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PlanIndicator;
use Illuminate\Database\Eloquent\Model;

/**
 * Deletes the targets planned against a source once that source is gone.
 *
 * `plan_indicators.source_id` is polymorphic and carries no foreign key, so the
 * database cannot clean these up. `source_type` and `source_id` are both NOT
 * NULL, so the row cannot be detached either — deleting it is the only option.
 *
 * The `reports` and `report_files` beneath each target are removed by the
 * database, which cascades from `plan_indicators`.
 */
class PlanIndicatorSourceObserver
{
    /**
     * Handle the deletion of a annual plan or a proposal.
     */
    public function deleted(Model $source): void
    {
        PlanIndicator::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->delete();
    }
}
