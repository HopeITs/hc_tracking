<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use App\Notifications\RecipientResolver;
use Carbon\CarbonImmutable;

/**
 * Still overdue: +5 working days to the program manager, +10 to the top
 * roles (defaults). Critical.
 *
 * Each stage fires only within a short catch-up window after its threshold
 * is crossed (default 3 working days), so items that were overdue long
 * before the scanner first ran — or before a record was imported — keep
 * their overdue reminders but never flood managers with a burst of critical
 * escalations (user decision 2026-09-23). The window also tolerates a few
 * days of scheduler downtime.
 */
final class EscalationScanner implements Scanner
{
    public function __construct(
        private DeadlineCatalog $catalog,
        private WorkingCalendar $calendar,
        private NotificationSettings $settings,
        private AlertDispatcher $dispatcher,
        private RecipientResolver $resolver,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $today = $now->startOfDay();

        if (! $this->calendar->isWorkingDay($today)) {
            return 0;
        }

        $managerAfter = (int) $this->settings->get('escalate_manager_after_days');
        $topAfter = (int) $this->settings->get('escalate_top_after_days');
        $window = max(1, (int) $this->settings->get('escalation_catch_up_days'));
        $sent = 0;

        foreach ($this->catalog->items($today->subDay()) as $item) {
            $days = $this->calendar->workingDaysBetween($item->dueDate, $today);
            $due = $item->dueDate->toDateString();
            $params = ['subject' => $item->label, 'days' => (string) $days, 'date' => ['date' => $due]];

            if ($this->withinWindow($days, $managerAfter, $window)) {
                $managers = $this->resolver->programManagerUserIds($this->catalog->programIdFor($item));

                if ($managers !== []) {
                    $payload = $this->dispatcher->payload($item->module, NotificationPriority::Critical, 'notifications.alerts.escalation.manager', $params, $item->subjectType, $item->subjectId);
                    $sent += $this->dispatcher->send('escalation', "manager:{$due}", $payload, $managers);
                }
            }

            if ($this->withinWindow($days, $topAfter, $window)) {
                $payload = $this->dispatcher->payload($item->module, NotificationPriority::Critical, 'notifications.alerts.escalation.top', $params, $item->subjectType, $item->subjectId);
                $sent += $this->dispatcher->send('escalation', "top:{$due}", $payload, $this->resolver->usersWithRoles(config('notifications.top_roles')));
            }
        }

        return $sent;
    }

    /**
     * Whether an item overdue by $days working days is inside the catch-up
     * window of a stage that starts at $threshold.
     */
    private function withinWindow(int $days, int $threshold, int $window): bool
    {
        return $days >= $threshold && $days < $threshold + $window;
    }
}
