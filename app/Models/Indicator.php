<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\IndicatorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int $result_id
 * @property string $measure
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'result_id', 'measure'])]
class Indicator extends Model
{
    /** @use HasFactory<IndicatorFactory> */
    use HasFactory;

    /**
     * The result this indicator measures.
     *
     * @return BelongsTo<Result, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    /**
     * The targets planned against this indicator.
     *
     * @return HasMany<PlanIndicator, $this>
     */
    public function planIndicators(): HasMany
    {
        return $this->hasMany(PlanIndicator::class);
    }
}
