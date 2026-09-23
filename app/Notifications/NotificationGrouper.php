<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationPriority;
use App\Models\User;
use App\Models\UserNotification;

/**
 * Merge-or-create: an unread notification for the same actor and record,
 * touched within the grouping window, absorbs the new event instead of a new
 * row appearing. The loudest event's sentence stays the headline.
 */
final class NotificationGrouper
{
    /**
     * @return array{0: UserNotification, 1: bool} the row, and whether it was merged
     */
    public function store(User $user, NotificationPayload $payload, string $id): array
    {
        $existing = $payload->groupKey === null ? null : UserNotification::query()
            ->forUser($user)
            ->where('group_key', $payload->groupKey)
            ->whereNull('read_at')
            ->where('created_at', '>=', now()->subMinutes((int) config('notifications.grouping_window_minutes')))
            ->latest()
            ->first();

        if ($existing !== null) {
            return [$this->merge($existing, $payload), true];
        }

        return [UserNotification::query()->create([
            'id' => $id,
            'type' => AppNotification::class,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
            'data' => [
                'key' => $payload->key,
                'params' => $payload->params,
                'count' => 1,
                'activity_ids' => $payload->activityId === null ? [] : [$payload->activityId],
                'link' => $payload->link,
                'actions' => $payload->actions,
            ],
            'priority' => $payload->priority,
            'module' => $payload->module,
            'kind' => $payload->kind,
            'subject_type' => $payload->subjectType,
            'subject_id' => $payload->subjectId,
            'actor_id' => $payload->actorId,
            'activity_id' => $payload->activityId,
            'group_key' => $payload->groupKey,
        ]), false];
    }

    private function merge(UserNotification $existing, NotificationPayload $payload): UserNotification
    {
        $data = $existing->data;

        if ($payload->priority->rank() >= $existing->priority->rank()) {
            $data['key'] = $payload->key;
            $data['params'] = $payload->params;
            $data['actions'] = $payload->actions;
        }

        $data['count'] = (int) ($data['count'] ?? 1) + 1;
        $data['activity_ids'] = [...($data['activity_ids'] ?? []), ...($payload->activityId === null ? [] : [$payload->activityId])];

        $existing->forceFill([
            'data' => $data,
            'priority' => NotificationPriority::highest($existing->priority, $payload->priority),
            'activity_id' => $payload->activityId ?? $existing->activity_id,
            'created_at' => now(),
        ])->save();

        return $existing;
    }
}
