<?php

declare(strict_types=1);

namespace App\Actions\Results;

use App\Models\Proposal;
use App\Models\Result;
use App\Support\ProposalFramework;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Delete one logframe result (contracts §11.2).
 *
 * Refused while it has children (parent_id cascades, taking the subtree) or
 * indicators (indicators cascade, and plan_indicators and reports cascade
 * from those). Inside a proposal framework, once the proposal reaches
 * `submitted` only `results.update` holders may delete. The result is
 * reloaded through the framework it is deleted from, so another framework's
 * node is not found; a result already gone (a double click) returns quietly.
 *
 * Error keys: structure, result.
 */
final class DeleteResult
{
    /**
     * @throws ValidationException
     */
    public function handle(Result $result, ?Proposal $proposal = null): void
    {
        Gate::authorize('results.delete');

        DB::transaction(function () use ($result, $proposal): void {
            $lockedProposal = $proposal === null
                ? null
                : Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            $locked = Result::query()
                ->when(
                    $lockedProposal === null,
                    fn ($query) => $query->programFramework(),
                    fn ($query) => $query->proposalFramework($lockedProposal?->id),
                )
                ->whereKey($result->getKey())
                ->withCount(['children', 'indicators'])
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                if (Result::query()->whereKey($result->getKey())->exists()) {
                    throw (new ModelNotFoundException)->setModel(Result::class, [$result->getKey()]);
                }

                return;
            }

            if ($lockedProposal !== null
                && ProposalFramework::isStructureLocked($lockedProposal) && ! Gate::allows('results.update')) {
                throw ValidationException::withMessages(['structure' => __('results_framework.structural_lock')]);
            }

            if ($locked->children_count > 0 || $locked->indicators_count > 0) {
                throw ValidationException::withMessages([
                    'result' => __('results_framework.this_result_still_has_nested_results_or_indicators_remove_th'),
                ]);
            }

            $locked->delete();
        });
    }
}
