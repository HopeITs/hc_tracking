<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanWeekStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What happened to one batch in one week of a monthly update.
 *
 * @property int $id
 * @property int $plan_update_id
 * @property int $activity_plan_batch_id
 * @property CarbonImmutable $week
 * @property PlanWeekStatus $status
 * @property string|null $reason
 * @property CarbonImmutable|null $new_week
 */
#[Fillable(['plan_update_id', 'activity_plan_batch_id', 'week', 'status', 'reason', 'new_week'])]
class PlanUpdateWeek extends Model
{
    /**
     * @return BelongsTo<PlanUpdate, $this>
     */
    public function planUpdate(): BelongsTo
    {
        return $this->belongsTo(PlanUpdate::class, 'plan_update_id');
    }

    /**
     * @return BelongsTo<ActivityPlanBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ActivityPlanBatch::class, 'activity_plan_batch_id');
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'plan_update_id' => 'integer',
            'activity_plan_batch_id' => 'integer',
            'week' => 'date',
            'status' => PlanWeekStatus::class,
            'new_week' => 'date',
        ];
    }
}
