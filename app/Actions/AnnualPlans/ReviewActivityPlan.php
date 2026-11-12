<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Enums\ActivityPlanStatus;
use App\Models\ActivityPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Step 5, the MEAL manager's decision: approve the plan (it goes live and
 * its batches are written to the month/week calendar), or send it back to
 * the program manager with a required reason (back to step 3, a draft).
 */
final class ReviewActivityPlan
{
    public function __construct(private SyncActivitySchedules $sync) {}

    public function approve(User $user, ActivityPlan $plan): ActivityPlan
    {
        Gate::forUser($user)->authorize('review', $plan);

        return DB::transaction(function () use ($user, $plan): ActivityPlan {
            $locked = $this->locked($plan);
            $locked->forceFill([
                'status' => ActivityPlanStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $user->id,
            ])->save();
            $this->sync->handle($locked);

            return $locked;
        });
    }

    public function sendBack(User $user, ActivityPlan $plan, string $reason): ActivityPlan
    {
        Gate::forUser($user)->authorize('review', $plan);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['sendBackReason' => __('annual_plans.send_back_reason_required')]);
        }

        return DB::transaction(function () use ($user, $plan, $reason): ActivityPlan {
            $locked = $this->locked($plan);
            $locked->forceFill([
                'status' => ActivityPlanStatus::Draft,
                'step' => 2,
                'sent_back_at' => now(),
                'sent_back_by' => $user->id,
                'sent_back_reason' => $reason,
            ])->save();

            return $locked;
        });
    }

    private function locked(ActivityPlan $plan): ActivityPlan
    {
        $locked = ActivityPlan::query()->lockForUpdate()->findOrFail($plan->id);

        if ($locked->status !== ActivityPlanStatus::InReview) {
            throw ValidationException::withMessages(['plan' => __('annual_plans.not_in_review')]);
        }

        return $locked;
    }
}
