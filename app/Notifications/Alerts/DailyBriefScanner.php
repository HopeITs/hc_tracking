<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\UserNotification;
use App\Notifications\NotificationPayload;
use Carbon\CarbonImmutable;

/**
 * Each person's morning summary of their own items. Sent only when there is
 * something in it.
 */
final class DailyBriefScanner implements Scanner
{
    public function __construct(
        private DeadlineCatalog $catalog,
        private StalledScanner $stalled,
        private WorkingCalendar $calendar,
        private AlertDispatcher $dispatcher,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $today = $now->startOfDay();

        if (! $this->calendar->isWorkingDay($today)) {
            return 0;
        }

        $empty = ['due_today' => 0, 'overdue' => 0, 'stalled' => 0, 'assigned' => 0];
        $counts = [];

        foreach ($this->catalog->items($today) as $item) {
            $bucket = $item->dueDate->isSameDay($today) ? 'due_today' : 'overdue';

            foreach ($item->directUserIds as $userId) {
                $counts[$userId] ??= $empty;
                $counts[$userId][$bucket]++;
            }
        }

        foreach ($this->stalled->stalledSubjects($today) as $stall) {
            foreach ($stall['userIds'] as $userId) {
                $counts[$userId] ??= $empty;
                $counts[$userId]['stalled']++;
            }
        }

        UserNotification::query()
            ->where('kind', NotificationKind::Action->value)
            ->where('created_at', '>=', $now->subDay())
            ->where('data->key', 'like', '%\_you')
            ->toBase()
            ->selectRaw('notifiable_id, count(*) as aggregate')
            ->groupBy('notifiable_id')
            ->pluck('aggregate', 'notifiable_id')
            ->each(function (int|string $count, int|string $userId) use (&$counts, $empty): void {
                $counts[(int) $userId] ??= $empty;
                $counts[(int) $userId]['assigned'] = (int) $count;
            });

        $sent = 0;

        foreach ($counts as $userId => $count) {
            if (array_sum($count) === 0) {
                continue;
            }

            $payload = new NotificationPayload(
                kind: NotificationKind::Digest,
                module: 'system',
                priority: NotificationPriority::Info,
                key: 'notifications.digests.daily',
                params: array_map('strval', $count),
                subjectType: 'user',
                subjectId: $userId,
                link: ['route' => 'dashboard'],
            );

            $sent += $this->dispatcher->send('daily_brief', $today->toDateString(), $payload, [$userId], [], [$userId]);
        }

        return $sent;
    }
}
