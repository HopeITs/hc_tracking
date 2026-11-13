<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\FollowUpTrigger;
use App\Models\ActivityPlan;
use App\Models\ActivityPlanFollowUp;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * MEAL's follow-up tasks on a plan under review: an activity of the plan,
 * when (start, middle, finish) and what to do. Each fires once per centre as
 * a task for that governorate's MEAL officer.
 */
final class ManageActivityPlanFollowUps
{
    public function add(User $user, ActivityPlan $plan, int $itemId, string $trigger, string $description): ActivityPlanFollowUp
    {
        Gate::forUser($user)->authorize('review', $plan);
        $description = trim($description);

        if ($description === '' || mb_strlen($description) > 255) {
            throw ValidationException::withMessages(['followUpText' => __('annual_plans.follow_up_text_required')]);
        }

        $item = $plan->items()->findOrFail($itemId);

        return $plan->followUps()->create([
            'activity_plan_item_id' => $item->id,
            'trigger' => FollowUpTrigger::from($trigger)->value,
            'description' => $description,
            'created_by' => $user->id,
        ]);
    }

    public function remove(User $user, ActivityPlan $plan, int $followUpId): void
    {
        Gate::forUser($user)->authorize('review', $plan);
        $plan->followUps()->whereKey($followUpId)->delete();
    }
}
