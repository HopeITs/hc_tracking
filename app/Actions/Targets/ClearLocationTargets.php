<?php

declare(strict_types=1);

namespace App\Actions\Targets;

use App\Models\Activity;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Delete every target of one location row of a proposal (Y-10), so the row
 * can be removed or its project unlinked: a row that carries targets is never
 * removed on its own (ProposalLocationRows).
 *
 * - Who: `targets.delete` (MEAL Manager slot, General Manager, Super Admin).
 * - Scope (BOLA): the row is loaded through the proposal's own rows.
 * - A target with reports is never deleted (a measurement is not discarded):
 *   one reported target refuses the whole call and nothing is deleted.
 * - One transaction on the locked proposal; one `proposal.targets_saved`
 *   summary (counts only, no values), as the targets grid records.
 *
 * Error key: targets.
 */
final class ClearLocationTargets
{
    /**
     * @return int the number of targets deleted
     *
     * @throws ValidationException
     */
    public function handle(Proposal $proposal, int $locationId): int
    {
        Gate::authorize('targets.delete');

        return DB::transaction(function () use ($proposal, $locationId): int {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();
            $row = $locked->locations()->whereKey($locationId)->firstOrFail();

            $targets = $row->targets()->withCount('reports')->lockForUpdate()->get();

            if ($targets->contains(fn (PlanIndicator $target): bool => $target->reports_count > 0)) {
                throw ValidationException::withMessages(['targets' => __('targets.location_targets_reported')]);
            }

            if ($targets->isEmpty()) {
                return 0;
            }

            Activity::withoutRecording(fn () => $targets->each(fn (PlanIndicator $target) => $target->delete()));

            $locked->recordActivity('proposal.targets_saved', [], ['created' => 0, 'updated' => 0, 'deleted' => $targets->count()]);

            return $targets->count();
        });
    }
}
