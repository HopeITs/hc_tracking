<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\HrDepartment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Department-scoped task visibility, shared by the tasks index and show
 * pages so a task is hidden from a viewer in exactly one place.
 *
 * Only the named team roles (config('access-control.task_department_scoped_
 * roles')) are hard-scoped to their own department at all times — there is
 * no filter to widen it, matching the "Media team only sees Media tasks"
 * policy. Bypass roles (task_department_scope_bypass_roles) see every
 * department with a filter to narrow down; an account holding neither kind
 * of role (elevated, or none at all) sees every department, unfiltered.
 * "My Tasks" (assigned to or created by the viewer) is a separate concern
 * layered on top by the caller, not part of this scope.
 */
trait ManagesTaskVisibility
{
    /**
     * Constrain a tasks query to one HR department: any task with an
     * assignee in that department, or created by a tracking user whose own
     * current department matches — so a task raised by one department but
     * handed to another still shows up on both sides.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    protected function scopeTasksToDepartment(Builder $query, int $departmentId): Builder
    {
        $employeeIds = HrDepartment::employeeIdsInDepartment($departmentId);
        $creatorIds = User::query()->whereIn('employee_id', $employeeIds)->pluck('id');

        return $query->where(fn (Builder $query) => $query
            ->whereHas('taskAssignees', fn (Builder $query) => $query->whereIn('employee_id', $employeeIds))
            ->orWhereIn('created_by', $creatorIds));
    }

    /**
     * Apply this viewer's department scope: nothing unless they hold one of
     * the named team roles, in which case it's an unconditional constraint
     * to their own department (a scoped viewer with no resolvable
     * department — role held but no linked HR position — sees nothing,
     * rather than silently falling back to "everything").
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    protected function applyViewerDepartmentScope(Builder $query): Builder
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->isTaskDepartmentScoped()) {
            return $query;
        }

        $departmentId = $user->departmentId();

        if ($departmentId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $this->scopeTasksToDepartment($query, $departmentId);
    }
}
