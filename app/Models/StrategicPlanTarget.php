<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StrategicPlanTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A program indicator one strategic plan year is measured by, with the
 * figure to reach that year; null target = linked, no figure yet.
 *
 * @property int $id
 * @property int $strategic_plan_year_id
 * @property int $indicator_id
 * @property string|null $target
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['strategic_plan_year_id', 'indicator_id', 'target'])]
class StrategicPlanTarget extends Model
{
    /** @use HasFactory<StrategicPlanTargetFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<StrategicPlanYear, $this>
     */
    public function year(): BelongsTo
    {
        return $this->belongsTo(StrategicPlanYear::class, 'strategic_plan_year_id');
    }

    /**
     * @return BelongsTo<Indicator, $this>
     */
    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strategic_plan_year_id' => 'integer',
            'indicator_id' => 'integer',
            'target' => 'decimal:2',
        ];
    }
}
