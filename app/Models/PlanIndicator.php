<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PlanIndicatorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A target committed for one indicator, in one plan or proposal.
 *
 * @property int $id
 * @property string $source_type
 * @property int $source_id
 * @property int $indicator_id
 * @property string $target
 * @property int|null $project_id
 * @property string $period
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period'])]
class PlanIndicator extends Model
{
    /** @use HasFactory<PlanIndicatorFactory> */
    use HasFactory;

    /**
     * The plan or proposal this target was committed in.
     *
     * There is no foreign key behind source_id, so a source can be deleted out
     * from under this row. See the migrations rule on orphans.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The indicator being targeted.
     *
     * @return BelongsTo<Indicator, $this>
     */
    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    /**
     * The project this target is attributed to, if any.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The actuals reported against this target.
     *
     * @return HasMany<Report, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target' => 'decimal:2',
        ];
    }
}
