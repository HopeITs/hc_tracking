<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Calendar;

use App\Models\HrEmployee;

/**
 * Whose items the calendar shows: the employees checked in the responsible
 * filter, or the signed-in user's own HR employee record when none are, and
 * who a new task defaults to.
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
     * to one. Task assignees hold HR employee ids, not
     * `users.id`, so this is the bridge between "me" and "my tasks".
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
     * The employee a newly created task defaults to: the one
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

    /**
     * The page heading, naming whose calendar is on screen: "My Calendar"
     * for the viewer's own, the person's name for one other person, and a
     * head count for several.
     */
    protected function calendarTitle(): string
    {
        $picked = array_map('intval', $this->filterEmployeeIds);

        if ($picked === [] || $picked === [$this->employeeId()]) {
            return __('common.my_calendar');
        }

        if (count($picked) === 1) {
            $name = HrEmployee::pickerOptions()->firstWhere('id', $picked[0])?->name;

            if ($name !== null && $name !== '') {
                return __('calendar.calendar_of', ['name' => $name]);
            }
        }

        return trans_choice('calendar.calendar_of_people', count($picked), ['count' => count($picked)]);
    }
}
