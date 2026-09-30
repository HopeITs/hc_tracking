<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Move a proposal to a new status and record why.
 *
 * Both writes happen in one transaction. The live status and its audit trail
 * share one vocabulary, so they must never be allowed to disagree.
 */
class ChangeProposalStatus
{
    public function handle(Proposal $proposal, ProposalStatus|string $status, string $context, string $date): void
    {
        Gate::authorize('update', $proposal);

        $status = $status instanceof ProposalStatus ? $status : ProposalStatus::from($status);

        DB::transaction(function () use ($proposal, $status, $context, $date): void {
            $proposal->fill(['status' => $status])->save();

            ProposalStatusNote::query()->create([
                'proposal_id' => $proposal->id,
                'status' => $status,
                'context' => $context,
                'date' => $date,
            ]);
        });
    }
}
