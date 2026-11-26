<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Locale;
use App\Support\EmployeeProfile;
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
use Illuminate\Support\Facades\DB;

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

    /** Bumped whenever the picker list's shape changes, so browsers drop the copy they cached for five minutes. */
    public const int PickerListVersion = 2;

    private const PickerCacheKeyPrefix = 'hr.employees.picker.';

    private const PickerCacheSeconds = 300;

    private const AvatarCacheKey = 'hr.employees.avatars';

    /**
     * Where each Spatie disk of the HR app keeps its files, inside HR's storage/app.
     *
     * @var array<string, string>
     */
    private const AvatarDiskFolders = ['public' => 'public', 'local' => 'private'];

    /**
     * HR's own picture for an employee with no photo, copied into public/images.
     */
    public const DefaultAvatar = 'images/default-avatar.png';

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
    public static function idsMatchingName(string $search, bool $acrossLayouts = true): array
    {
        if (trim(Like::term($search)) === '') {
            return [];
        }

        return array_values(static::query()
            ->where(Like::everyWord($search, fn ($query, string $word) => $query
                ->where(Like::word('first_name_en', $word))
                ->orWhere(Like::word('last_name_en', $word))
                ->orWhere(Like::word('first_name_ar', $word))
                ->orWhere(Like::word('last_name_ar', $word)), $acrossLayouts))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * Where the employee's HR photo sits on the `hr` disk, or null without one.
     *
     * HR keeps photos as Spatie media in the `avatar` collection. Every photo
     * row is read in one query and cached for five minutes, so a page showing
     * many people asks HR once. Only image files with a plain file name count.
     *
     * @return array{path: string, mime: string, version: int}|null
     */
    public static function avatarOf(int $employeeId): ?array
    {
        /** @var array<int, array{path: string, mime: string, version: int}> $avatars */
        $avatars = Cache::memo()->remember(self::AvatarCacheKey, self::PickerCacheSeconds, function (): array {
            $avatars = [];

            $rows = DB::connection('hr')->table('media')
                ->select(['id', 'model_id', 'file_name', 'mime_type', 'disk', 'updated_at'])
                ->where('model_type', 'App\Models\Employee')
                ->where('collection_name', 'avatar')
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $folder = self::AvatarDiskFolders[$row->disk] ?? null;
                $fileName = (string) $row->file_name;

                if ($folder === null || $fileName === '' || basename($fileName) !== $fileName || ! str_starts_with((string) $row->mime_type, 'image/')) {
                    continue;
                }

                $avatars[(int) $row->model_id] = [
                    'path' => $folder.'/'.$row->id.'/'.$fileName,
                    'mime' => (string) $row->mime_type,
                    'version' => $row->updated_at === null ? (int) $row->id : (int) strtotime((string) $row->updated_at),
                ];
            }

            return $avatars;
        });

        return $avatars[$employeeId] ?? null;
    }

    /**
     * The URL of the employee's photo, or HR's default picture without one.
     *
     * The photo URL goes through EmployeeAvatarController, which needs a
     * signed-in user; `v` changes when HR replaces the photo, so browsers
     * fetch the new one.
     */
    public static function avatarUrl(?int $employeeId): string
    {
        return self::photoUrl($employeeId) ?? asset(self::DefaultAvatar);
    }

    /**
     * The URL of the employee's own HR photo, or null without one (for
     * places that show something else rather than the default picture).
     */
    public static function photoUrl(?int $employeeId): ?string
    {
        $avatar = $employeeId === null ? null : self::avatarOf($employeeId);

        if ($avatar === null) {
            return null;
        }

        return route('employees.avatar', ['employee' => $employeeId, 'v' => $avatar['version']]);
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
     * The picker list as plain `{value, label, detail, photo, inactive}` rows,
     * label being the main and secondary name together, detail the person's
     * current position and photo their picture (HR's default without one; a
     * host-less URL, as the list is cached for everyone), in
     * the order pickerOptions() sorts them. Someone with no active contract
     * (or inactive in HR) is flagged inactive.
     *
     * @return SupportCollection<int, array{value: string, label: string, detail: string, photo: string, inactive: bool}>
     */
    public static function pickerLabels(): SupportCollection
    {
        $suffix = Locale::current() === Locale::Arabic ? 'ar' : 'en';

        return collect(Cache::memo()->remember(
            self::PickerCacheKeyPrefix.'labels-with-photo.'.$suffix,
            self::PickerCacheSeconds,
            fn (): array => static::pickerOptions()->map(fn (self $employee): array => [
                'value' => (string) $employee->id,
                'label' => $employee->name.' · '.$employee->secondary_name,
                'detail' => static::positionSummary($employee->id) ?? (string) __('common.no_active_position'),
                'photo' => self::pathOnly(static::avatarUrl($employee->id)),
                'inactive' => ! (EmployeeProfile::for($employee->id)['is_active'] ?? false),
            ])->all(),
        ));
    }

    /**
     * A same-site URL without its scheme and host. The picker list is cached
     * for every visitor, so an absolute photo URL would carry the host of
     * whoever built it (localhost vs 127.0.0.1, or another domain) and the
     * browser would send no sign-in cookie to it.
     */
    private static function pathOnly(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        $path = is_string($path) ? $path : '/';

        return is_string($query) ? $path.'?'.$query : $path;
    }

    /**
     * Where an employee sits now, for chips and pickers: «position · job level ·
     * department · center» in the interface language, null without an active
     * contract. The job level is left out when it only repeats the position
     * title. Reads EmployeeProfile and the in-memory HR lookups only.
     */
    public static function positionSummary(int $employeeId): ?string
    {
        $profile = EmployeeProfile::for($employeeId);

        if ($profile === null) {
            return null;
        }

        $title = Locale::current() === Locale::Arabic && trim((string) $profile['position_title_ar']) !== ''
            ? $profile['position_title_ar']
            : $profile['position_title_en'];

        $jobLevel = $profile['job_level'];

        if (mb_strtolower(trim((string) $jobLevel)) === mb_strtolower(trim((string) $title))) {
            $jobLevel = null;
        }

        return collect([
            $title,
            $jobLevel,
            $profile['department_id'] === null ? null : HrDepartment::nameOf($profile['department_id']),
            $profile['center_id'] === null ? null : HrCenter::remembered($profile['center_id'])?->name,
        ])->map(fn (?string $part): string => trim((string) $part))->filter()->implode(' · ');
    }

    /**
     * Drop the cached picker lists so the next request reloads them from HR.
     */
    public static function forgetPickerOptions(): void
    {
        foreach (['en', 'ar'] as $suffix) {
            Cache::memo()->forget(self::PickerCacheKeyPrefix.$suffix);
            Cache::memo()->forget(self::PickerCacheKeyPrefix.'labels-with-photo.'.$suffix);
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
     * The places this employee is responsible for (Y-16, D-93): the twin of
     * `projects()`, which goes with the `projects` table in Y-18.
     *
     * @return HasMany<ProposalLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(ProposalLocation::class, 'responsible_employee_id');
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
     * departments, which EmployeeProfile reads (never a contract's pay
     * columns). An employee with more than one active contract resolves to
     * the most recently started one.
     */
    public function currentDepartmentId(): ?int
    {
        return EmployeeProfile::for($this->id)['department_id'] ?? null;
    }
}
