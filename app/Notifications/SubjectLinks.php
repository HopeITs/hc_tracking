<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AnnualPlan;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Strategy;
use App\Models\Task;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Where a notification or activity row leads. Returns null when the record
 * no longer exists, so the caller can say so instead of 404ing.
 */
final class SubjectLinks
{
    public function urlFor(UserNotification $notification): ?string
    {
        $link = $notification->data['link'] ?? [];

        if (isset($link['route'])) {
            return route($link['route'], $link['parameters'] ?? []);
        }

        if ($notification->subject_type === null || $notification->subject_id === null) {
            return null;
        }

        return $this->urlForSubject($notification->subject_type, $notification->subject_id);
    }

    public function urlForSubject(string $type, int $id): ?string
    {
        $class = Relation::getMorphedModel($type);

        if ($class === null || ! $class::query()->whereKey($id)->exists()) {
            return null;
        }

        return $this->routeFor($type, $id);
    }

    /**
     * The page for a record, without checking it still exists. Records with
     * no page of their own lead to their directory.
     */
    public function routeFor(string $type, int $id): ?string
    {
        return match ($type) {
            'task' => $this->showRoute('tasks.show', Task::class, $id),
            'milestone' => $this->showRoute('milestones.show', Milestone::class, $id),
            'project' => $this->showRoute('projects.show', Project::class, $id),
            'program' => $this->showRoute('programs.show', Program::class, $id),
            'annual_plan' => $this->showRoute('annual-plans.show', AnnualPlan::class, $id),
            'strategy' => $this->showRoute('strategies.show', Strategy::class, $id),
            'proposal' => $this->showRoute('proposals.show', Proposal::class, $id),
            'report' => $this->showRoute('reports.show', Report::class, $id),
            'donor' => $this->showRoute('donors.show', Donor::class, $id),
            'donor_proposal' => $this->showRoute('funding-agreements.show', DonorProposal::class, $id),
            'donor_report' => $this->donorReportRoute($id),
            'risk' => route('risks.index'),
            'indicator' => route('indicators.index', ['inspect' => $id]),
            'library_indicator' => route('indicator-library.index', ['entry' => $id]),
            'plan_indicator' => route('targets.index'),
            'result' => route('results.index'),
            'transfer' => route('transfers.index'),
            'account' => route('admin.accounts.index'),
            'user' => route('admin.users.index'),
            'role' => route('admin.users.index', ['tab' => 'roles']),
            default => null,
        };
    }

    /**
     * Which of the given records still exist — one query per type.
     *
     * @param  array<string, list<int>>  $idsByType
     * @return array<string, list<int>>
     */
    public function existing(array $idsByType): array
    {
        $existing = [];

        foreach ($idsByType as $type => $ids) {
            $class = Relation::getMorphedModel($type);
            $existing[$type] = $class === null ? [] : array_values($class::query()
                ->whereKey(array_values(array_unique($ids)))
                ->pluck((new $class)->getKeyName())
                ->map(fn (int|string $id): int => (int) $id)
                ->sort()
                ->all());
        }

        return $existing;
    }

    /**
     * A donor report leads to the funding agreement it is owed under.
     */
    private function donorReportRoute(int $id): ?string
    {
        $agreementId = DonorReport::query()->whereKey($id)->value('donor_proposal_id');

        return $agreementId === null ? null : $this->showRoute('funding-agreements.show', DonorProposal::class, $agreementId);
    }

    /**
     * A show route bound by slug needs the record itself, not its id — the
     * id a notification or activity row stores is no longer a valid route
     * parameter on its own.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function showRoute(string $routeName, string $modelClass, int $id): ?string
    {
        $record = $modelClass::query()->find($id);

        return $record === null ? null : route($routeName, $record);
    }
}
