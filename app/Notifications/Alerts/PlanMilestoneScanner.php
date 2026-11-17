<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\ActivityPlanStatus;
use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\ActivityPlanMilestone;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use App\Notifications\RecipientFilter;
use App\Notifications\RecipientResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PlanMilestoneScanner
{
    public function __construct(
        private AlertLedger $ledger,
        private RecipientResolver $resolver,
        private RecipientFilter $filter,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $sent = 0;
        ActivityPlanMilestone::query()
            ->whereHas('plan', fn ($query) => $query->where('status', ActivityPlanStatus::Approved->value))
            ->whereDate('due_date', '<=', $now->addDays(7)->toDateString())
            ->with(['plan', 'target.indicator', 'target.reports'])
            ->orderBy('id')
            ->chunkById(100, function ($milestones) use ($now, &$sent): void {
                foreach ($milestones as $milestone) {
                    if ($milestone->status($now) === 'reached') {
                        continue;
                    }

                    $days = (int) $now->startOfDay()->diffInDays($milestone->due_date, false);
                    $stage = match (true) {
                        $milestone->status($now) === 'missed' => 'missed',
                        $days === 7 => 'week',
                        $days === 3 => 'three_days',
                        default => null,
                    };
                    if ($stage !== null) {
                        $sent += $this->notify($milestone, $stage);
                    }
                }
            });

        return $sent;
    }

    private function notify(ActivityPlanMilestone $milestone, string $stage): int
    {
        $creator = $milestone->created_by === null ? [] : [(int) $milestone->created_by];
        $admins = $stage === 'missed' ? $this->resolver->usersWithRoles(['Super Admin']) : [];
        $generalManagers = $stage === 'missed' ? $this->resolver->usersWithRoles(['General Manager']) : [];
        $programManagers = $stage === 'missed' ? $this->resolver->programManagerUserIds($milestone->plan->program_id) : [];
        $candidates = array_values(array_unique([...$creator, ...$admins, ...$generalManagers, ...$programManagers]));
        $allowed = $this->filter->apply($candidates, 'annual-plans', null, $candidates);
        $sent = 0;

        foreach (User::query()->whereIn('id', $allowed)->get() as $recipient) {
            $isAlert = in_array($recipient->id, [...$creator, ...$admins], true);
            $priority = in_array($recipient->id, $admins, true)
                ? NotificationPriority::Critical
                : ($isAlert ? NotificationPriority::Important : NotificationPriority::Info);
            $payload = new NotificationPayload(
                kind: $isAlert ? NotificationKind::Alert : NotificationKind::Action,
                module: 'annual-plans',
                priority: $priority,
                key: 'notifications.events.annual_plan.milestone_'.$stage,
                params: ['milestone' => $milestone->title, 'date' => $milestone->due_date->toDateString()],
                subjectType: 'activity_plan_milestone',
                subjectId: $milestone->id,
                link: ['route' => 'activity-plans.show', 'parameters' => [$milestone->plan->slug]],
            );
            $sent += DB::transaction(function () use ($milestone, $stage, $recipient, $payload): int {
                $key = $stage.':'.$milestone->due_date->toDateString();
                if (! $this->ledger->claim('plan_milestone', 'activity_plan_milestone', $milestone->id, $key, $recipient->id)) {
                    return 0;
                }
                $recipient->notify(new AppNotification($payload));

                return 1;
            });
        }

        return $sent;
    }
}
