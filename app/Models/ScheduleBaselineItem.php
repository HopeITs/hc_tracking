<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ScheduleBaselineItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One milestone's or task's dates as they stood when a baseline was saved.
 *
 * @property int $id
 * @property int $schedule_baseline_id
 * @property string $item_type
 * @property int $item_id
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $end_date
 * @property int $progress
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['schedule_baseline_id', 'item_type', 'item_id', 'start_date', 'end_date', 'progress'])]
class ScheduleBaselineItem extends Model
{
    /** @use HasFactory<ScheduleBaselineItemFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'progress' => 0,
    ];

    /**
     * @return BelongsTo<ScheduleBaseline, $this>
     */
    public function baseline(): BelongsTo
    {
        return $this->belongsTo(ScheduleBaseline::class, 'schedule_baseline_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'progress' => 'integer',
            'item_id' => 'integer',
        ];
    }
}
