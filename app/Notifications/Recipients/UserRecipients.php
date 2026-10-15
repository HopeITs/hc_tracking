<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\User;
use App\Notifications\EmployeeRecipients;

/**
 * Users & roles: the affected user is direct and hears about their own
 * account even without user-management permission; Super Admins watch.
 * A role change (subject "role") has no affected user — only the watchers.
 *
 * A moved MEAL Manager slot (notification_settings.meal_manager_changed, T07)
 * is recorded on the admin's account and tells the previous and the new
 * holder, who need no user-management permission for it.
 */
final class UserRecipients extends ModuleRecipients
{
    private const MealManagerChanged = 'notification_settings.meal_manager_changed';

    public function directEmployeeIds(Activity $activity): array
    {
        return $activity->event === self::MealManagerChanged ? $this->slotHolderIds($activity) : [];
    }

    public function directUserIds(Activity $activity): array
    {
        if ($activity->subject_type !== 'user') {
            return [];
        }

        return User::query()->whereKey($activity->subject_id)->exists() ? [(int) $activity->subject_id] : [];
    }

    public function permissionExemptUserIds(Activity $activity): array
    {
        $own = $activity->subject_type === 'user' ? [(int) $activity->subject_id] : [];

        if ($activity->event !== self::MealManagerChanged) {
            return $own;
        }

        return $this->uniqueIds([...$own, ...array_values(app(EmployeeRecipients::class)->usersFor($this->slotHolderIds($activity)))]);
    }

    public function watcherRoles(Activity $activity): array
    {
        return ['Super Admin'];
    }

    /**
     * @return list<int>
     */
    private function slotHolderIds(Activity $activity): array
    {
        return $this->uniqueIds([...$this->idsFromProperty($activity, 'employee_id'), ...$this->idsFromProperty($activity, 'previous_employee_id')]);
    }
}
