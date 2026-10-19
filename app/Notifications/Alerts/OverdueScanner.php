<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use App\Notifications\EventCatalog;
use App\Notifications\Recipients\OversightRecipients;
use Carbon\CarbonImmutable;

/**
 * "Overdue": day 1, then every N working days (default 3) until done. A
 * scanner-driven catalog event (`<eventKey>.overdue`, e.g. a department task)
 * also tells the people who hear only once it is late (its requester) and
 * its oversight, and sends nothing while an admin has it switched off.
 */
final class OverdueScanner implements Scanner
{
    public function __construct(
        private DeadlineCatalog $catalog,
        private WorkingCalendar $calendar,
        private NotificationSettings $settings,
        private AlertDispatcher $dispatcher,
        private EventCatalog $events,
        private OversightRecipients $oversight,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $today = $now->startOfDay();

        if (! $this->calendar->isWorkingDay($today)) {
            return 0;
        }

        $repeat = max(1, (int) $this->settings->get('overdue_repeat_days'));
        $sent = 0;

        foreach ($this->catalog->items($today->subDay()) as $item) {
            $days = $this->calendar->workingDaysBetween($item->dueDate, $today);

            if ($days < 1) {
                continue;
            }

            $mark = 1 + intdiv($days - 1, $repeat) * $repeat;

            $payload = $this->dispatcher->payload(
                $item->module,
                NotificationPriority::Important,
                'notifications.alerts.overdue.'.($days === 1 ? 'one' : 'many'),
                ['subject' => $item->label, 'days' => (string) $days, 'date' => ['date' => $item->dueDate->toDateString()]],
                $item->subjectType,
                $item->subjectId,
                [['type' => 'alert.snooze']],
                $item->link,
            );

            $stage = "day{$mark}:{$item->dueDate->toDateString()}";

            if ($item->eventKey === null) {
                $sent += $this->dispatcher->sendForItem('overdue', $stage, $payload, $item);

                continue;
            }

            $eventKey = $item->eventKey.'.overdue';

            if (! ($this->events->effective($eventKey)['enabled'] ?? false)) {
                continue;
            }

            $direct = array_values(array_unique([...$item->directUserIds, ...$item->overdueUserIds]));
            $others = $this->oversight->userIdsFor($eventKey, $item->projectId);

            // Nobody named: the watcher roles hear it, as sendForItem() does.
            if ($direct === []) {
                $others = [...$others, ...$this->dispatcher->watcherUserIds($item)];
            }

            $sent += $this->dispatcher->send('overdue', $stage, $payload, $direct, $others, $item->exemptUserIds);
        }

        return $sent;
    }
}
