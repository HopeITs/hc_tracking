<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Enums\OversightMode;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Notifications\EmployeeRecipients;
use App\Notifications\EventCatalog;
use App\Support\EmployeeProfile;
use App\Support\MealCoverage;

/**
 * The oversight layer of a catalog event (contracts.md §15.1): MEAL (role
 * holders + the project's officer) and General Management (role + roles the
 * admin added for that event), per the event's oversight mode. A separate
 * layer: it never widens a module's watcher roles. Super Admin never
 * oversees anything (D-20a).
 */
final class OversightRecipients
{
    private const MealRole = 'MEAL';

    private const ManagementRole = 'General Manager';

    public function __construct(
        private EventCatalog $catalog,
        private EmployeeRecipients $employees,
    ) {}

    /**
     * MEAL + management per the key's oversight mode; [] for `none`, for a
     * disabled or unknown event, and never Super Admin.
     *
     * @return list<int> user ids
     */
    public function userIdsFor(string $eventKey, ?int $projectId = null): array
    {
        $event = $this->catalog->effective($eventKey);

        if ($event === null || ! $event['enabled']) {
            return [];
        }

        $management = $this->usersWithRoles([self::ManagementRole, ...$event['oversight_roles']]);

        $ids = match ($event['oversight']) {
            OversightMode::All => [...$this->meal($projectId), ...$management],
            OversightMode::ManagementOnly => $management,
            OversightMode::None => [],
        };

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * Role `MEAL` holders + (when $projectId is given) the user of the
     * project's responsible MEAL officer.
     *
     * @return list<int>
     */
    public function meal(?int $projectId = null): array
    {
        $ids = [...$this->usersWithRoles([self::MealRole]), ...$this->projectOfficerUserIds($projectId)];

        return $this->withoutSuperAdmins(array_values(array_unique($ids)));
    }

    /**
     * Role `General Manager` holders. Roles added in settings are per event
     * and join through userIdsFor().
     *
     * @return list<int>
     */
    public function management(): array
    {
        return $this->usersWithRoles([self::ManagementRole]);
    }

    /**
     * Members of an HR department mapped to users.
     *
     * @return list<int>
     */
    public function departmentUserIds(int $departmentId): array
    {
        $ids = array_values($this->employees->usersFor(EmployeeProfile::employeeIdsInDepartment($departmentId)));
        sort($ids);

        return $ids;
    }

    /**
     * The user behind the project's responsible MEAL officer, when active and
     * holding an account.
     *
     * @return list<int>
     */
    private function projectOfficerUserIds(?int $projectId): array
    {
        $project = $projectId === null ? null : Project::query()->find($projectId);
        $officer = $project === null ? null : MealCoverage::responsibleFor($project);

        return $officer === null ? [] : array_values($this->employees->usersFor([$officer]));
    }

    /**
     * Holders of any existing role in the list, never Super Admin holders.
     * Unknown (deleted) roles are skipped.
     *
     * @param  list<string>  $roles
     * @return list<int>
     */
    private function usersWithRoles(array $roles): array
    {
        $roles = array_values(array_diff($roles, [EventCatalog::SuperAdminRole]));
        $existing = $roles === [] ? [] : Role::query()->whereIn('name', $roles)->pluck('name')->all();

        if ($existing === []) {
            return [];
        }

        $ids = array_map(
            fn (int|string $id): int => (int) $id,
            User::role($existing)
                ->whereDoesntHave('roles', fn ($query) => $query->where('name', EventCatalog::SuperAdminRole))
                ->pluck('users.id')
                ->all(),
        );
        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function withoutSuperAdmins(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $superAdmins = array_map(
            fn (int|string $id): int => (int) $id,
            User::query()
                ->whereIn('users.id', $ids)
                ->whereHas('roles', fn ($query) => $query->where('name', EventCatalog::SuperAdminRole))
                ->pluck('users.id')
                ->all(),
        );
        $kept = array_values(array_diff($ids, $superAdmins));
        sort($kept);

        return $kept;
    }
}
