<?php

declare(strict_types=1);

namespace App\Actions\Risks;

use App\Models\Program;
use App\Models\Proposal;
use App\Models\Risk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Remove a risk from a program's results framework or a proposal's logframe
 * (a copied risk can only be removed). Loaded through the owner, so another
 * owner's risk is never reachable; the library entry is untouched.
 */
final class DeleteFrameworkRisk
{
    public function handle(Program|Proposal $owner, int $riskId): void
    {
        Gate::authorize('manageFramework', [Risk::class, $owner]);

        DB::transaction(function () use ($owner, $riskId): void {
            $locked = $owner::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('manageFramework', [Risk::class, $locked]);

            $risk = ($locked instanceof Program
                ? Risk::query()->onProgram($locked->id)
                : Risk::query()->onLogframeOf($locked->id))
                ->whereKey($riskId)->lockForUpdate()->firstOrFail();

            $name = $risk->name;

            $risk->delete();

            if ($locked instanceof Proposal) {
                $locked->recordActivity('proposal.risk_detached', properties: ['value' => $name]);
            }
        });
    }
}
