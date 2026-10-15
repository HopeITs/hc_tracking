<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\Project;
use App\Models\ProposalReviewer;
use App\Models\Role;
use App\Models\User;
use App\Notifications\EmployeeRecipients;
use App\Support\MealCoverage;
use App\Support\Slots;

/**
 * Proposals: watched by Financial and the Public Relations Manager; approved
 * (important) and rejected (critical) are ★.
 *
 * A beneficiary change after approval (proposal.beneficiaries_changed, D-25)
 * also reaches MEAL: the officer responsible for each changed project
 * (MealCoverage) directly, and every MEAL role holder as a watcher. MEAL holds
 * no proposals.view, so those people skip the permission check for this event.
 *
 * The review loop (T07, contracts §15.2): a review request reaches the
 * reviewers it names, an added or removed reviewer is told, a reviewer who
 * has seen the proposal tells the project owners, and a need assessment tells
 * the owners and the MEAL Manager slot. Reviewers hold no proposals.view
 * (ProposalPolicy::view admits them), so the proposal's reviewers, the
 * removed reviewer and the MEAL Manager skip the permission check for these
 * events.
 */
final class ProposalRecipients extends WatcherRecipients
{
    private const BeneficiariesChanged = 'proposal.beneficiaries_changed';

    private const ReviewRequested = 'proposal.review_requested';

    private const ReviewerAdded = 'proposal.reviewer_added';

    private const ReviewerRemoved = 'proposal.reviewer_removed';

    private const ReviewerSeen = 'proposal.reviewer_seen';

    private const NeedAssessmentAdded = 'proposal.need_assessment_added';

    private const ReviewEvents = [self::ReviewRequested, self::ReviewerAdded, self::ReviewerRemoved, self::ReviewerSeen, self::NeedAssessmentAdded];

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
        return match ($activity->event) {
            self::BeneficiariesChanged => $this->mealOfficerIds($activity),
            self::ReviewRequested => $this->idsFromProperty($activity, 'employee_ids'),
            self::ReviewerAdded, self::ReviewerRemoved => $this->idsFromProperty($activity, 'employee_id'),
            self::ReviewerSeen => $this->projectOwnerIds($activity),
            self::NeedAssessmentAdded => $this->uniqueIds([...$this->projectOwnerIds($activity), Slots::holderOf('meal_manager')]),
            default => [],
        };
    }

    /**
     * The reviewers a request or an addition is about: they get the "…you…"
     * wording, and the actor is told about any who have no account.
     */
    public function assignedEmployeeIds(Activity $activity): array
    {
        return match ($activity->event) {
            self::ReviewRequested => $this->idsFromProperty($activity, 'employee_ids'),
            self::ReviewerAdded => $this->idsFromProperty($activity, 'employee_id'),
            default => [],
        };
    }

    public function permissionExemptUserIds(Activity $activity): array
    {
        if ($activity->event === self::BeneficiariesChanged) {
            $mealHolders = Role::query()->where('name', 'MEAL')->exists()
                ? User::role('MEAL')->pluck('users.id')->all()
                : [];

            return $this->uniqueIds([
                ...array_values(app(EmployeeRecipients::class)->usersFor($this->mealOfficerIds($activity))),
                ...$mealHolders,
            ]);
        }

        if (! in_array($activity->event, self::ReviewEvents, true)) {
            return [];
        }

        $employeeIds = ProposalReviewer::query()->where('proposal_id', $activity->subject_id)->pluck('employee_id')->all();

        $employeeIds = match ($activity->event) {
            self::ReviewerRemoved => [...$employeeIds, ...$this->idsFromProperty($activity, 'employee_id')],
            self::NeedAssessmentAdded => [...$employeeIds, Slots::holderOf('meal_manager')],
            default => $employeeIds,
        };

        return $this->uniqueIds(array_values(app(EmployeeRecipients::class)->usersFor($this->uniqueIds($employeeIds))));
    }

    public function isKeyEvent(Activity $activity): bool
    {
        return $activity->event === 'proposal.status_changed'
            && in_array($this->changedTo($activity, 'status'), ['approved', 'rejected'], true);
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

    /**
     * The owners of the projects the proposal funds: the proposal has no
     * owner of its own (contracts §15.2 "proposal owner").
     *
     * @return list<int>
     */
    private function projectOwnerIds(Activity $activity): array
    {
        return $this->uniqueIds(Project::query()
            ->whereHas('proposals', fn ($query) => $query->whereKey($activity->subject_id))
            ->pluck('user_id')
            ->all());
    }
}
