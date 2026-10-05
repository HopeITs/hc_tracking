<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Models\UserNotification;

/**
 * Which records have unread notifications for a user, for the dots on list
 * rows and record headers. One query per list.
 */
final class UnreadSubjects
{
    /**
     * @return list<int>
     */
    public function ids(User $user, string $subjectType): array
    {
        return array_values(UserNotification::query()
            ->forUser($user)
            ->visible()
            ->whereNull('read_at')
            ->where('subject_type', $subjectType)
            ->distinct()
            ->orderBy('subject_id')
            ->pluck('subject_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all());
    }

    public function has(User $user, string $subjectType, int $subjectId): bool
    {
        return UserNotification::query()
            ->forUser($user)
            ->visible()
            ->whereNull('read_at')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->exists();
    }
}
