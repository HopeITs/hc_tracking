<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\PlanIndicatorSourceObserver;
use Carbon\CarbonImmutable;
use Database\Factories\YearlyPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $program_id
 * @property int $manager_id
 * @property int $year
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['program_id', 'manager_id', 'year'])]
#[ObservedBy(PlanIndicatorSourceObserver::class)]
class YearlyPlan extends Model
{
    /** @use HasFactory<YearlyPlanFactory> */
    use HasFactory;

    /**
     * Delete the model inside a transaction.
     *
     * Its observer deletes the targets committed in this plan, which no foreign
     * key can reach. Those deletions bracket the delete of
     * this row, so a failure partway through must not leave them committed.
     */
    public function delete(): bool
    {
        return (bool) DB::transaction(fn () => parent::delete());
    }

    /**
     * The program this plan covers.
     *
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * The user accountable for this plan.
     *
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * The indicator targets committed in this plan.
     *
     * @return MorphMany<PlanIndicator, $this>
     */
    public function planIndicators(): MorphMany
    {
        return $this->morphMany(PlanIndicator::class, 'source');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
        ];
    }
}
