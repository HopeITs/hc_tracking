<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Enums\NotificationPriority;
use App\Models\Activity;

/**
 * Everything one module knows about who hears about its activity. The
 * resolver core stays module-agnostic; each module overrides only what it has.
 */
abstract class ModuleRecipients
{
    /**
     * HR employees named on the record.
     *
     * @return list<int>
     */
    public function directEmployeeIds(Activity $activity): array
    {
        return [];
    }

    /**
     * Tracking users named on the record (creator, author, affected user).
     *
     * @return list<int>
     */
    public function directUserIds(Activity $activity): array
    {
        return [];
    }

    /**
     * Employees this activity assigns something to: important for them, and
     * the actor is warned about any who have no account.
     *
     * @return list<int>
     */
    public function assignedEmployeeIds(Activity $activity): array
    {
        return [];
    }

    /**
     * Roles that watch this activity. A user with any of them receives it.
     *
     * @return list<string>
     */
    public function watcherRoles(Activity $activity): array
    {
        return [];
    }

    /**
     * ★ — also sent to the top roles.
     */
    public function isKeyEvent(Activity $activity): bool
    {
        return false;
    }

    /**
     * Priority for every recipient; being assigned raises it for that person.
     */
    public function priority(Activity $activity): NotificationPriority
    {
        return $this->isKeyEvent($activity) ? NotificationPriority::Important : NotificationPriority::Info;
    }

    /**
     * Users who skip the view-permission check for this activity.
     *
     * @return list<int>
     */
    public function permissionExemptUserIds(Activity $activity): array
    {
        return [];
    }

    /**
     * The value a field was changed to, or null when it did not change.
     */
    protected function changedTo(Activity $activity, string $field): mixed
    {
        return $activity->changes[$field][1] ?? null;
    }

    /**
     * The value a field was changed from, or null when it did not change.
     */
    protected function changedFrom(Activity $activity, string $field): mixed
    {
        return $activity->changes[$field][0] ?? null;
    }

    /**
     * Integer ids from a property that may hold one id or a list.
     *
     * @return list<int>
     */
    protected function idsFromProperty(Activity $activity, string $key): array
    {
        $value = $activity->properties[$key] ?? [];

        return array_values(array_map('intval', array_filter(is_array($value) ? $value : [$value])));
    }

    /**
     * Distinct, non-empty integer ids.
     *
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    protected function uniqueIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids))));
    }
}
