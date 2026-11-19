<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Events\ProposalLocationsChanged;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Support\ProposalLocationRows;
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
 * reports stays linked. Since Y-10 a target belongs to the project's location
 * row, which goes with the link: when that row carries targets the unlink is
 * refused (UnlinkProjectWithTargets deletes them first, for a holder of
 * `targets.delete`). Targets of a project with no row (data from before the
 * rows) stay and are flagged on the targets tab, as before.
 *
 * Since W-34a the project's location row goes with the link (contracts.md
 * §7), and ProposalLocationsChanged fires after commit.
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

            // Y-10: the project's location row goes with the link, and a row that
            // carries targets is never removed on its own. The dialog's "delete
            // and unlink" clears them first (UnlinkProjectWithTargets).
            if ($locked->locations()->where('project_id', $project->id)->whereHas('targets')->exists()) {
                throw ValidationException::withMessages(['project' => __('proposals.location_has_targets')]);
            }

            $locked->projects()->detach($project->id);

            $locked->recordActivity('proposal.project_unlinked', properties: [
                'project_id' => $project->id,
                'project' => $project->name,
            ]);

            $counts = app(ProposalLocationRows::class)->detachProject($locked, $project->id);

            if ($counts['removed'] > 0) {
                $locked->recordActivity('proposal.locations_changed', [], $counts);
                ProposalLocationsChanged::dispatch($locked->id, auth()->user()?->id, $counts['added'], $counts['removed'], $counts['changed']);
            }
        });

        $proposal->unsetRelation('projects');
        $proposal->unsetRelation('locations');
    }
}
