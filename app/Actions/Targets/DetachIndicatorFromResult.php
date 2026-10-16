<?php

declare(strict_types=1);

namespace App\Actions\Targets;

use App\Models\Indicator;
use App\Models\PlanIndicator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Remove an indicator instance from its node (contracts §12.1).
 *
 * - Who: `indicators.delete`.
 * - Refused, with the count, while any target (`plan_indicators` row) points
 *   at it; targets are removed first through the grid.
 *
 * Error key: indicator.
 */
final class DetachIndicatorFromResult
{
    /**
     * @throws ValidationException
     */
    public function handle(Indicator $indicator): void
    {
        Gate::authorize('indicators.delete');

        DB::transaction(function () use ($indicator): void {
            $locked = Indicator::query()->whereKey($indicator->getKey())->lockForUpdate()->firstOrFail();
            $targets = PlanIndicator::query()->where('indicator_id', $locked->id)->count();

            if ($targets > 0) {
                throw ValidationException::withMessages([
                    'indicator' => trans_choice('targets.detach_has_targets', $targets, ['count' => $targets]),
                ]);
            }

            $locked->delete();
        });
    }
}
