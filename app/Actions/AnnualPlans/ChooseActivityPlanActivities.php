<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\ActivityKind;
use App\Enums\CountUnit;
use App\Models\ActivityPlan;
use App\Models\ActivityPlanItem;
use App\Models\Result;
use App\Models\User;
use App\Support\ActivityPlanScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Step 2: the logframe activities the plan runs. Unticked activities leave
 * the plan with their batches; newly ticked ones start "Needs details" with
 * one batch of eight weeks (or one single week for an event) in every
 * governorate of the plan, in logframe order.
 */
final class ChooseActivityPlanActivities
{
    /**
     * @param  list<int>  $resultIds
     */
    public function handle(User $user, ActivityPlan $plan, array $resultIds): ActivityPlan
    {
        Gate::forUser($user)->authorize('update', $plan);
        $allowed = ActivityPlanScope::activityIds($plan);
        $chosen = array_values(array_filter($allowed, fn (int $id): bool => in_array($id, $resultIds, true)));

        if ($chosen === []) {
            throw ValidationException::withMessages(['activities' => __('annual_plans.choose_at_least_one')]);
        }

        return DB::transaction(function () use ($plan, $chosen): ActivityPlan {
            $plan->items()->whereNotIn('result_id', $chosen)->delete();
            $existing = $plan->items()->pluck('result_id')->map(fn (int|string $id): int => (int) $id)->all();
            $calendar = $plan->calendar();
            $cityIds = ActivityPlanScope::cityIds($plan);
            $kinds = Result::query()->whereKey($chosen)->get(['id', 'activity_kind'])->keyBy('id');
            $first = $calendar->firstMonday();
            $last = $calendar->lastMonday();

            foreach ($chosen as $sort => $resultId) {
                if (in_array($resultId, $existing, true)) {
                    $plan->items()->where('result_id', $resultId)->update(['sort' => $sort]);

                    continue;
                }

                $isEvent = $kinds->get($resultId)?->activity_kind === ActivityKind::Event;
                /** @var ActivityPlanItem $item */
                $item = $plan->items()->create([
                    'result_id' => $resultId,
                    'is_single_week' => $isEvent,
                    'unit' => ($isEvent ? CountUnit::Test : CountUnit::Session)->value,
                    'sort' => $sort,
                ]);
                $start = $isEvent ? $first->addWeeks(4) : $first;
                $start = $start->gt($last) ? $last : $start;
                $end = $isEvent ? $start : $first->addWeeks(7);
                $item->batches()->create([
                    'start_week' => $start->toDateString(),
                    'end_week' => ($end->gt($last) ? $last : $end)->toDateString(),
                    'planned_count' => $isEvent ? 1 : null,
                    'sort' => 0,
                ]);

                foreach ($cityIds as $cityId) {
                    $item->cities()->create(['city_id' => $cityId]);
                }
            }

            $plan->forceFill(['step' => max($plan->step, 2)])->save();

            return $plan->refresh();
        });
    }
}
