<?php

declare(strict_types=1);

namespace App\Actions\StrategicPlans;

use App\Enums\StrategicPlanStatus;
use App\Models\StrategicPlan;
use App\Models\StrategicPlanTarget;
use App\Models\StrategicPlanYear;
use App\Models\User;
use App\Support\StrategicPlanNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Send a draft strategic plan to the program manager for approval. Every
 * year must say what it achieves and at least one indicator must be linked.
 * The plan is locked while it waits; a previous send-back reason is cleared.
 */
final class SubmitStrategicPlan
{
    /**
     * @throws ValidationException
     */
    public function handle(User $user, StrategicPlan $plan): StrategicPlan
    {
        Gate::forUser($user)->authorize('write', [StrategicPlan::class, $plan]);

        return DB::transaction(function () use ($user, $plan): StrategicPlan {
            $locked = StrategicPlan::query()->lockForUpdate()->findOrFail($plan->id);

            if ($locked->status !== StrategicPlanStatus::Draft) {
                throw ValidationException::withMessages(['plan' => __('strategic_plans.not_a_draft')]);
            }

            $years = $locked->years()->get(['id', 'year', 'goals']);

            if ($years->isEmpty() || $years->contains(fn (StrategicPlanYear $year): bool => blank($year->goals))) {
                throw ValidationException::withMessages(['plan' => __('strategic_plans.every_year_needs_goals')]);
            }

            if (! StrategicPlanTarget::query()->whereIn('strategic_plan_year_id', $years->pluck('id'))->exists()) {
                throw ValidationException::withMessages(['plan' => __('strategic_plans.link_an_indicator')]);
            }

            $locked->forceFill([
                'status' => StrategicPlanStatus::Submitted,
                'submitted_at' => now(),
                'submitted_by' => $user->id,
                'sent_back_reason' => null,
            ])->save();

            StrategicPlanNotifier::send($locked, 'submitted', 'program_manager', $user);

            return $locked;
        });
    }
}
