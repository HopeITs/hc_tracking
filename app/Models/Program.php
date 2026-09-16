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
 * A delivery program, mirroring one HR program under the same id.
 *
 * `name` is a copy of the HR name refreshed by syncFromHr() and is never edited
 * here; tracking owns only the strategy and manager links, which stay empty
 * until someone assigns them.
 *
 * @property int $id
 * @property string $name
 * @property int|null $strategy_id
 * @property int|null $manager_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['strategy_id', 'manager_id'])]
#[ObservedBy(ProgramObserver::class)]
class Program extends Model
{
    /** @use HasFactory<ProgramFactory> */
    use HasFactory;

    /**
     * Delete the model inside a transaction.
     *
     * Its observer deletes this program's annual plans, whose own observer
     * reaches the targets planned in them. Those deletions bracket the delete of
     * this row, so a failure partway through must not leave them committed.
     */
    public function delete(): bool
    {
        return (bool) DB::transaction(fn () => parent::delete());
    }

    /**
     * Mirror every HR program: add new ones and refresh the names of known ones.
     *
     * Strategy and manager links are never touched, and programs that have
     * disappeared from HR are kept so the projects and plans under them survive.
     */
    public static function syncFromHr(): void
    {
        $now = now();

        $rows = HrProgram::query()
            ->get(['id', 'name'])
            ->map(fn (HrProgram $hrProgram): array => [
                'id' => $hrProgram->id,
                'name' => trim($hrProgram->name),
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            static::query()->toBase()->upsert($rows, ['id'], ['name']);
        }
    }

    /**
     * The HR program this row mirrors.
     *
     * @return BelongsTo<HrProgram, $this>
     */
    public function hrProgram(): BelongsTo
    {
        return $this->belongsTo(HrProgram::class, 'id');
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
     * The HR employee accountable for this program.
     *
     * @return BelongsTo<HrEmployee, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'manager_id');
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
     * @return HasMany<AnnualPlan, $this>
     */
    public function annualPlans(): HasMany
    {
        return $this->hasMany(AnnualPlan::class);
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
