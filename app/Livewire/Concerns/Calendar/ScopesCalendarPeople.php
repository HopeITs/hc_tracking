<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Calendar;

/**
 * Whose items the calendar shows: the employees checked in the responsible
 * filter, or the signed-in user's own HR employee record when none are, and
 * who a new milestone or task defaults to.
 *
 * Used by calendar/index only. The page provides the #[Url]
 * filterEmployeeIds property.
 *
 * @property array<int, string> $filterEmployeeIds
 */
trait ScopesCalendarPeople
{
    /**
     * The employee record for the signed-in user, if their account is linked
     * to one. `Milestone::responsible_user_id` holds an HR employee id, not a
     * `users.id`, so this is the bridge between "me" and "my milestones".
     */
    protected function employeeId(): ?int
    {
        return auth()->user()->employee_id;
    }

    /**
     * The employees whose items the calendar shows: everyone checked in the
     * responsible-user filter, defaulting to just the signed-in user's own
     * employee record when nothing is checked.
     *
     * @return array<int, int>
     */
    protected function scopedEmployeeIds(): array
    {
        if ($this->filterEmployeeIds !== []) {
            return array_map('intval', $this->filterEmployeeIds);
        }

        $employeeId = $this->employeeId();

        return $employeeId === null ? [] : [$employeeId];
    }

    /**
     * The employee a newly created milestone/task defaults to: the one
     * employee the filter is narrowed to, or the signed-in user's own record
     * when the filter covers several people (or none) and there is no single
     * obvious owner to default to.
     */
    protected function defaultResponsibleEmployeeId(): ?int
    {
        if (count($this->filterEmployeeIds) === 1) {
            return (int) $this->filterEmployeeIds[0];
        }

        return $this->employeeId();
    }
}
