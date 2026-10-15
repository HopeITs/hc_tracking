<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Models\Activity;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\Result;
use App\Notifications\ActivityRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Delete a proposal together with its own logical framework (contracts §11.2).
 *
 * Team B file in a Phase 1 folder; Dev 1 reviews it.
 *
 * - Refused while a funding agreement points at it (donor_proposal RESTRICTs,
 *   protecting the money trail) or while any indicator of its framework has a
 *   target with reports.
 * - results.proposal_id RESTRICTs, so the framework goes first: leaf-first
 *   through Eloquent (indicators, their targets and milestone links follow by
 *   foreign key), then the proposal itself, whose observer removes the targets
 *   committed in it.
 * - All in one transaction on the locked proposal row, without per-row
 *   activity, and one `proposal.deleted` entry. A second call for a proposal
 *   already gone returns quietly.
 *
 * Error key: proposal.
 */
final class DeleteProposal
{
    /**
     * @throws ValidationException
     */
    public function handle(Proposal $proposal): void
    {
        Gate::authorize('delete', $proposal);

        DB::transaction(function () use ($proposal): void {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            if ($locked->donorProposals()->exists()) {
                $this->refuse('proposals.this_proposal_has_a_funding_agreement_remove_the_agreement_f');
            }

            $frameworkIndicatorIds = Indicator::query()
                ->select('id')
                ->whereIn('result_id', Result::query()->select('id')->where('proposal_id', $locked->id));

            if (PlanIndicator::query()->whereIn('indicator_id', $frameworkIndicatorIds)->whereHas('reports')->exists()) {
                $this->refuse('results_framework.proposal_delete_reported');
            }

            Activity::withoutRecording(function () use ($locked): void {
                $this->leafFirst($locked->results()->select(['id', 'parent_id'])->lockForUpdate()->get())
                    ->each(fn (Result $result) => $result->delete());

                $locked->delete();
            });

            app(ActivityRecorder::class)->record($locked, 'proposal.deleted');
        });
    }

    /**
     * Order a framework so every result comes before its parent.
     *
     * @param  Collection<int, Result>|\Illuminate\Database\Eloquent\Collection<int, Result>  $results
     * @return Collection<int, Result>
     */
    private function leafFirst(Collection $results): Collection
    {
        $parentOf = $results->pluck('parent_id', 'id')->all();

        $depth = function (int $id) use (&$depth, $parentOf): int {
            $parentId = $parentOf[$id] ?? null;

            return $parentId === null || ! array_key_exists($parentId, $parentOf) ? 0 : 1 + $depth((int) $parentId);
        };

        return $results->sortByDesc(fn (Result $result): int => $depth($result->id))->values();
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $messageKey): never
    {
        throw ValidationException::withMessages(['proposal' => __($messageKey)]);
    }
}
