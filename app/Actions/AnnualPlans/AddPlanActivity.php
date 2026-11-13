<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Actions\Results\SaveResult;
use App\Enums\ActivityKind;
use App\Enums\ResultType;
use App\Models\ActivityPlan;
use App\Models\Result;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "+ Add activity" on step 2 of the annual plan (user decision 2026-11-12):
 * the program manager names the activity and picks the outcome (or output)
 * it serves; the activity is placed in the proposal's logframe through
 * SaveResult, so every chain rule still holds. An activity sits under an
 * output, so under an outcome with no output yet one is created for it
 * first. The new activity is ticked in the plan.
 */
final class AddPlanActivity
{
    public function __construct(private SaveResult $save) {}

    public function handle(User $user, ActivityPlan $plan, string $statement, int $parentId, bool $singleWeek): Result
    {
        Gate::forUser($user)->authorize('update', $plan);
        $statement = trim($statement);

        if ($statement === '') {
            throw ValidationException::withMessages(['newActivityName' => __('annual_plans.wf.activity_name_required')]);
        }

        $proposal = $plan->proposal()->firstOrFail();
        $parent = Result::query()->where('proposal_id', $proposal->id)->whereKey($parentId)->first();

        if ($parent === null || ! in_array($parent->type, [ResultType::Outcome, ResultType::Output], true)
            || ($parent->program_id !== null && (int) $parent->program_id !== $plan->program_id)) {
            throw ValidationException::withMessages(['newActivityParent' => __('annual_plans.wf.pick_outcome')]);
        }

        return DB::transaction(function () use ($parent, $proposal, $statement, $singleWeek): Result {
            $output = $parent->type === ResultType::Output
                ? $parent
                : (Result::query()->where('parent_id', $parent->id)->where('type', ResultType::Output->value)->orderBy('id')->first()
                    ?? $this->save->handle(null, [
                        'statement' => __('annual_plans.wf.auto_output', ['outcome' => mb_substr((string) $parent->statement, 0, 180)]),
                        'type' => ResultType::Output->value,
                        'parent_id' => $parent->id,
                        'checkout_period' => $parent->checkout_period?->value ?? 'monthly',
                    ], $proposal));

            return $this->save->handle(null, [
                'statement' => $statement,
                'type' => ResultType::Activity->value,
                'activity_kind' => ($singleWeek ? ActivityKind::Event : ActivityKind::Continuous)->value,
                'parent_id' => $output->id,
                'checkout_period' => $output->checkout_period?->value ?? 'monthly',
            ], $proposal);
        });
    }
}
