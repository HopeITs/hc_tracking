<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ActivityPlanStatus;
use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\ActivityPlan;
use App\Models\PlanUpdate;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use Illuminate\Support\Facades\Cache;

/**
 * In-app notifications of the annual plan workflow (Rami, 2026-11-11: in-app
 * only). Each event names who hears it: the program manager, the MEAL
 * manager(s), the centre's project manager, its governorate MEAL officer.
 * The actor never notifies themselves. Texts live under
 * notifications.events.annual_plan.{event}.{title,body}.
 */
final class PlanNotifier
{
    /**
     * @param  list<string>  $to  program_manager | meal | project_manager | officer
     * @param  array<string, string>  $params
     */
    public static function plan(ActivityPlan $plan, string $event, array $to, array $params = [], ?User $actor = null, bool $critical = false): void
    {
        self::send(self::recipients($plan, null, $to), $event, [
            'plan' => (string) $plan->proposal()->value('name'),
            ...$params,
        ], ['route' => 'activity-plans.show', 'parameters' => [$plan->slug]], $actor, $critical);
    }

    /**
     * @param  list<string>  $to
     * @param  array<string, string>  $params
     */
    public static function update(PlanUpdate $update, string $event, array $to, array $params = [], ?User $actor = null, bool $critical = false): void
    {
        $update->loadMissing(['plan.proposal', 'project']);

        self::send(self::recipients($update->plan, $update->project, $to), $event, [
            'plan' => (string) $update->plan->proposal->name,
            'centre' => (string) $update->project->name,
            'month' => $update->month->translatedFormat('F Y'),
            ...$params,
        ], ['route' => 'plan-updates.show', 'parameters' => [$update->plan->slug, $update->project->slug, $update->month->format('Y-m')]], $actor, $critical);
    }

    /**
     * Notify once per key (reminders, late notices) within the given days.
     *
     * @param  list<string>  $to
     * @param  array<string, string>  $params
     */
    public static function once(string $key, int $days, ActivityPlan $plan, Project $project, string $month, string $event, array $to, array $params): void
    {
        if (! Cache::add('annual-plan-notice:'.$key, true, now()->addDays($days))) {
            return;
        }

        self::send(self::recipients($plan, $project, $to), $event, [
            'plan' => (string) $plan->proposal()->value('name'),
            'centre' => (string) $project->name,
            ...$params,
        ], ['route' => 'plan-updates.show', 'parameters' => [$plan->slug, $project->slug, $month]], null, $event === 'annual_plan.update_late');
    }

    /**
     * @param  list<string>  $to
     * @return list<User>
     */
    public static function recipients(ActivityPlan $plan, ?Project $project, array $to): array
    {
        $employeeIds = [];
        $users = collect();

        if (in_array('program_manager', $to, true)) {
            $employeeIds[] = Program::query()->whereKey($plan->program_id)->value('manager_id');
        }

        if (in_array('meal', $to, true)) {
            $employeeIds[] = MealCoverage::mealManagerEmployeeId();
            $users = $users->merge(User::permission('annual-plans.review')->get());
        }

        $projects = $project !== null ? [$project] : ActivityPlanScope::projects($plan);

        foreach ($projects as $centre) {
            if (in_array('project_manager', $to, true)) {
                $employeeIds[] = $centre->getAttribute('user_id') ?? Project::ownerEmployeeIdFor($centre->id);
            }

            if (in_array('officer', $to, true)) {
                $employeeIds[] = MealCoverage::responsibleFor($centre);
            }
        }

        $employeeIds = array_values(array_unique(array_filter(array_map(fn ($id): ?int => $id === null ? null : (int) $id, $employeeIds))));

        if ($employeeIds !== []) {
            $users = $users->merge(User::query()->whereIn('employee_id', $employeeIds)->get());
        }

        return array_values($users->unique('id')->all());
    }

    /**
     * Tell every MEAL reviewer that the plan waits for them, once per user
     * and submission. Run on submit and daily: whoever becomes MEAL manager
     * after the plan was sent still hears of it (Rami, 2026-11-12).
     */
    public static function notifyReviewers(ActivityPlan $plan, ?User $actor = null): int
    {
        $sent = 0;
        $actor ??= $plan->submitted_by === null ? null : User::query()->find($plan->submitted_by);
        $stamp = $plan->submitted_at?->timestamp ?? 0;

        foreach (self::recipients($plan, null, ['meal']) as $user) {
            if ($user->id === $actor?->id || ! Cache::add("plan-review-waiting:{$plan->id}:{$stamp}:{$user->id}", true, now()->addDays(120))) {
                continue;
            }

            self::send([$user], 'annual_plan.plan_submitted', ['plan' => (string) $plan->proposal()->value('name')], ['route' => 'activity-plans.show', 'parameters' => [$plan->slug]], $actor, false);
            $sent++;
        }

        return $sent;
    }

    /**
     * notifyReviewers for every plan with MEAL.
     */
    public static function notifyAllReviewers(): int
    {
        return ActivityPlan::query()->where('status', ActivityPlanStatus::InReview->value)->get()
            ->sum(fn (ActivityPlan $plan): int => self::notifyReviewers($plan));
    }

    /**
     * @param  list<User>  $users
     * @param  array<string, string>  $params
     * @param  array<string, mixed>  $link
     */
    private static function send(array $users, string $event, array $params, array $link, ?User $actor, bool $critical): void
    {
        foreach ($users as $user) {
            if ($actor !== null && $user->id === $actor->id) {
                continue;
            }

            rescue(fn () => $user->notify(new AppNotification(new NotificationPayload(
                kind: NotificationKind::Action,
                module: 'annual-plans',
                priority: $critical ? NotificationPriority::Critical : NotificationPriority::Info,
                key: 'notifications.events.'.$event,
                params: array_filter([...$params, 'actor' => $actor?->name], fn (?string $value): bool => $value !== null),
                subjectType: null,
                subjectId: null,
                actorId: $actor?->id,
                link: $link,
            ))), report: false);
        }
    }
}
