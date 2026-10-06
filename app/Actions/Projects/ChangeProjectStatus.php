<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Move a project to another status from its detail page. Archiving and
 * restoring need delete rights on top of edit rights, and a project only leaves
 * Draft (or an archive made from Draft) once its owner has acknowledged being in charge.
 */
class ChangeProjectStatus
{
    /**
     * @throws ValidationException when a draft's owner has not acknowledged yet
     */
    public function handle(Project $project, ProjectStatus $target): void
    {
        Gate::authorize('update', $project);

        if ($project->status === $target) {
            return;
        }

        if ($target === ProjectStatus::Archived || $project->status === ProjectStatus::Archived) {
            Gate::authorize('delete', $project);
        }

        $leavesDraft = in_array($project->status, [ProjectStatus::Draft, ProjectStatus::Archived], true)
            && ! in_array($target, [ProjectStatus::Draft, ProjectStatus::Archived], true);

        if ($leavesDraft && ! $project->isAssignmentAcknowledged('user_id')) {
            throw ValidationException::withMessages(['status' => __('projects.owner_must_acknowledge_first')]);
        }

        $project->update(['status' => $target]);
    }
}
