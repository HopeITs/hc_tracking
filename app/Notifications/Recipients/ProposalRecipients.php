<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Notifications\EmployeeRecipients;
use App\Support\MealCoverage;

/**
 * Proposals: watched by Financial and the Public Relations Manager; approved
 * (important) and rejected (critical) are ★.
 *
 * A beneficiary change after approval (proposal.beneficiaries_changed, D-25)
 * also reaches MEAL: the officer responsible for each changed project
 * (MealCoverage) directly, and every MEAL role holder as a watcher. MEAL holds
 * no proposals.view, so those people skip the permission check for this event.
 */
final class ProposalRecipients extends WatcherRecipients
{
    private const BeneficiariesChanged = 'proposal.beneficiaries_changed';

    protected function roles(): array
    {
        return ['Financial', 'Public Relations Manager'];
    }

    public function watcherRoles(Activity $activity): array
    {
        return $activity->event === self::BeneficiariesChanged ? [...$this->roles(), 'MEAL'] : $this->roles();
    }

    public function directEmployeeIds(Activity $activity): array
    {
        return $activity->event === self::BeneficiariesChanged ? $this->mealOfficerIds($activity) : [];
    }

    public function permissionExemptUserIds(Activity $activity): array
    {
        if ($activity->event !== self::BeneficiariesChanged) {
            return [];
        }

        $mealHolders = Role::query()->where('name', 'MEAL')->exists()
            ? User::role('MEAL')->pluck('users.id')->all()
            : [];

        return $this->uniqueIds([
            ...array_values(app(EmployeeRecipients::class)->usersFor($this->mealOfficerIds($activity))),
            ...$mealHolders,
        ]);
    }

    public function isKeyEvent(Activity $activity): bool
    {
        return $activity->event === 'proposal.approved'
            || ($activity->event === 'proposal.status_changed'
                && in_array($this->changedTo($activity, 'status'), ['approved', 'rejected'], true));
    }

    public function priority(Activity $activity): NotificationPriority
    {
        return match (true) {
            $this->isKeyEvent($activity) && $this->changedTo($activity, 'status') === 'rejected' => NotificationPriority::Critical,
            $this->isKeyEvent($activity) => NotificationPriority::Important,
            default => NotificationPriority::Info,
        };
    }

    /**
     * The MEAL officer responsible for each project the change names.
     *
     * @return list<int>
     */
    private function mealOfficerIds(Activity $activity): array
    {
        $projects = $activity->properties['projects'] ?? [];
        $projectIds = is_array($projects)
            ? array_map(fn (mixed $project): int => is_array($project) ? (int) ($project['project_id'] ?? 0) : 0, $projects)
            : [];

        $officerIds = Project::query()
            ->select(['id', 'city_id', 'center_id'])
            ->whereKey($this->uniqueIds($projectIds))
            ->get()
            ->map(fn (Project $project): ?int => MealCoverage::responsibleFor($project))
            ->all();

        return $this->uniqueIds($officerIds);
    }
}
