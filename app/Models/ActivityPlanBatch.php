<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ActivityPlanBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One batch of a planned activity: from the week of start_week to the week of
 * end_week (both Mondays), with how many units it plans and, for sessions,
 * the hours of each. A single-week activity has start = end. Batches of one
 * activity may overlap.
 *
 * @property int $id
 * @property int $activity_plan_item_id
 * @property CarbonImmutable $start_week
 * @property CarbonImmutable $end_week
 * @property int|null $planned_count
 * @property string|null $hours_each
 * @property int $sort
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['activity_plan_item_id', 'start_week', 'end_week', 'planned_count', 'hours_each', 'sort'])]
class ActivityPlanBatch extends Model
{
    /** @use HasFactory<ActivityPlanBatchFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort' => 0,
    ];

    /**
     * @return BelongsTo<ActivityPlanItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ActivityPlanItem::class, 'activity_plan_item_id');
    }

    /**
     * The Mondays this batch runs on, ascending.
     *
     * @return list<CarbonImmutable>
     */
    public function mondays(): array
    {
        $weeks = [];

        for ($week = $this->start_week; $week->lte($this->end_week); $week = $week->addWeek()) {
            $weeks[] = $week;
        }

        return $weeks;
    }

    /**
     * Number of weeks the batch spans.
     */
    public function weekCount(): int
    {
        return intdiv((int) $this->start_week->diffInDays($this->end_week), 7) + 1;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activity_plan_item_id' => 'integer',
            'start_week' => 'date',
            'end_week' => 'date',
            'planned_count' => 'integer',
            'hours_each' => 'decimal:2',
            'sort' => 'integer',
        ];
    }
}
