<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use App\Notifications\RecipientFilter;
use App\Notifications\RecipientResolver;

/**
 * The shared delivery path of every scheduled alert: filter, claim the
 * ledger stage per person, notify.
 */
final class AlertDispatcher
{
    public function __construct(
        private RecipientFilter $filter,
        private RecipientResolver $resolver,
        private AlertLedger $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @param  list<array<string, mixed>>  $actions
     */
    public function payload(string $module, NotificationPriority $priority, string $key, array $params, string $subjectType, int $subjectId, array $actions = []): NotificationPayload
    {
        return new NotificationPayload(
            kind: NotificationKind::Alert,
            module: $module,
            priority: $priority,
            key: $key,
            params: $params,
            subjectType: $subjectType,
            subjectId: $subjectId,
            actions: $actions,
        );
    }

    /**
     * @param  array<int, int>  $directUserIds
     * @param  array<int, int>  $otherUserIds
     * @param  array<int, int>  $exemptUserIds
     */
    public function send(string $rule, string $stage, NotificationPayload $payload, array $directUserIds, array $otherUserIds = [], array $exemptUserIds = []): int
    {
        $recipients = $this->filter->apply([...$directUserIds, ...$otherUserIds], $payload->module, null, $directUserIds, $exemptUserIds);
        $sent = 0;

        foreach (User::query()->whereIn('id', $recipients)->get() as $user) {
            if (! $this->ledger->claim($rule, (string) $payload->subjectType, (int) $payload->subjectId, $stage, $user->id)) {
                continue;
            }

            $user->notify(new AppNotification($payload));
            $sent++;
        }

        return $sent;
    }

    /**
     * Direct people when the item names any, otherwise its watcher roles.
     */
    public function sendForItem(string $rule, string $stage, NotificationPayload $payload, DeadlineItem $item): int
    {
        return $item->directUserIds !== []
            ? $this->send($rule, $stage, $payload, $item->directUserIds)
            : $this->send($rule, $stage, $payload, [], $this->resolver->usersWithRoles($item->watcherRoles));
    }
}
