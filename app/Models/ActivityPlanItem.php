<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CountUnit;
use Carbon\CarbonImmutable;
use Database\Factories\ActivityPlanItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One logframe activity inside an annual plan: how it runs (several weeks or
 * single weeks), what it counts, where it runs, and its batches.
 *
 * @property int $id
 * @property int $activity_plan_id
 * @property int $result_id
 * @property bool $is_single_week
 * @property CountUnit $unit
 * @property string|null $unit_other
 * @property int|null $expected_people
 * @property string|null $note
 * @property bool $is_ready
 * @property int $sort
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'activity_plan_id',
    'result_id',
    'is_single_week',
    'unit',
    'unit_other',
    'expected_people',
    'note',
    'is_ready',
    'sort',
])]
class ActivityPlanItem extends Model
{
    /** @use HasFactory<ActivityPlanItemFactory> */
    use HasFactory;

    /**
     * Mirror the column defaults for strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_single_week' => false,
        'unit' => 'session',
        'is_ready' => false,
        'sort' => 0,
    ];

    /**
     * @return BelongsTo<ActivityPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ActivityPlan::class, 'activity_plan_id');
    }

    /**
     * The logframe activity (a `results` row of type activity).
     *
     * @return BelongsTo<Result, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Result::class, 'result_id');
    }

    /**
     * @return HasMany<ActivityPlanBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(ActivityPlanBatch::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * The governorates (HR city ids) the activity runs in.
     *
     * @return HasMany<ActivityPlanItemCity, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(ActivityPlanItemCity::class)->orderBy('city_id');
    }

    /**
     * The city ids, ascending. Needs `cities` loaded.
     *
     * @return list<int>
     */
    public function cityIds(): array
    {
        return array_values($this->cities->map(fn (ActivityPlanItemCity $city): int => $city->city_id)->all());
    }

    /**
     * "24 sessions" for a count of this item's unit.
     */
    public function counted(int $count): string
    {
        return $this->unit->counted($count, $this->unit_other);
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
            'result_id' => 'integer',
            'is_single_week' => 'boolean',
            'unit' => CountUnit::class,
            'expected_people' => 'integer',
            'is_ready' => 'boolean',
            'sort' => 'integer',
        ];
    }
}
