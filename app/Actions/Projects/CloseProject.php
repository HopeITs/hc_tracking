<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Close an active project (contracts.md §3: active → closed, dates kept).
 *
 * The row is locked and its status re-read inside the transaction, so a
 * second close of the same project finds it closed and refuses: one change
 * is written. Authorization runs on the locked row (`ProjectPolicy::update`:
 * a projects.update holder, the owner or the program manager; never an
 * owner-rejected project).
 */
final class CloseProject
{
    /**
     * @throws ValidationException when the project is not active
     */
    public function handle(Project $project): void
    {
        DB::transaction(function () use ($project): void {
            $locked = Project::query()->with('ownerAssignment')->whereKey($project->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('update', $locked);

            if ($locked->status !== ProjectStatus::Active) {
                throw ValidationException::withMessages(['status' => __('projects.close_only_active')]);
            }

            $locked->status = ProjectStatus::Closed;
            $locked->save();
        });

        $project->refresh();
    }
}
