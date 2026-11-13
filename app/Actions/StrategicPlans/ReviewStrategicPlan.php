<?php

declare(strict_types=1);

namespace App\Actions\StrategicPlans;

use App\Enums\StrategicPlanStatus;
use App\Models\StrategicPlan;
use App\Models\User;
use App\Support\StrategicPlanNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The program manager's decision on a strategic plan waiting for them:
 * approve it, or send it back to the MEAL manager as a draft with a reason.
 */
final class ReviewStrategicPlan
{
    public function approve(User $user, StrategicPlan $plan): StrategicPlan
    {
        Gate::forUser($user)->authorize('review', $plan);

        return DB::transaction(function () use ($user, $plan): StrategicPlan {
            $locked = $this->locked($plan);
            $locked->forceFill([
                'status' => StrategicPlanStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $user->id,
            ])->save();

            StrategicPlanNotifier::send($locked, 'approved', 'meal', $user);

            return $locked;
        });
    }

    /**
     * @throws ValidationException
     */
    public function sendBack(User $user, StrategicPlan $plan, string $reason): StrategicPlan
    {
        Gate::forUser($user)->authorize('review', $plan);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['sendBackReason' => __('strategic_plans.send_back_reason_required')]);
        }

        return DB::transaction(function () use ($user, $plan, $reason): StrategicPlan {
            $locked = $this->locked($plan);
            $locked->forceFill([
                'status' => StrategicPlanStatus::Draft,
                'sent_back_at' => now(),
                'sent_back_by' => $user->id,
                'sent_back_reason' => $reason,
            ])->save();

            StrategicPlanNotifier::send($locked, 'sent_back', 'meal', $user, ['reason' => $reason], true);

            return $locked;
        });
    }

    /**
     * @throws ValidationException
     */
    private function locked(StrategicPlan $plan): StrategicPlan
    {
        $locked = StrategicPlan::query()->lockForUpdate()->findOrFail($plan->id);

        if ($locked->status !== StrategicPlanStatus::Submitted) {
            throw ValidationException::withMessages(['plan' => __('strategic_plans.not_waiting')]);
        }

        return $locked;
    }
}
