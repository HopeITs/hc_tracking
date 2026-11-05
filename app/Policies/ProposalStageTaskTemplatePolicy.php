<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProposalStageTaskTemplate;
use App\Models\User;

/**
 * Stage task templates are the PR Manager's list (`stage-task-templates.manage`,
 * contracts.md §8, D-63). There is no separate view permission: seeing the
 * list and changing it both need the same one. Deactivating and reordering
 * are edits.
 *
 * A template the system has already used is deactivated rather than deleted,
 * which the admin page enforces (W-12 slice 2); the rule lives there because
 * it depends on the rows pointing at the template, not on the actor.
 */
class ProposalStageTaskTemplatePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('stage-task-templates.manage');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ProposalStageTaskTemplate $template): bool
    {
        return $user->can('stage-task-templates.manage');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('stage-task-templates.manage');
    }

    /**
     * Determine whether the user can update, deactivate or reorder the model.
     */
    public function update(User $user, ProposalStageTaskTemplate $template): bool
    {
        return $user->can('stage-task-templates.manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ProposalStageTaskTemplate $template): bool
    {
        return $user->can('stage-task-templates.manage');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ProposalStageTaskTemplate $template): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ProposalStageTaskTemplate $template): bool
    {
        return false;
    }
}
