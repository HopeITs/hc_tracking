<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StrategicPlanYearFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One year of a strategic plan: what must be achieved, and the indicators
 * (with their figure) that measure it.
 *
 * @property int $id
 * @property int $strategic_plan_id
 * @property int $year
 * @property string|null $goals
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['strategic_plan_id', 'year', 'goals'])]
class StrategicPlanYear extends Model
{
    /** @use HasFactory<StrategicPlanYearFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<StrategicPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(StrategicPlan::class, 'strategic_plan_id');
    }

    /**
     * @return HasMany<StrategicPlanTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(StrategicPlanTarget::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strategic_plan_id' => 'integer',
            'year' => 'integer',
        ];
    }
}
