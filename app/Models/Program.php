<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\ProgramObserver;
use Carbon\CarbonImmutable;
use Database\Factories\ProgramFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $name
 * @property int $strategy_id
 * @property int $manager_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'strategy_id', 'manager_id'])]
#[ObservedBy(ProgramObserver::class)]
class Program extends Model
{
    /** @use HasFactory<ProgramFactory> */
    use HasFactory;

    /**
     * Delete the model inside a transaction.
     *
     * Its observer deletes this program's yearly plans, whose own observer
     * reaches the targets planned in them. Those deletions bracket the delete of
     * this row, so a failure partway through must not leave them committed.
     */
    public function delete(): bool
    {
        return (bool) DB::transaction(fn () => parent::delete());
    }

    /**
     * The strategy this program delivers against.
     *
     * @return BelongsTo<Strategy, $this>
     */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    /**
     * The user accountable for this program.
     *
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * The projects delivered under this program.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * The annual operational plans written for this program.
     *
     * @return HasMany<YearlyPlan, $this>
     */
    public function yearlyPlans(): HasMany
    {
        return $this->hasMany(YearlyPlan::class);
    }

    /**
     * The logframe results this program is measured by.
     *
     * @return HasMany<Result, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }
}
