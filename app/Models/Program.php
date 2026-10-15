<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsActivity;
use App\Enums\DonorAttribution;
use App\Notifications\TracksActivity;
use App\Observers\ProgramObserver;
use Carbon\CarbonImmutable;
use Database\Factories\ProgramFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A delivery program: the tracking side of one HR program, under the same id.
 *
 * The row exists only so local foreign keys (projects, annual plans, results)
 * and the tracking-owned links can hang off the HR program. Everything HR
 * holds, the name included, is read from HR (see HrProgram::namesById()) and
 * never copied here. Tracking owns only the strategy and manager links; the manager follows
 * HR's Program Manager position where there is one, the strategy stays
 * empty until someone assigns it.
 *
 * @property int $id
 * @property-read string $name
 * @property-read string $slug
 * @property int|null $strategy_id
 * @property int|null $manager_id
 * @property DonorAttribution $donor_attribution
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['strategy_id', 'manager_id', 'donor_attribution'])]
#[ObservedBy(ProgramObserver::class)]
class Program extends Model implements TracksActivity
{
    /** @use HasFactory<ProgramFactory> */
    use HasFactory, RecordsActivity;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'donor_attribution' => 'shared',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'donor_attribution' => DonorAttribution::class,
        ];
    }

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
     * Give every HR program its tracking row and keep its manager in step with HR.
     *
     * New HR programs get a row carrying the id. A program that has an active
     * "Program Manager" position in HR takes its manager from HR on every sync:
     * a change of holder, or a vacated seat (null), is mirrored here. A program
     * with no such position keeps whatever manager was assigned in tracking.
     * Programs that have disappeared from HR are kept so the projects and plans
     * under them survive. The HR mirror is not somebody's action, so it records
     * no activity.
     */
    public static function syncFromHr(): void
    {
        Activity::withoutRecording(function (): void {
            $now = now();
            $managers = HrPosition::programManagerEmployeeIds();

            $rows = HrProgram::query()
                ->pluck('id')
                ->map(fn (int $id): array => [
                    'id' => $id,
                    'manager_id' => $managers[$id] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            if ($rows !== []) {
                static::query()->toBase()->insertOrIgnore($rows);
            }

            foreach ($managers as $programId => $employeeId) {
                static::query()->toBase()
                    ->where('id', $programId)
                    ->where(fn ($query) => $employeeId === null
                        ? $query->whereNotNull('manager_id')
                        : $query->whereNull('manager_id')->orWhere('manager_id', '!=', $employeeId))
                    ->update(['manager_id' => $employeeId, 'updated_at' => $now]);
            }
        });
    }

    /**
     * Programs ordered for a picker by their HR name.
     *
     * @return Collection<int, static>
     */
    public static function options(): Collection
    {
        return static::query()->select(['id', 'manager_id'])->orderByName()->get();
    }

    /**
     * The program name, read from HR.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => HrProgram::nameOf($this->id));
    }

    /**
     * The program slug, read from HR — used as the route key so
     * `programs/{program}` shows a readable slug instead of the id.
     *
     * @return Attribute<string, never>
     */
    protected function slug(): Attribute
    {
        return Attribute::get(fn (): string => HrProgram::slugOf($this->id));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Resolve `{program}` from its HR slug instead of the local id column,
     * which the `slug` accessor above is not backed by.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $id = HrProgram::idBySlug((string) $value);

        return $id === null ? null : static::query()->find($id);
    }

    /**
     * Programs whose HR name contains the search.
     *
     * The name lives in HR, so the matching ids are read from HR first.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function whereNameLike(Builder $query, string $search): void
    {
        $query->whereIn($query->qualifyColumn('id'), HrProgram::idsMatchingName($search));
    }

    /**
     * Order programs by their HR name, then id.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function orderByName(Builder $query, string $direction = 'asc'): void
    {
        $ids = HrProgram::idsOrderedByName();

        if ($ids !== []) {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $query->orderByRaw('FIELD(programs.id, '.$placeholders.') '.($direction === 'desc' ? 'desc' : 'asc'), $ids);
        }

        $query->orderBy($query->qualifyColumn('id'), $direction === 'desc' ? 'desc' : 'asc');
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
     * The logframe results this program is measured by: its program
     * framework only. A proposal framework's nodes carry the program too but
     * belong to the proposal (contracts §11.2, Proposal::results()).
     *
     * @return HasMany<Result, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(Result::class)->programFramework();
    }

    public function activityModule(): string
    {
        return 'programs';
    }

    public function activityLabel(): string
    {
        return $this->name;
    }

    public function activityProgramId(): ?int
    {
        return $this->getKey() === null ? null : (int) $this->getKey();
    }

    /**
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return ['manager_id' => 'manager_changed', 'strategy_id' => 'updated', 'donor_attribution' => 'updated'];
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
        return ['employee_ids' => array_filter([(int) $this->activityAttribute('manager_id')])];
    }
}
