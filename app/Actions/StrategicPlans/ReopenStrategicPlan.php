<?php

declare(strict_types=1);

namespace App\Actions\StrategicPlans;

use App\Enums\StrategicPlanStatus;
use App\Models\StrategicPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Take an approved strategic plan back to a draft so the MEAL manager can
 * revise it; it then needs the program manager's approval again.
 */
final class ReopenStrategicPlan
{
    public function handle(User $user, StrategicPlan $plan): StrategicPlan
    {
        Gate::forUser($user)->authorize('reopen', $plan);

        return DB::transaction(function () use ($plan): StrategicPlan {
            $locked = StrategicPlan::query()->lockForUpdate()->findOrFail($plan->id);

            if ($locked->status === StrategicPlanStatus::Approved) {
                $locked->forceFill(['status' => StrategicPlanStatus::Draft, 'approved_at' => null, 'approved_by' => null])->save();
            }

            return $locked;
        });
    }
}
