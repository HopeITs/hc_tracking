<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\DonorReport;
use App\Models\UserNotification;
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
            'task' => route('tasks.show', $id),
            'milestone' => route('milestones.show', $id),
            'project' => route('projects.show', $id),
            'program' => route('programs.show', $id),
            'annual_plan' => route('annual-plans.show', $id),
            'strategy' => route('strategies.show', $id),
            'proposal' => route('proposals.show', $id),
            'report' => route('reports.show', $id),
            'donor' => route('donors.show', $id),
            'donor_proposal' => route('funding-agreements.show', $id),
            'donor_report' => $this->donorReportRoute($id),
            'risk' => route('risks.index'),
            'indicator' => route('indicators.index', ['inspect' => $id]),
            'plan_indicator' => route('targets.index'),
            'result' => route('results.index'),
            'transfer' => route('transfers.index'),
            'account' => route('admin.accounts.index'),
            'user' => route('admin.users.index'),
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
            $existing[$type] = $class === null ? [] : $class::query()
                ->whereKey(array_values(array_unique($ids)))
                ->pluck((new $class)->getKeyName())
                ->map(fn (int|string $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
        }

        return $existing;
    }

    /**
     * A donor report leads to the funding agreement it is owed under.
     */
    private function donorReportRoute(int $id): ?string
    {
        $agreementId = DonorReport::query()->whereKey($id)->value('donor_proposal_id');

        return $agreementId === null ? null : route('funding-agreements.show', $agreementId);
    }
}
