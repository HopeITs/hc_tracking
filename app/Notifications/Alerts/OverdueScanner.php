<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use Carbon\CarbonImmutable;

/**
 * "Overdue": day 1, then every N working days (default 3) until done.
 */
final class OverdueScanner implements Scanner
{
    public function __construct(
        private DeadlineCatalog $catalog,
        private WorkingCalendar $calendar,
        private NotificationSettings $settings,
        private AlertDispatcher $dispatcher,
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
            );

            $sent += $this->dispatcher->sendForItem('overdue', "day{$mark}:{$item->dueDate->toDateString()}", $payload, $item);
        }

        return $sent;
    }
}
