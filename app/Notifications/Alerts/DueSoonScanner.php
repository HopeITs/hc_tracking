<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use App\Notifications\EventCatalog;
use Carbon\CarbonImmutable;

/**
 * "Due soon": 7 / 3 / 1 working days before, and on the day (defaults). An
 * item with stages of its own (a department task: `department_task_due_days`
 * and the day) uses those, and a scanner-driven catalog event that an admin
 * switched off sends nothing. A department task nobody carries yet tells its
 * department once, a week of working days before it is due (D-33).
 */
final class DueSoonScanner implements Scanner
{
    /** Working days before the due date when a task with no person alerts its department (D-33). */
    public const UnassignedDays = 7;

    public function __construct(
        private DeadlineCatalog $catalog,
        private WorkingCalendar $calendar,
        private NotificationSettings $settings,
        private AlertDispatcher $dispatcher,
        private EventCatalog $events,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $today = $now->startOfDay();

        if (! $this->calendar->isWorkingDay($today)) {
            return 0;
        }

        $globalStages = collect((array) $this->settings->get('due_soon_days'))->map(fn ($days): int => (int) $days)->push(0)->unique()->sort()->values();
        $furthest = max((int) $globalStages->max(), (int) $this->settings->get('department_task_due_days'), self::UnassignedDays);
        $horizon = $this->calendar->addWorkingDays($today, $furthest + 1)->subDay();
        $sent = 0;

        foreach ($this->catalog->items($horizon) as $item) {
            if ($item->dueDate->lt($today) || ! $this->isEnabled($item)) {
                continue;
            }

            $effective = $this->calendar->lastWorkingDayOnOrBefore($item->dueDate);
            $remaining = $this->calendar->workingDaysBetween($today, $effective);

            if ($item->unassignedUserIds !== [] && $remaining <= self::UnassignedDays) {
                $sent += $this->dispatcher->send('department_unassigned', $item->dueDate->toDateString(), $this->dispatcher->payload(
                    $item->module,
                    NotificationPriority::Important,
                    'notifications.alerts.department_task_unassigned',
                    ['subject' => $item->label, 'date' => ['date' => $item->dueDate->toDateString()]],
                    $item->subjectType,
                    $item->subjectId,
                ), $item->unassignedUserIds);
            }

            $stages = $item->dueSoonStages === null
                ? $globalStages
                : collect($item->dueSoonStages)->push(0)->unique()->sort()->values();
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

    /**
     * A scanner-driven catalog event (`<eventKey>.due`) runs only while enabled.
     */
    private function isEnabled(DeadlineItem $item): bool
    {
        return $item->eventKey === null || ($this->events->effective($item->eventKey.'.due')['enabled'] ?? false);
    }
}
