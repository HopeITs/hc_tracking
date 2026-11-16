<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\HrProgram;
use App\Models\Program;
use App\Models\StrategicPlan;
use App\Models\Strategy;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;

/**
 * In-app notifications of the strategic plan workflow: the program manager
 * (the General Manager while the program has none) hears that a plan waits
 * for them; the MEAL manager hears it was approved or sent back. The actor
 * never notifies themselves. Texts live under
 * notifications.events.strategic_plan.{event}.{title,body}.
 */
final class StrategicPlanNotifier
{
    /**
     * @param  'program_manager'|'meal'  $to
     * @param  array<string, string>  $params
     */
    public static function send(StrategicPlan $plan, string $event, string $to, ?User $actor, array $params = [], bool $critical = false): void
    {
        $strategy = Strategy::query()->select(['id', 'slug', 'name'])->findOrFail($plan->strategy_id);
        $program = Program::query()->findOrFail($plan->program_id);

        foreach (self::recipients($plan, $to) as $user) {
            if ($actor !== null && $user->id === $actor->id) {
                continue;
            }

            rescue(fn () => $user->notify(new AppNotification(new NotificationPayload(
                kind: NotificationKind::Action,
                module: 'strategies',
                priority: $critical ? NotificationPriority::Critical : NotificationPriority::Info,
                key: 'notifications.events.strategic_plan.'.$event,
                params: array_filter([
                    'program' => (string) $program->name,
                    'strategy' => $strategy->name,
                    'actor' => $actor?->name,
                    ...$params,
                ], fn (?string $value): bool => $value !== null),
                subjectType: null,
                subjectId: null,
                actorId: $actor?->id,
                link: ['route' => 'strategic-plans.show', 'parameters' => ['strategy' => $strategy->slug, 'program' => HrProgram::slugOf($plan->program_id)]],
            ))), report: false);
        }
    }

    /**
     * @param  'program_manager'|'meal'  $to
     * @return list<User>
     */
    public static function recipients(StrategicPlan $plan, string $to): array
    {
        if ($to === 'meal') {
            $employeeId = MealCoverage::mealManagerEmployeeId();

            return $employeeId === null ? [] : array_values(User::query()->where('employee_id', $employeeId)->get()->all());
        }

        $managerId = Program::query()->whereKey($plan->program_id)->value('manager_id');

        $users = $managerId === null
            ? User::role('General Manager')->get()
            : User::query()->where('employee_id', $managerId)->get();

        return array_values($users->all());
    }
}
