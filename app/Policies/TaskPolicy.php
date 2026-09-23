<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HrDepartment;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * Only the named team roles (config('access-control.
     * task_department_scoped_roles')) are limited to their own department (a
     * task is "in" a department when any of its assignees, or its creator,
     * is) — everyone else (an elevated bypass role, or an account with
     * neither kind of role) may view any task, matching the tasks page's
     * long-standing "any authenticated user" access before departments
     * existed. A scoped viewer can always see a task they created or are
     * assigned to themselves — the "My Tasks" case — even if its other
     * assignees sit in another department.
     */
    public function view(User $user, Task $task): bool
    {
        if (! $user->isTaskDepartmentScoped()) {
            return true;
        }

        if ($task->created_by === $user->id) {
            return true;
        }

        $assigneeIds = $task->taskAssignees->pluck('employee_id');

        if ($user->employee_id !== null && $assigneeIds->contains($user->employee_id)) {
            return true;
        }

        $departmentId = $user->departmentId();

        if ($departmentId === null) {
            return false;
        }

        $departmentEmployeeIds = HrDepartment::employeeIdsInDepartment($departmentId);

        if ($assigneeIds->intersect($departmentEmployeeIds)->isNotEmpty()) {
            return true;
        }

        $creator = $task->creator;

        return $creator !== null && $creator->departmentId() === $departmentId;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('tasks.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Task $task): bool
    {
        return $user->can('tasks.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Task $task): bool
    {
        return $user->can('tasks.delete');
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
}
