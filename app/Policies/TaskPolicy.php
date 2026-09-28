<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Scopes\TaskVisibilityScope;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('tasks.view');
    }

    /**
     * Determine whether the user can view the model.
     *
     * The module permission, plus the task department rule — decided in one
     * place, TaskVisibilityScope, which every task query also goes through.
     */
    public function view(User $user, Task $task): bool
    {
        return $user->can('tasks.view') && TaskVisibilityScope::allows($user, $task);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('tasks.create');
    }

    /**
     * Determine whether the user can edit every field of the task: holders of
     * tasks.update, and the task's own creator. Which tasks a department-
     * scoped user can reach at all is decided by TaskVisibilityScope when the
     * task is loaded.
     */
    public function update(User $user, Task $task): bool
    {
        return $user->can('tasks.update') || $this->isCreator($user, $task);
    }

    /**
     * Determine whether the user can move the task's status and progress:
     * everyone who may edit it, plus the people it is assigned to.
     */
    public function updateStatus(User $user, Task $task): bool
    {
        return $this->update($user, $task) || $this->isAssignee($user, $task);
    }

    /**
     * Determine whether the user can delete the model: holders of
     * tasks.delete, and the task's own creator.
     */
    public function delete(User $user, Task $task): bool
    {
        return $user->can('tasks.delete') || $this->isCreator($user, $task);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Task $task): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Task $task): bool
    {
        return false;
    }

    /**
     * Whether the user raised this task.
     */
    private function isCreator(User $user, Task $task): bool
    {
        return $task->created_by === $user->id;
    }

    /**
     * Whether the task is assigned to the user's HR employee. Uses the loaded
     * assignees when a list already eager-loaded them.
     */
    private function isAssignee(User $user, Task $task): bool
    {
        if ($user->employee_id === null) {
            return false;
        }

        return $task->relationLoaded('taskAssignees')
            ? $task->taskAssignees->contains('employee_id', $user->employee_id)
            : $task->taskAssignees()->where('employee_id', $user->employee_id)->exists();
    }
}
