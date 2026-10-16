<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\NarrativeQuestion;
use App\Models\User;

/**
 * The narrative question bank is MEAL's own (narrative-questions.manage,
 * contracts.md §8: kept from the General Manager; the Super Admin holds every
 * permission). There is no view permission: reading the bank needs manage
 * too. Deactivating, reactivating and moving a question are edits.
 */
class NarrativeQuestionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('narrative-questions.manage');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, NarrativeQuestion $narrativeQuestion): bool
    {
        return $user->can('narrative-questions.manage');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('narrative-questions.manage');
    }

    /**
     * Determine whether the user can update, deactivate, reactivate or move the model.
     */
    public function update(User $user, NarrativeQuestion $narrativeQuestion): bool
    {
        return $user->can('narrative-questions.manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, NarrativeQuestion $narrativeQuestion): bool
    {
        return $user->can('narrative-questions.manage');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, NarrativeQuestion $narrativeQuestion): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, NarrativeQuestion $narrativeQuestion): bool
    {
        return false;
    }
}
