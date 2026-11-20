<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PlanIndicator;
use App\Models\Proposal;
use Illuminate\Database\Eloquent\Model;

/**
 * Deletes the targets planned against a source once that source is gone.
 *
 * `plan_indicators.source_id` is polymorphic and carries no foreign key, so the
 * database cannot clean these up. `source_type` and `source_id` are both NOT
 * NULL, so the row cannot be detached either — deleting it is the only option.
 *
 * A proposal's targets go on `deleting` (Y-10): they point at the proposal's
 * location rows with a restricting foreign key, and the database cascade
 * from the proposal to those rows would otherwise be refused. The caller's
 * transaction (DeleteProposal) rolls them back if the delete then fails.
 * Annual-plan targets still go on `deleted`.
 *
 * The `reports` and `report_files` beneath each target are removed by the
 * database, which cascades from `plan_indicators`.
 */
class PlanIndicatorSourceObserver
{
    /**
     * Handle a proposal about to be deleted.
     */
    public function deleting(Model $source): void
    {
        if ($source instanceof Proposal) {
            $this->deleteTargetsOf($source);
        }
    }

    /**
     * Handle the deletion of a annual plan or a proposal.
     */
    public function deleted(Model $source): void
    {
        $this->deleteTargetsOf($source);
    }

    private function deleteTargetsOf(Model $source): void
    {
        PlanIndicator::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->delete();
    }
}
