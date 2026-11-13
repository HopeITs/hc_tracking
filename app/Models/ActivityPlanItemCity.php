<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A governorate (HR city id, no foreign key) a planned activity runs in.
 *
 * @property int $id
 * @property int $activity_plan_item_id
 * @property int $city_id
 */
#[Fillable(['activity_plan_item_id', 'city_id'])]
class ActivityPlanItemCity extends Model
{
    /**
     * @return BelongsTo<ActivityPlanItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ActivityPlanItem::class, 'activity_plan_item_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activity_plan_item_id' => 'integer',
            'city_id' => 'integer',
        ];
    }
}
