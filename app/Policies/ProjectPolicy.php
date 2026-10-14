<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Besides holders of the projects.* permissions, a project's owner and its
 * program's manager (see User::ownedProjectIds()) edit the projects they
 * answer for — its details, files, linked proposals and schedule. Archiving
 * and restoring (projects are never deleted) and reassigning a project to
 * another program or owner stay with projects.* holders.
 */
class ProjectPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('projects.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Project $project): bool
    {
        return $user->can('projects.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('projects.create');
    }

    /**
     * Determine whether the user can edit the project. Nobody can once its
     * owner has rejected the assignment.
     */
    public function update(User $user, Project $project): bool
    {
        if ($project->isOwnerRejected()) {
            return false;
        }

        return $user->can('projects.update') || in_array($project->id, $user->ownedProjectIds(), true);
    }

    /**
     * Determine whether the user can move the project to another program or
     * owner: a management decision, not an owner's. Locked for everyone once
     * the owner has rejected the assignment.
     */
    public function reassign(User $user, Project $project): bool
    {
        return ! $project->isOwnerRejected() && $user->can('projects.update');
    }

    /**
     * Determine whether the user can reopen a closed project as a draft by
     * linking it to a proposal (contracts.md §3). The proposal side decides,
     * so this follows proposals.update, never projects.update.
     */
    public function reopenAsDraft(User $user, Project $project): bool
    {
        return ! $project->isOwnerRejected() && $user->can('proposals.update');
    }

    /**
     * Determine whether the user can archive or restore the project.
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->can('projects.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Project $project): bool
    {
        return false;
    }
}
