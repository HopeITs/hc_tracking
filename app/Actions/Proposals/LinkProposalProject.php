<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Actions\Projects\ReopenProjectAsDraft;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Proposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Link a project to a proposal, from either the proposal or the project page.
 *
 * The project is loaded through Project::linkableToProposals(), the same
 * query every picker uses, so an archived, owner-rejected, missing or
 * tampered id is refused with one message. A closed project is reopened as
 * a draft in the same transaction. Linking a project already on the
 * proposal changes nothing, its beneficiaries included.
 *
 * Pivot writes fire no model events, so the link is recorded explicitly,
 * with the project's name and never an amount.
 */
final class LinkProposalProject
{
    public function handle(Proposal $proposal, int $projectId, ?int $targetBeneficiaries = null): void
    {
        DB::transaction(function () use ($proposal, $projectId, $targetBeneficiaries): void {
            $locked = Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('update', $locked);

            if ($targetBeneficiaries !== null && $targetBeneficiaries < 1) {
                throw ValidationException::withMessages(['beneficiaries' => __('proposals.enter_at_least_one_beneficiary')]);
            }

            $project = Project::query()->linkableToProposals()->whereKey($projectId)->lockForUpdate()->first();

            if ($project === null) {
                throw ValidationException::withMessages(['projectIds' => __('proposals.that_project_is_no_longer_available')]);
            }

            Gate::authorize('view', $project);

            if ($locked->projects()->whereKey($project->id)->exists()) {
                return;
            }

            if ($project->status === ProjectStatus::Closed) {
                app(ReopenProjectAsDraft::class)->handle($project);
            }

            $locked->projects()->attach($project->id, ['target_beneficiaries' => $targetBeneficiaries]);

            $locked->recordActivity('proposal.project_linked', properties: [
                'project_id' => $project->id,
                'project' => $project->name,
            ]);
        });

        $proposal->unsetRelation('projects');
    }
}
