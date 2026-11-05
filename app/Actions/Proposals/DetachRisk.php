<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Models\Proposal;
use App\Models\Risk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Remove a risk from a proposal's logframe (contracts.md §6, W-09). The risk
 * is loaded through the proposal, so another proposal's risk or a legacy
 * project risk is never reachable; the library entry is untouched.
 */
final class DetachRisk
{
    public function handle(Proposal $proposal, int $riskId): void
    {
        Gate::authorize('detach', [Risk::class, $proposal]);

        DB::transaction(function () use ($proposal, $riskId): void {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('detach', [Risk::class, $locked]);

            $risk = $locked->risks()->whereKey($riskId)->lockForUpdate()->firstOrFail();
            $name = $risk->name;
            $resultStatement = $risk->result()->value('statement');

            $risk->delete();

            $locked->recordActivity('proposal.risk_detached', properties: [
                'value' => $name,
                'result' => $resultStatement,
            ]);
        });
    }
}
