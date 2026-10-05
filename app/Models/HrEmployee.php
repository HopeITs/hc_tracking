<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Locale;
use App\Support\Like;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * An employee from the HR database (hchr_employees).
 *
 * Only the columns hc-tracking needs are documented; personal fields exist in
 * the table but are hidden from serialization.
 *
 * @property int $id
 * @property int|null $city_id
 * @property string $first_name_en
 * @property string $first_name_ar
 * @property string $last_name_en
 * @property string $last_name_ar
 * @property string $username
 * @property bool $is_active
 * @property bool $is_board_member
 * @property-read string $name
 * @property-read string $secondary_name
 * @property-read string $name_en
 * @property-read string $name_ar
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Hidden([
    'national_number', 'national_number_index', 'actual_dob', 'dob', 'civil_registry_office',
    'civil_registry_number', 'personal_phone', 'personal_email', 'address_en', 'address_ar',
    'digital_id_token',
])]
class HrEmployee extends HrModel
{
    /**
     * The columns needed to show an employee by name, for eager loads and pickers.
     *
     * @var list<string>
     */
    public const NameColumns = ['id', 'first_name_en', 'last_name_en', 'first_name_ar', 'last_name_ar'];

    private const PickerCacheKeyPrefix = 'hr.employees.picker.';

    private const PickerCacheSeconds = 300;

    protected $table = 'employees';

    /**
     * The ids of employees whose English or Arabic name matches the search.
     *
     * Every word of the search must appear in one of the name columns, so both
     * "Sami" and "Sami Haddad" find Sami Haddad. Employees live in the HR
     * database, so a tracking query searching by a person's name filters on
     * these ids instead of a whereHas() across connections.
     *
     * @return list<int>
     */
    public static function idsMatchingName(string $search): array
    {
        $words = preg_split('/\s+/u', Like::term($search), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return [];
        }

        $query = static::query();

        foreach ($words as $word) {
            $query->where(fn ($query) => $query
                ->where('first_name_en', 'like', Like::contains($word))
                ->orWhere('last_name_en', 'like', Like::contains($word))
                ->orWhere('first_name_ar', 'like', Like::contains($word))
                ->orWhere('last_name_ar', 'like', Like::contains($word)));
        }

        return $query->pluck('id')->all();
    }

    /**
     * Employees ordered for a picker, with only the columns a picker shows.
     *
     * Every employee is offered, active or not, exactly as HR holds them.
     *
     * Every assignee dropdown on every page asks for this list on each request,
     * so the raw rows are cached for five minutes per sort language instead of
     * hitting the HR database each time, and held in memory for the rest of the
     * request so a page with several dropdowns reads the cache once.
     *
     * @return Collection<int, static>
     */
    public static function pickerOptions(): Collection
    {
        $suffix = Locale::current() === Locale::Arabic ? 'ar' : 'en';

        $rows = Cache::memo()->remember(
            self::PickerCacheKeyPrefix.$suffix,
            self::PickerCacheSeconds,
            fn (): array => static::query()
                ->select(self::NameColumns)
                ->orderBy('first_name_'.$suffix)
                ->orderBy('last_name_'.$suffix)
                ->orderBy('id')
                ->toBase()
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        );

        return static::hydrate($rows);
    }

    /**
     * The picker list as plain `{value, label}` rows, label being the main and
     * secondary name together, in the order pickerOptions() sorts them.
     *
     * @return SupportCollection<int, array{value: string, label: string}>
     */
    public static function pickerLabels(): SupportCollection
    {
        $suffix = Locale::current() === Locale::Arabic ? 'ar' : 'en';

        return collect(Cache::memo()->remember(
            self::PickerCacheKeyPrefix.'labels.'.$suffix,
            self::PickerCacheSeconds,
            fn (): array => static::pickerOptions()->map(fn (self $employee): array => [
                'value' => (string) $employee->id,
                'label' => $employee->name.' · '.$employee->secondary_name,
            ])->all(),
        ));
    }

    /**
     * Drop the cached picker lists so the next request reloads them from HR.
     */
    public static function forgetPickerOptions(): void
    {
        foreach (['en', 'ar'] as $suffix) {
            Cache::memo()->forget(self::PickerCacheKeyPrefix.$suffix);
            Cache::memo()->forget(self::PickerCacheKeyPrefix.'labels.'.$suffix);
        }
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_board_member' => 'boolean',
        ];
    }

    /**
     * The employee's name in the interface language: Arabic when the site is
     * in Arabic (falling back to English when HR has no Arabic name), English
     * otherwise.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => Locale::current() === Locale::Arabic
            ? ($this->name_ar !== '' ? $this->name_ar : $this->name_en)
            : $this->name_en);
    }

    /**
     * The employee's name in the other language, shown beside the main name.
     *
     * @return Attribute<string, never>
     */
    protected function secondaryName(): Attribute
    {
        return Attribute::get(fn (): string => Locale::current() === Locale::Arabic ? $this->name_en : $this->name_ar);
    }

    /**
     * Initials from the English name, matching User::initials().
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name_en, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * The programs this employee manages.
     *
     * @return HasMany<Program, $this>
     */
    public function managedPrograms(): HasMany
    {
        return $this->hasMany(Program::class, 'manager_id');
    }

    /**
     * The annual plans this employee manages.
     *
     * @return HasMany<AnnualPlan, $this>
     */
    public function managedAnnualPlans(): HasMany
    {
        return $this->hasMany(AnnualPlan::class, 'manager_id');
    }

    /**
     * The projects this employee is responsible for.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'user_id');
    }

    /**
     * The milestones this employee is accountable for.
     *
     * @return HasMany<Milestone, $this>
     */
    public function responsibleMilestones(): HasMany
    {
        return $this->hasMany(Milestone::class, 'responsible_user_id');
    }

    /**
     * The tasks assigned to this employee (a task may have several assignees).
     *
     * A task and its task_assignees pivot both live on the default connection,
     * so this still resolves in one query even though HrEmployee itself is on
     * the separate `hr` connection — the join happens entirely on the far
     * side, parameterised by this employee's id rather than joined to it.
     *
     * @return HasManyThrough<Task, TaskAssignee, $this>
     */
    public function assignedTasks(): HasManyThrough
    {
        return $this->hasManyThrough(Task::class, TaskAssignee::class, 'employee_id', 'id', 'id', 'task_id');
    }

    /**
     * The employee's full English name.
     *
     * @return Attribute<string, never>
     */
    protected function nameEn(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name_en.' '.$this->last_name_en));
    }

    /**
     * The employee's full Arabic name.
     *
     * @return Attribute<string, never>
     */
    protected function nameAr(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name_ar.' '.$this->last_name_ar));
    }

    /**
     * The city the employee lives in.
     *
     * @return BelongsTo<HrCity, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(HrCity::class, 'city_id');
    }

    /**
     * The HR login account of this employee.
     *
     * @return HasOne<HrUser, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(HrUser::class, 'employee_id');
    }

    /**
     * Every contract this employee has held, current or past.
     *
     * @return HasMany<HrContract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(HrContract::class, 'employee_id');
    }

    /**
     * The department of this employee's current position, resolved through
     * their active contract.
     *
     * There is no department_id on the employee row itself — department is
     * only reachable via employees -> contracts (is_active) -> positions ->
     * departments. Kept as a plain query rather than a hasOneThrough chain
     * across two intermediate tables, matching the "resolve ids first"
     * convention the rest of this class uses for cross-connection lookups.
     * An employee with more than one active contract (should not happen)
     * resolves to the most recently started one.
     */
    public function currentDepartmentId(): ?int
    {
        return HrContract::query()
            ->where('employee_id', $this->id)
            ->where('is_active', true)
            ->orderByDesc('start_date')
            ->with('position')
            ->first()
            ?->position
            ?->department_id;
    }
}
