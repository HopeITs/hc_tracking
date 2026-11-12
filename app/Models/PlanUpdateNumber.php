<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many units of one batch were done in an update's month. Never fed
 * into indicators (Rami, 2026-11-11): it tracks execution only.
 *
 * @property int $id
 * @property int $plan_update_id
 * @property int $activity_plan_batch_id
 * @property int $done_count
 */
#[Fillable(['plan_update_id', 'activity_plan_batch_id', 'done_count'])]
class PlanUpdateNumber extends Model
{
    /**
     * @return BelongsTo<ActivityPlanBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ActivityPlanBatch::class, 'activity_plan_batch_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan_update_id' => 'integer',
            'activity_plan_batch_id' => 'integer',
            'done_count' => 'integer',
        ];
    }
}
