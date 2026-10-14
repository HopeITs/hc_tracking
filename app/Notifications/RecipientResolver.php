<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\EventImportance;
use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Notifications\Recipients\ModuleRecipients;
use App\Notifications\Recipients\OversightRecipients;

/**
 * Turns one activity into the users who hear about it:
 * direct ∪ program-manager cascade ∪ role watchers ∪ top (★ only) ∪
 * oversight (EventCatalog movements of immediate importance), then
 * RecipientFilter removes the actor, users without the module's view
 * permission and users who muted it (unless direct). A catalog event the
 * admin disabled reaches nobody.
 */
final class RecipientResolver
{
    public function __construct(
        private EmployeeRecipients $employees,
        private RecipientFilter $filter,
        private EventCatalog $catalog,
        private OversightRecipients $oversight,
    ) {}

    public function resolve(Activity $activity): ResolvedRecipients
    {
        $map = $this->mapFor($activity->module);
        $eventKey = EventCatalog::keyForActivity($activity->event);
        $event = $eventKey === null ? null : $this->catalog->effective($eventKey);

        if ($map === null || ($event !== null && ! $event['enabled'])) {
            return new ResolvedRecipients([]);
        }

        $assignedEmployeeIds = $map->assignedEmployeeIds($activity);
        $employeeIds = array_values(array_unique([...$map->directEmployeeIds($activity), ...$assignedEmployeeIds]));
        $matched = $this->employees->usersFor($employeeIds);

        $direct = array_values(array_unique([...array_values($matched), ...$map->directUserIds($activity)]));
        $assignedUserIds = array_values(array_intersect_key($matched, array_flip($assignedEmployeeIds)));

        $candidates = [
            ...$direct,
            ...$this->programManagerUserIds($activity->program_id),
            ...$this->usersWithRoles($map->watcherRoles($activity)),
            ...($map->isKeyEvent($activity) ? $this->usersWithRoles(config('notifications.top_roles')) : []),
            ...($eventKey !== null && $event !== null && $event['importance'] === EventImportance::Immediate
                ? $this->oversight->userIdsFor($eventKey, $this->projectIdOf($activity))
                : []),
        ];

        $recipients = $this->filter->apply($candidates, $activity->module, $activity->actor_id, $direct, $map->permissionExemptUserIds($activity));
        $base = $map->priority($activity);
        $priorities = [];

        foreach ($recipients as $userId) {
            $priorities[$userId] = in_array($userId, $assignedUserIds, true)
                ? NotificationPriority::highest($base, NotificationPriority::Important)
                : $base;
        }

        return new ResolvedRecipients(
            $priorities,
            array_values(array_intersect($assignedUserIds, $recipients)),
            array_values(array_diff($assignedEmployeeIds, array_keys($matched))),
        );
    }

    /**
     * Users holding any of the roles; roles that do not exist are skipped
     * (spatie's role() scope throws on an unknown name).
     *
     * @param  array<int, string>  $roles
     * @return list<int>
     */
    public function usersWithRoles(array $roles): array
    {
        $existing = $roles === [] ? [] : Role::query()->whereIn('name', $roles)->pluck('name')->all();

        if ($existing === []) {
            return [];
        }

        return array_values(User::role($existing)->pluck('users.id')->map(fn (int|string $id): int => (int) $id)->all());
    }

    /**
     * The user behind a program's HR manager, when there is one.
     *
     * @return list<int>
     */
    public function programManagerUserIds(?int $programId): array
    {
        if ($programId === null) {
            return [];
        }

        $managerId = Program::query()->whereKey($programId)->value('manager_id');

        return $managerId === null ? [] : array_values($this->employees->usersFor([(int) $managerId]));
    }

    /**
     * The project an activity is about, for the MEAL officer: the subject
     * itself, or the `project_id` the recording module put in its properties.
     */
    private function projectIdOf(Activity $activity): ?int
    {
        if ($activity->subject_type === 'project') {
            return $activity->subject_id;
        }

        $projectId = $activity->properties['project_id'] ?? null;

        return is_numeric($projectId) ? (int) $projectId : null;
    }

    private function mapFor(string $module): ?ModuleRecipients
    {
        $class = config("notifications.modules.{$module}");

        return $class === null ? null : app($class);
    }
}
