<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use Carbon\CarbonImmutable;

/**
 * "Due soon": 7 / 3 / 1 working days before, and on the day (defaults).
 */
final class DueSoonScanner implements Scanner
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

        $stages = collect((array) $this->settings->get('due_soon_days'))->map(fn ($days): int => (int) $days)->push(0)->unique()->sort()->values();
        $horizon = $this->calendar->addWorkingDays($today, (int) $stages->max() + 1)->subDay();
        $sent = 0;

        foreach ($this->catalog->items($horizon) as $item) {
            if ($item->dueDate->lt($today)) {
                continue;
            }

            $effective = $this->calendar->lastWorkingDayOnOrBefore($item->dueDate);
            $remaining = $this->calendar->workingDaysBetween($today, $effective);
            $stage = $stages->first(fn (int $threshold): bool => $remaining <= $threshold);

            if ($stage === null) {
                continue;
            }

            $key = match (true) {
                $remaining === 0 && $item->dueDate->isSameDay($today) => 'today',
                $remaining === 0 => 'on_day',
                $remaining === 1 => 'in_one_day',
                default => 'in_days',
            };

            $payload = $this->dispatcher->payload(
                $item->module,
                $remaining === 0 ? NotificationPriority::Important : NotificationPriority::Info,
                'notifications.alerts.due_soon.'.$key,
                [
                    'subject' => $item->label,
                    'days' => (string) $remaining,
                    'date' => ['date' => $item->dueDate->toDateString()],
                    'weekday' => ['weekday' => $item->dueDate->toDateString()],
                ],
                $item->subjectType,
                $item->subjectId,
            );

            $sent += $this->dispatcher->sendForItem('due_soon', "d{$stage}:{$item->dueDate->toDateString()}", $payload, $item);
        }

        return $sent;
    }
}
