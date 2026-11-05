<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasSlug;
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
 * A delivery program: owned by tracking since W-34b (D-68).
 *
 * It used to be the thin tracking side of an HR program under the same id,
 * with the name, code, slug and active flag read live out of `hchr_programs`
 * on every page. D-68 flipped that: the columns are ours, nothing reads a
 * program out of HR any more, and tracking issues the id for everything new.
 * `programs:import-from-hr` seeded the existing rows from HR once and is the
 * only code left that reads HR's copy, as a drift report.
 *
 * What HR still owns is the manager, because it is an employee: `syncFromHr()`
 * keeps `manager_id` in step with HR's Program Manager position where there is
 * one, and a program with no such position keeps whoever was assigned here.
 *
 * @property int $id
 * @property string $name_en
 * @property string|null $name_ar
 * @property string $code
 * @property string|null $slug
 * @property bool $is_active
 * @property-read string $name
 * @property int|null $strategy_id
 * @property int|null $strategic_pillar_id
 * @property int|null $manager_id
 * @property DonorAttribution $donor_attribution
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name_en', 'name_ar', 'code', 'strategy_id', 'strategic_pillar_id', 'manager_id', 'donor_attribution'])]
#[ObservedBy(ProgramObserver::class)]
class Program extends Model implements TracksActivity
{
    /** @use HasFactory<ProgramFactory> */
    use HasFactory, HasSlug, RecordsActivity;

    /**
     * Program names and codes by id, read once per request.
     *
     * The lookups below replace the HR ones every page used to call
     * (HrProgram::namesById() and friends), so the shape of the call sites did
     * not have to change when the data came home. `is_active` is deliberately
     * not part of it: an inactive program still has to render its name
     * wherever a project or plan points at it.
     *
     * @var array<int, array{name_en: string, name_ar: ?string, code: string}>|null
     */
    private static ?array $lookup = null;

    private static ?int $lookupApp = null;

    /**
     * Mirror the column defaults so a program that has not been round-tripped
     * through the database still reports them under strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'donor_attribution' => 'shared',
        'name_en' => '',
        'code' => '',
        'is_active' => true,
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'donor_attribution' => DonorAttribution::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Forget the lookup whenever a program is written, so a rename is visible
     * to the rest of the request that made it.
     */
    public static function forgetLookup(): void
    {
        self::$lookup = null;
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
     * Keep every program's manager in step with HR's Program Manager position.
     *
     * Since W-34b (D-68) this no longer inserts rows: tracking owns the
     * programs and issues their ids, so a program HR has that we do not is
     * drift for `programs:import-from-hr` to report, not something to create
     * behind the user's back. What is left is the manager, which is an HR
     * employee: a change of holder, or a vacated seat (null), is mirrored
     * here, and a program with no such position keeps whoever was assigned in
     * tracking. The mirror is not somebody's action, so it records no activity
     * and sends nothing.
     */
    public static function syncFromHr(): void
    {
        Activity::withoutRecording(function (): void {
            $now = now();

            foreach (HrPosition::programManagerEmployeeIds() as $programId => $employeeId) {
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
     * Programs ordered for a picker by name.
     *
     * @return Collection<int, static>
     */
    public static function options(): Collection
    {
        return static::query()->select(['id', 'name_en', 'name_ar', 'manager_id'])->orderByName()->get();
    }

    /**
     * Every program's name in the current language, by id.
     *
     * @return array<int, string>
     */
    public static function namesById(): array
    {
        return array_map(static fn (array $row): string => self::localized($row['name_en'], $row['name_ar']), self::lookup());
    }

    /**
     * The name of one program, empty when there is no such program.
     */
    public static function nameOf(int $id): string
    {
        $row = self::lookup()[$id] ?? null;

        return $row === null ? '' : self::localized($row['name_en'], $row['name_ar']);
    }

    /**
     * The code of one program, empty when there is no such program.
     */
    public static function codeOf(int $id): string
    {
        return self::lookup()[$id]['code'] ?? '';
    }

    /**
     * The ids of the programs whose name contains the search, in either
     * language — a reader searching in Arabic finds a program named only in
     * English, and the other way round.
     *
     * @return list<int>
     */
    public static function idsMatchingName(string $search): array
    {
        $search = mb_strtolower(trim($search));

        return array_keys(array_filter(
            self::lookup(),
            fn (array $row): bool => $search === ''
                || str_contains(mb_strtolower($row['name_en']), $search)
                || str_contains(mb_strtolower((string) $row['name_ar']), $search),
        ));
    }

    /**
     * Every program id, ordered by name in the current language, then id.
     *
     * @return list<int>
     */
    public static function idsOrderedByName(): array
    {
        $names = self::namesById();

        uksort($names, fn (int $a, int $b): int => [mb_strtolower($names[$a]), $a] <=> [mb_strtolower($names[$b]), $b]);

        return array_keys($names);
    }

    /**
     * Name and code of every program, read once per request.
     *
     * Reloads on the same app-instance check RemembersHrLookup uses: the test
     * suite boots a fresh application per test, so without it this cache would
     * keep serving programs made by an earlier test.
     *
     * @return array<int, array{name_en: string, name_ar: ?string, code: string}>
     */
    private static function lookup(): array
    {
        $app = spl_object_id(app());

        if (self::$lookup === null || self::$lookupApp !== $app) {
            self::$lookup = static::query()->toBase()->get(['id', 'name_en', 'name_ar', 'code'])
                ->mapWithKeys(fn (object $row): array => [(int) $row->id => [
                    'name_en' => trim((string) $row->name_en),
                    'name_ar' => $row->name_ar === null ? null : trim((string) $row->name_ar),
                    'code' => trim((string) $row->code),
                ]])
                ->all();
            self::$lookupApp = $app;
        }

        return self::$lookup;
    }

    /**
     * The name to show: the Arabic one under the Arabic interface, and the
     * English one everywhere else or when there is no Arabic name — HR had a
     * single name, so `name_ar` is empty on everything imported from it.
     */
    private static function localized(string $nameEn, ?string $nameAr): string
    {
        return app()->getLocale() === 'ar' && filled($nameAr) ? (string) $nameAr : $nameEn;
    }

    /**
     * The program name in the current language.
     *
     * Falls back to the lookup when this instance came from a query that
     * selected specific columns and left the names out. The app has many
     * `select(['id', ...])` reads of programs that were written when the name
     * lived in HR and no local column existed; requiring every one of them to
     * list two more columns would be a wide, easy-to-miss surface, and strict
     * mode turns each miss into an exception. `HasSlug::getRouteKey()` solves
     * the same problem the same way: the lookup reads every program's name at
     * once, so a page of them costs one query, not one per program.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(function (): string {
            if (array_key_exists('name_en', $this->attributes)) {
                return self::localized((string) $this->attributes['name_en'], $this->attributes['name_ar'] ?? null);
            }

            return $this->getKey() === null ? '' : self::nameOf((int) $this->getKey());
        });
    }

    protected function slugSource(): string
    {
        return $this->name_en;
    }

    /**
     * Programs whose name contains the search, in either language.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function whereNameLike(Builder $query, string $search): void
    {
        $search = trim($search);

        $query->where(function (Builder $query) use ($search): void {
            $query->where('name_en', 'like', '%'.$search.'%')->orWhere('name_ar', 'like', '%'.$search.'%');
        });
    }

    /**
     * Order programs by name in the current language, then id.
     *
     * Arabic falls back to the English name, so the column cannot simply be
     * switched: `COALESCE` over a blank `name_ar` gives the same order the
     * `name` accessor shows.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function orderByName(Builder $query, string $direction = 'asc'): void
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        if (app()->getLocale() === 'ar') {
            $query->orderByRaw("coalesce(nullif(programs.name_ar, ''), programs.name_en) ".$direction);
        } else {
            $query->orderBy($query->qualifyColumn('name_en'), $direction);
        }

        $query->orderBy($query->qualifyColumn('id'), $direction);
    }

    /**
     * Only the programs still in use; an inactive one keeps rendering wherever
     * a project or plan already points at it.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * HR's own copy of this program, read **only** by
     * `programs:import-from-hr` (the one-time seed and the drift report).
     * Nothing else may reach through it: the name, code and slug are ours.
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
     * The strategic pillar this program sits under (D-35), always one of its
     * own strategy's. Null = "No pillar".
     *
     * @return BelongsTo<StrategicPillar, $this>
     */
    public function pillar(): BelongsTo
    {
        return $this->belongsTo(StrategicPillar::class, 'strategic_pillar_id');
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
        return ['manager_id' => 'manager_changed', 'strategy_id' => 'updated', 'strategic_pillar_id' => 'updated', 'donor_attribution' => 'updated'];
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
