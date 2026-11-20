<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Actions\Targets\ClearLocationTargets;
use App\Models\Proposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Delete and unlink" (Y-10): delete the targets of the project's location row
 * of this proposal, then unlink the project, in one transaction. Either both
 * happen or neither does (the last linked project, a reported target).
 *
 * - Who: may update the proposal AND holds `targets.delete`.
 * - Targets go through ClearLocationTargets (a reported one refuses), the
 *   unlink through UnlinkProposalProject (its signature is frozen, contracts §6).
 *
 * Error keys: project, targets.
 */
final class UnlinkProjectWithTargets
{
    /**
     * @throws ValidationException
     */
    public function handle(Proposal $proposal, int $projectId): void
    {
        Gate::authorize('update', $proposal);
        Gate::authorize('targets.delete');

        DB::transaction(function () use ($proposal, $projectId): void {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();
            $project = $locked->projects()->whereKey($projectId)->firstOrFail(['projects.id']);

            foreach ($locked->locations()->where('project_id', $project->id)->get() as $row) {
                app(ClearLocationTargets::class)->handle($locked, $row->id);
            }

            app(UnlinkProposalProject::class)->handle($locked, $project->id);
        });
    }
}
