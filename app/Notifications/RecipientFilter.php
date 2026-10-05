<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\NotificationMute;
use App\Models\Permission;
use App\Models\User;

/**
 * The removals every notification goes through: the actor, anyone lacking the
 * module's view permission, and anyone who muted the module (unless they are
 * a direct recipient).
 */
final class RecipientFilter
{
    /**
     * @param  array<int, int>  $candidateIds
     * @param  array<int, int>  $directIds  never removed by a mute
     * @param  array<int, int>  $exemptIds  skip the permission check (their own account, digests)
     * @return list<int>
     */
    public function apply(array $candidateIds, string $module, ?int $actorId, array $directIds = [], array $exemptIds = []): array
    {
        $ids = array_values(array_unique(array_diff($candidateIds, $actorId === null ? [] : [$actorId])));

        if ($ids === []) {
            return [];
        }

        $allowed = array_values(array_unique([
            ...$this->withViewPermission($ids, $module),
            ...array_intersect($ids, $exemptIds),
        ]));

        if ($allowed === []) {
            return [];
        }

        $muted = NotificationMute::query()
            ->where('module', $module)
            ->whereIn('user_id', $allowed)
            ->pluck('user_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $kept = array_values(array_filter($allowed, fn (int $id): bool => in_array($id, $directIds, true) || ! in_array($id, $muted, true)));

        sort($kept);

        return $kept;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function withViewPermission(array $ids, string $module): array
    {
        $permission = config("notifications.view_permissions.{$module}", "{$module}.view");

        if (! Permission::query()->where('name', $permission)->exists()) {
            return [];
        }

        return array_values(User::permission($permission)
            ->whereIn('users.id', $ids)
            ->pluck('users.id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all());
    }
}
