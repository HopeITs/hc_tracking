<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\User;

/**
 * Users & roles: the affected user is direct and hears about their own
 * account even without user-management permission; Super Admins watch.
 */
final class UserRecipients extends ModuleRecipients
{
    public function directUserIds(Activity $activity): array
    {
        return User::query()->whereKey($activity->subject_id)->exists() ? [(int) $activity->subject_id] : [];
    }

    public function permissionExemptUserIds(Activity $activity): array
    {
        return [(int) $activity->subject_id];
    }

    public function watcherRoles(Activity $activity): array
    {
        return ['Super Admin'];
    }
}
