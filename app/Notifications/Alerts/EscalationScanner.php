<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use App\Models\Scopes\TaskVisibilityScope;
use App\Models\Task;
use App\Models\User;
use App\Notifications\EmployeeRecipients;
use App\Notifications\RecipientResolver;
use App\Support\SupervisionMap;
use Carbon\CarbonImmutable;

/**
 * Still overdue: +5 working days to the program manager and to the active
 * supervisors of the people named on the item, +10 to the top roles
 * (defaults). Critical.
 *
 * Each stage fires only within a short catch-up window after its threshold
 * is crossed (default 3 working days), so items that were overdue long
 * before the scanner first ran — or before a record was imported — keep
 * their overdue reminders but never flood managers with a burst of critical
 * escalations (user decision 2026-09-23). The window also tolerates a few
 * days of scheduler downtime.
 *
 * Supervisors (SupervisionMap, contracts §10) are loaded once per scan. They
 * get no extra visibility: a task only reaches a supervisor who can open it.
 */
final class EscalationScanner implements Scanner
{
    public function __construct(
        private DeadlineCatalog $catalog,
        private WorkingCalendar $calendar,
        private NotificationSettings $settings,
        private AlertDispatcher $dispatcher,
        private RecipientResolver $resolver,
        private EmployeeRecipients $employees,
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

        /** @var list<array{item: DeadlineItem, days: int}> $overdue */
        $overdue = $this->catalog->items($today->subDay())
            ->map(fn (DeadlineItem $item): array => ['item' => $item, 'days' => $this->calendar->workingDaysBetween($item->dueDate, $today)])
            ->values()
            ->all();

        $supervisors = $this->supervisorUserIds(array_map(
            fn (array $entry): ?DeadlineItem => $this->withinWindow($entry['days'], $managerAfter, $window) ? $entry['item'] : null,
            $overdue,
        ));

        foreach ($overdue as $index => ['item' => $item, 'days' => $days]) {
            $due = $item->dueDate->toDateString();
            $params = ['subject' => $item->label, 'days' => (string) $days, 'date' => ['date' => $due]];

            if ($this->withinWindow($days, $managerAfter, $window)) {
                $managers = $this->resolver->programManagerUserIds($this->catalog->programIdFor($item));

                if ($managers !== []) {
                    $payload = $this->dispatcher->payload($item->module, NotificationPriority::Critical, 'notifications.alerts.escalation.manager', $params, $item->subjectType, $item->subjectId);
                    $sent += $this->dispatcher->send('escalation', "manager:{$due}", $payload, $managers);
                }

                $itemSupervisors = array_values(array_diff($supervisors[$index] ?? [], $managers));

                if ($itemSupervisors !== []) {
                    $payload = $this->dispatcher->payload($item->module, NotificationPriority::Critical, 'notifications.alerts.escalation.supervisor', $params, $item->subjectType, $item->subjectId);
                    $sent += $this->dispatcher->send('escalation', "supervisor:{$due}", $payload, $itemSupervisors);
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

    /**
     * The users who supervise the people named on each item, minus those
     * people themselves; tasks keep only supervisors who can see them.
     * A fixed number of queries for the whole scan.
     *
     * @param  array<int, DeadlineItem|null>  $items  null = not at the manager stage
     * @return array<int, list<int>> item index => supervisor user ids
     */
    private function supervisorUserIds(array $items): array
    {
        $items = array_filter($items, fn (?DeadlineItem $item): bool => $item !== null && $item->directUserIds !== []);
        $namedUserIds = array_values(array_unique(array_merge([], ...array_values(array_map(fn (DeadlineItem $item): array => $item->directUserIds, $items)))));

        if ($namedUserIds === []) {
            return [];
        }

        /** @var array<int, int> $employeeByUser */
        $employeeByUser = User::query()
            ->whereIn('id', $namedUserIds)
            ->whereNotNull('employee_id')
            ->pluck('employee_id', 'id')
            ->mapWithKeys(fn (int|string $employeeId, int|string $userId): array => [(int) $userId => (int) $employeeId])
            ->all();

        if ($employeeByUser === []) {
            return [];
        }

        $supervisorsByEmployee = SupervisionMap::supervisorsOf(array_values(array_unique($employeeByUser)));
        $userBySupervisorEmployee = $this->employees->usersFor(array_values(array_unique(array_merge([], ...array_values($supervisorsByEmployee)))));
        $result = [];

        foreach ($items as $index => $item) {
            $ids = [];

            foreach ($item->directUserIds as $userId) {
                foreach ($supervisorsByEmployee[$employeeByUser[$userId] ?? 0] ?? [] as $supervisorEmployeeId) {
                    if (isset($userBySupervisorEmployee[$supervisorEmployeeId])) {
                        $ids[] = $userBySupervisorEmployee[$supervisorEmployeeId];
                    }
                }
            }

            $ids = array_values(array_diff(array_unique($ids), $item->directUserIds));

            if ($ids !== []) {
                $result[$index] = $ids;
            }
        }

        return $this->withoutHiddenTasks($result, $items);
    }

    /**
     * Drop supervisors from tasks outside their TaskVisibilityScope: one
     * query per department-scoped supervisor, never one per item.
     *
     * @param  array<int, list<int>>  $supervisors  item index => supervisor user ids
     * @param  array<int, DeadlineItem>  $items
     * @return array<int, list<int>>
     */
    private function withoutHiddenTasks(array $supervisors, array $items): array
    {
        $taskIndexes = array_values(array_filter(array_keys($supervisors), fn (int $index): bool => $items[$index]->subjectType === 'task'));

        if ($taskIndexes === []) {
            return $supervisors;
        }

        $taskIds = array_map(fn (int $index): int => $items[$index]->subjectId, $taskIndexes);
        $userIds = array_values(array_unique(array_merge([], ...array_map(fn (int $index): array => $supervisors[$index], $taskIndexes))));

        /** @var array<int, list<int>> $visibleTasks department-scoped user id => task ids they can see */
        $visibleTasks = [];

        foreach (User::query()->with('roles')->whereIn('id', $userIds)->get() as $user) {
            if (! $user->isTaskDepartmentScoped()) {
                continue;
            }

            $query = Task::query()->withoutGlobalScope(TaskVisibilityScope::class)->whereKey($taskIds);
            TaskVisibilityScope::constrain($query, $user);
            $visibleTasks[$user->id] = $query->pluck('id')->map(fn (int|string $id): int => (int) $id)->all();
        }

        foreach ($taskIndexes as $index) {
            $taskId = $items[$index]->subjectId;
            $supervisors[$index] = array_values(array_filter(
                $supervisors[$index],
                fn (int $userId): bool => ! isset($visibleTasks[$userId]) || in_array($taskId, $visibleTasks[$userId], true),
            ));
        }

        return $supervisors;
    }
}
