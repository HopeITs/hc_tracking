<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Reopen a closed project as a draft when a new proposal links it
 * (contracts.md §3: closed → draft, dates cleared).
 *
 * Called inside the SaveProposal / LinkProposalProject transaction, so its
 * own transaction is a savepoint. The row is re-locked and re-read: a
 * project another linker already reopened is left as it is, so the second
 * of two concurrent links simply links. The status change is saved through
 * the model, so the Activity Log keeps the old status and dates.
 *
 * A project without a center (legacy, unmapped) skips the one-open-project
 * check and is still reopened (T06 plan Q5).
 */
final class ReopenProjectAsDraft
{
    public function handle(Project $project): void
    {
        DB::transaction(function () use ($project): void {
            $locked = Project::query()->with('ownerAssignment')->whereKey($project->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('reopenAsDraft', $locked);

            if ($locked->status === ProjectStatus::Draft) {
                return;
            }

            if ($locked->status !== ProjectStatus::Closed) {
                throw ValidationException::withMessages(['projectIds' => __('proposals.that_project_is_no_longer_available')]);
            }

            if ($locked->center_id !== null) {
                $open = Project::openProjectFor($locked->center_id, $locked->program_id, $locked->id);

                if ($open !== null) {
                    throw ValidationException::withMessages(['projectIds' => __('projects.open_project_exists', ['name' => $open->name])]);
                }
            }

            $locked->status = ProjectStatus::Draft;
            $locked->start_date = null;
            $locked->end_date = null;
            $locked->save();
        });

        $project->refresh();
    }
}
