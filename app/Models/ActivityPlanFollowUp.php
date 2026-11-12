<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FollowUpTrigger;
use Carbon\CarbonImmutable;
use Database\Factories\ActivityPlanFollowUpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A follow-up task MEAL attaches to an activity while reviewing the plan,
 * e.g. "when Basic sessions finish, run the post test". It fires once per
 * centre, as a task for that governorate's MEAL officer.
 *
 * @property int $id
 * @property int $activity_plan_id
 * @property int $activity_plan_item_id
 * @property FollowUpTrigger $trigger
 * @property string $description
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['activity_plan_id', 'activity_plan_item_id', 'trigger', 'description', 'created_by'])]
class ActivityPlanFollowUp extends Model
{
    /** @use HasFactory<ActivityPlanFollowUpFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ActivityPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ActivityPlan::class, 'activity_plan_id');
    }

    /**
     * @return BelongsTo<ActivityPlanItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ActivityPlanItem::class, 'activity_plan_item_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'activity_plan_id' => 'integer',
            'activity_plan_item_id' => 'integer',
            'trigger' => FollowUpTrigger::class,
            'created_by' => 'integer',
        ];
    }
}
