<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\HrDepartment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The tasks page's department FILTER: lets a user who sees every department
 * narrow the list down to one.
 *
 * It is not what hides tasks from department-scoped viewers — that rule lives
 * in App\Models\Scopes\TaskVisibilityScope and applies to every task query in
 * the application, so no page can forget it.
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
}
