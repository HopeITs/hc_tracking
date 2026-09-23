<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use App\Notifications\RecipientResolver;
use Carbon\CarbonImmutable;

/**
 * Still overdue: +5 working days to the program manager, +10 to the top
 * roles (defaults). Critical.
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
        $sent = 0;

        foreach ($this->catalog->items($today->subDay()) as $item) {
            $days = $this->calendar->workingDaysBetween($item->dueDate, $today);
            $due = $item->dueDate->toDateString();
            $params = ['subject' => $item->label, 'days' => (string) $days, 'date' => ['date' => $due]];

            if ($days >= $managerAfter) {
                $managers = $this->resolver->programManagerUserIds($this->catalog->programIdFor($item));

                if ($managers !== []) {
                    $payload = $this->dispatcher->payload($item->module, NotificationPriority::Critical, 'notifications.alerts.escalation.manager', $params, $item->subjectType, $item->subjectId);
                    $sent += $this->dispatcher->send('escalation', "manager:{$due}", $payload, $managers);
                }
            }

            if ($days >= $topAfter) {
                $payload = $this->dispatcher->payload($item->module, NotificationPriority::Critical, 'notifications.alerts.escalation.top', $params, $item->subjectType, $item->subjectId);
                $sent += $this->dispatcher->send('escalation', "top:{$due}", $payload, $this->resolver->usersWithRoles(config('notifications.top_roles')));
            }
        }

        return $sent;
    }
}
