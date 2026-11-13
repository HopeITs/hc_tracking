<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Models\ActivityPlanBatch;
use App\Models\ActivityPlanItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Use the same settings for other activities": copies how the activity
 * runs, what it counts, its batches (weeks, counts, hours), governorates and
 * expected people to other activities of the same plan. Each keeps its own
 * note. A copy of a complete activity is Ready.
 */
final class CopyActivityPlanItemSettings
{
    /**
     * @param  list<int>  $targetItemIds
     * @return int how many activities received the settings
     */
    public function handle(User $user, ActivityPlanItem $source, array $targetItemIds): int
    {
        $plan = $source->plan()->firstOrFail();
        Gate::forUser($user)->authorize('update', $plan);
        $source->load(['batches', 'cities']);
        $targets = $plan->items()->whereKey($targetItemIds)->whereKeyNot($source->id)->get();

        if ($targets->isEmpty()) {
            throw ValidationException::withMessages(['copy' => __('annual_plans.copy_pick_one')]);
        }

        $data = [
            'single_week' => $source->is_single_week,
            'unit' => $source->unit->value,
            'unit_other' => $source->unit_other,
            'cities' => $source->cityIds(),
            'expected_people' => $source->expected_people,
            'note' => null,
            'batches' => array_values($source->batches->map(fn (ActivityPlanBatch $batch): array => [
                'start' => $batch->start_week->toDateString(),
                'end' => $batch->end_week->toDateString(),
                'count' => $batch->planned_count,
                'hours' => $batch->hours_each === null ? null : (float) $batch->hours_each,
            ])->all()),
        ];
        $complete = SaveActivityPlanItem::isComplete($data);

        DB::transaction(function () use ($targets, $data, $complete): void {
            foreach ($targets as $target) {
                SaveActivityPlanItem::write($target, [...$data, 'note' => $target->note], $complete);
            }
        });

        return $targets->count();
    }
}
