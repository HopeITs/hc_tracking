<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasSlug;
use App\Concerns\RecordsActivity;
use App\Notifications\TracksActivity;
use App\Observers\PlanIndicatorSourceObserver;
use Carbon\CarbonImmutable;
use Database\Factories\AnnualPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $slug
 * @property int $program_id
 * @property int $manager_id
 * @property int $year
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['program_id', 'manager_id', 'year'])]
#[ObservedBy(PlanIndicatorSourceObserver::class)]
class AnnualPlan extends Model implements TracksActivity
{
    /** @use HasFactory<AnnualPlanFactory> */
    use HasFactory, HasSlug, RecordsActivity;

    /**
     * "program-slug-year" — there is no title field of its own, so the slug
     * is built from the program it covers and its fiscal year.
     */
    protected function slugSource(): string
    {
        return $this->program?->slug.'-'.$this->year;
    }

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
     * The HR employee accountable for this plan.
     *
     * @return BelongsTo<HrEmployee, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'manager_id');
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
     * Restrict the query to plans whose fiscal year falls inside an inclusive
     * range.
     *
     * The bounds are ordered here rather than trusted, so a caller that hands
     * over a reversed range gets the years between them instead of nothing.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function forYears(Builder $query, int $from, int $to): void
    {
        $query->whereBetween('year', [min($from, $to), max($from, $to)]);
    }

    /**
     * Restrict the query to plans written for any of the given fiscal years.
     *
     * The years need not be contiguous: the matrix lets a reader pick an
     * arbitrary set of columns, so a range cannot express what is on screen.
     * An empty list matches nothing, which is the honest reading of "none of
     * these years" and keeps a caller from silently widening to everything.
     *
     * @param  Builder<static>  $query
     * @param  array<int, int>  $years
     */
    #[Scope]
    protected function inYears(Builder $query, array $years): void
    {
        $query->whereIn('year', $years);
    }

    /**
     * Restrict the query to the plans one manager is accountable for.
     *
     * A null id means "any manager" and leaves the query untouched, so an
     * optional filter can be passed straight through without a conditional at
     * every call site.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function managedBy(Builder $query, ?int $managerId): void
    {
        $query->when($managerId !== null, fn (Builder $query) => $query->where('manager_id', $managerId));
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

    public function activityModule(): string
    {
        return 'annual-plans';
    }

    /**
     * "Program · Year".
     */
    public function activityLabel(): string
    {
        return Program::query()->find($this->activityAttribute('program_id'), ['id'])?->name.' · '.$this->activityAttribute('year');
    }

    public function activityProgramId(): ?int
    {
        $programId = $this->activityAttribute('program_id');

        return $programId === null ? null : (int) $programId;
    }

    /**
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return ['manager_id' => 'manager_changed', 'year' => 'updated'];
    }

    /**
     * @return list<string>
     */
    public function activityEmployeeFields(): array
    {
        return ['manager_id'];
    }

    /**
     * The manager, remembered for the deleted event.
     *
     * @return array<string, mixed>
     */
    protected function activityPropertiesBeforeDelete(): array
    {
        return ['employee_ids' => array_values(array_filter([(int) $this->activityAttribute('manager_id')]))];
    }
}
