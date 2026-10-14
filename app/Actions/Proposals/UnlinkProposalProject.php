<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Models\PlanIndicator;
use App\Models\Proposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Unlink a project from a proposal.
 *
 * The project is loaded only through the proposal's own links, so a project
 * the proposal does not fund is "not found". The proposal row is locked and
 * its links recounted, so two people never unlink the last two projects at
 * once. A project carrying targets of this proposal that already have
 * reports stays linked; targets without reports stay too and are flagged on
 * the targets tab.
 */
final class UnlinkProposalProject
{
    public function handle(Proposal $proposal, int $projectId): void
    {
        DB::transaction(function () use ($proposal, $projectId): void {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('update', $locked);

            $project = $locked->projects()->whereKey($projectId)->firstOrFail(['projects.id', 'projects.name']);

            if ($locked->projects()->count() <= 1) {
                throw ValidationException::withMessages(['project' => __('proposals.the_last_linked_project_cannot_be_unlinked')]);
            }

            $hasReportedTargets = PlanIndicator::query()
                ->where('source_type', $locked->getMorphClass())
                ->where('source_id', $locked->id)
                ->where('project_id', $project->id)
                ->has('reports')
                ->exists();

            if ($hasReportedTargets) {
                throw ValidationException::withMessages(['project' => __('proposals.this_project_has_reported_targets_in_this_proposal')]);
            }

            $locked->projects()->detach($project->id);

            $locked->recordActivity('proposal.project_unlinked', properties: [
                'project_id' => $project->id,
                'project' => $project->name,
            ]);
        });

        $proposal->unsetRelation('projects');
    }
}
