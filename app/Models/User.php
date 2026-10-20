<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\RecordsActivity;
use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Notifications\ActivityRecorder;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use PDOException;
use Spatie\Permission\Traits\HasRoles;

/**
 * A person who can use hc-tracking.
 *
 * They log in with their HR account (see AuthenticateWithHrAccount), linked by
 * hr_user_id. This row holds only what HR does not: two-factor settings,
 * passkeys, the interface language and (via Spatie\Permission\Traits\HasRoles)
 * role/permission assignments. Name, email and password live in HR only;
 * `name` and `email` are read from there (see HrUser::directory()), never
 * copied. `employee_id` is the HR employee id, refreshed at each login.
 *
 * @property int $id
 * @property-read string $name
 * @property-read string $email
 * @property int $hr_user_id
 * @property int|null $employee_id
 * @property int|null $active_role_id
 * @property string|null $locale
 * @property array<mixed>|null $desk_layout the saved Desk arrangement, list<array{panel: string, size: string}> as written; read through Panels::layoutFor(), which drops anything malformed
 * @property bool $notification_popups
 * @property CarbonImmutable|null $tour_seen_at
 * @property CarbonImmutable|null $email_verified_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property bool $email_two_factor_enabled
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Hidden(['two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, PasskeyUser, TracksActivity
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, RecordsActivity, TwoFactorAuthenticatable;

    /**
     * Mirror the columns' DB defaults so a user that has not been round-tripped
     * through the database still reports them under strict mode: no language
     * chosen, no linked HR employee, no desktop notification popups and no
     * emailed sign-in codes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'employee_id' => null,
        'locale' => null,
        'desk_layout' => null,
        'notification_popups' => false,
        'tour_seen_at' => null,
        'email_two_factor_enabled' => false,
    ];

    /**
     * Remove the inbox with the user: notifications are polymorphic, so no
     * foreign key can cascade them.
     */
    protected static function booted(): void
    {
        static::deleted(function (User $user): void {
            UserNotification::query()->forUser($user)->delete();
        });
    }

    /**
     * This user's inbox, newest first, as UserNotification rows.
     *
     * @return MorphMany<UserNotification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(UserNotification::class, 'notifiable')->latest();
    }

    /**
     * The language notifications are written in for this user.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * The browsers that skip this user's second sign-in step.
     *
     * @return HasMany<TrustedDevice, $this>
     */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    /**
     * The tasks this user raised.
     *
     * @return HasMany<Task, $this>
     */
    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    /**
     * The reports this user has submitted.
     *
     * @return HasMany<Report, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * The files this user uploaded.
     *
     * `media.uploaded_by` restricts on delete, so a user who has uploaded
     * anything cannot be removed.
     *
     * @return HasMany<Media, $this>
     */
    public function uploadedMedia(): HasMany
    {
        return $this->hasMany(Media::class, 'uploaded_by');
    }

    /**
     * The indicator readings this user recorded.
     *
     * `indicator_progress.recorded_by` restricts on delete, matching
     * reports.user_id and task_updates.user_id.
     *
     * @return HasMany<IndicatorProgress, $this>
     */
    public function indicatorProgress(): HasMany
    {
        return $this->hasMany(IndicatorProgress::class, 'recorded_by');
    }

    /**
     * Delete the model inside a transaction.
     *
     * HasRoles and HasPermissions detach the user's roles and direct
     * permissions while deleting. When a restricting foreign key (reports,
     * uploads, created tasks) then refuses the delete, the detach must be rolled
     * back with it, or a user who is kept silently loses their access.
     */
    public function delete(): bool
    {
        return (bool) DB::transaction(fn () => parent::delete());
    }

    /**
     * The tracking user for an HR account, created on first use.
     *
     * Only active HR accounts (see HrUser::isActive()) may use tracking; the
     * caller checks that first. A new user starts with the default role
     * (config('access-control.default_role'), Team Member), except an email
     * listed in auth.hr_admin_emails, which starts as Super Admin.
     */
    public static function forHrAccount(HrUser $hrUser): self
    {
        $user = static::query()->where('hr_user_id', $hrUser->id)->first();

        if ($user !== null) {
            return $user;
        }

        $role = in_array(mb_strtolower($hrUser->email), config('auth.hr_admin_emails', []), true)
            ? 'Super Admin'
            : (string) config('access-control.default_role');

        return static::createFromHrAccount($hrUser, $role);
    }

    /**
     * Give every active HR account its tracking user, and refresh the HR
     * employee link of existing ones.
     *
     * Roles are never touched. Name and email need no refreshing: they are
     * read from HR, not copied. The HR mirror is not somebody's action, so it
     * records no activity.
     *
     * @return array{created: int, updated: int}
     */
    public static function syncFromHrAccounts(): array
    {
        return Activity::withoutRecording(function (): array {
            $usersByHrUserId = static::query()->get(['id', 'hr_user_id', 'employee_id'])->keyBy('hr_user_id');
            $created = 0;
            $updated = 0;

            HrUser::query()->active()->orderBy('id')->each(function (HrUser $hrUser) use ($usersByHrUserId, &$created, &$updated): void {
                $user = $usersByHrUserId->get($hrUser->id);

                if ($user === null) {
                    static::forHrAccount($hrUser);
                    $created++;

                    return;
                }

                if ($user->linkToHrAccount($hrUser)) {
                    $updated++;
                }
            });

            return ['created' => $created, 'updated' => $updated];
        });
    }

    /**
     * Delete the tracking users whose HR account is gone or no longer active.
     *
     * A user still referenced by records that restrict deletion (reports,
     * indicator readings, uploads, created tasks) is kept, roles included.
     * Part of the HR mirror, so it records no activity.
     *
     * @return array{deleted: int, kept: list<string>}
     */
    public static function pruneInactiveHrAccounts(): array
    {
        return Activity::withoutRecording(function (): array {
            $activeIds = HrUser::query()->active()->pluck('id')->flip();
            $deleted = 0;
            $kept = [];

            static::query()->orderBy('id')->each(function (User $user) use ($activeIds, &$deleted, &$kept): void {
                if ($activeIds->has($user->hr_user_id)) {
                    return;
                }

                try {
                    $user->delete();
                    $deleted++;
                } catch (QueryException) {
                    $kept[] = $user->email !== '' ? $user->email : "#{$user->id}";
                }
            });

            return ['deleted' => $deleted, 'kept' => $kept];
        });
    }

    /**
     * Give an HR account access to hc-tracking, optionally with a role.
     *
     * Only the link to HR is stored: name, email and password stay in HR, and
     * people always log in with their HR password.
     */
    public static function createFromHrAccount(HrUser $hrUser, ?string $roleName, bool $verified = true): self
    {
        $user = new self;

        $user->forceFill([
            'hr_user_id' => $hrUser->id,
            'employee_id' => $hrUser->employee_id,
            'email_verified_at' => $verified ? now() : null,
        ])->save();

        if ($roleName !== null) {
            $role = Role::findOrCreate($roleName);
            $user->assignRole($role);
            $user->setActiveRole($role->id);
            $user->recordRoleChange([], [$role->name]);
        }

        return $user;
    }

    /**
     * The role shown as this user's primary role.
     *
     * A user can hold several roles at once (see HasRoles); this is display
     * only — every assigned role still contributes its permissions (Spatie's
     * normal additive behavior). It does not narrow what the user can do.
     *
     * @return BelongsTo<Role, $this>
     */
    public function activeRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'active_role_id');
    }

    /**
     * Mark one of this user's already-assigned roles as active.
     *
     * Silently no-ops for a role the user does not hold, so a stale request
     * can never point active_role_id at a role that was just unassigned.
     */
    public function setActiveRole(?int $roleId): void
    {
        if ($roleId !== null && ! $this->roles()->whereKey($roleId)->exists()) {
            return;
        }

        $this->forceFill(['active_role_id' => $roleId])->save();
    }

    /**
     * Refresh the HR employee this user's account belongs to.
     *
     * Only tracking columns are written; the HR account is read, never changed.
     *
     * @return bool Whether anything changed.
     */
    public function linkToHrAccount(HrUser $hrUser): bool
    {
        $this->forceFill([
            'hr_user_id' => $hrUser->id,
            'employee_id' => $hrUser->employee_id,
        ]);

        if (! $this->isDirty()) {
            return false;
        }

        return $this->save();
    }

    /**
     * Whether each password sign-in must be followed by an emailed code: the
     * user turned it on, or their role requires two-factor authentication.
     */
    public function usesEmailTwoFactor(): bool
    {
        return ($this->email_two_factor_enabled && $this->email !== '')
            || EnsureTwoFactorIsEnabled::emailCodeRequiredFor($this);
    }

    /**
     * Whether HR has deactivated or removed the account this user logs in with.
     */
    public function hasLostHrAccess(): bool
    {
        $hrUser = HrUser::query()->with('employee')->find($this->hr_user_id);

        return $hrUser === null || ! $hrUser->isActive();
    }

    /**
     * The user's name, read from their HR employee record (English), or the
     * HR email for an account with no employee.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => $this->hrDirectoryEntry()['name'] ?? '');
    }

    /**
     * The user's email, read from their HR account.
     *
     * @return Attribute<string, never>
     */
    protected function email(): Attribute
    {
        return Attribute::get(fn (): string => $this->hrDirectoryEntry()['email'] ?? '');
    }

    /**
     * This user's HR directory entry, or null when HR no longer has the account.
     *
     * The column is NOT NULL, but a user built in memory and not saved yet
     * (createFromHrAccount() before forceFill, factory make()) has no
     * hr_user_id, so the attribute is read raw rather than through the
     * `int` property type (and may arrive as a numeric string, depending on
     * the database driver).
     *
     * @return array{email: string, name: string}|null
     */
    private function hrDirectoryEntry(): ?array
    {
        $hrUserId = $this->getAttribute('hr_user_id');

        return is_numeric($hrUserId) ? HrUser::directoryEntry((int) $hrUserId) : null;
    }

    /**
     * The HR password hash, or an empty string when HR has no account for
     * this user. No password is stored here: people log in with their HR
     * password.
     *
     * The session guard fingerprints this into the session at login and into
     * the remember-me cookie, so once HR changes the password a remember
     * cookie issued before stops working and EnsureHrAccountIsActive signs
     * open sessions out. Read from HR once per user instance (one query), so a
     * login that also queues a remember cookie asks HR only once; the guard
     * loads a new instance on every request, so the hash is never stale.
     *
     * When HR cannot be reached the failure is reported and a random value is
     * returned that no session or cookie can match: a remember cookie then
     * signs nobody in (the visitor sees the login page instead of an error),
     * and a cookie from before sessions followed the HR password is never
     * accepted again.
     */
    public function getAuthPassword(): string
    {
        try {
            return once(fn (): string => (string) HrUser::query()->whereKey($this->hr_user_id)->value('password'));
        } catch (QueryException|PDOException $exception) {
            report($exception);

            return Str::random(64);
        }
    }

    /**
     * Users whose HR account may still use tracking (see HrUser::isActive()).
     *
     * HR lives on another connection, so the active account ids are read from
     * HR first instead of a whereHas() across databases.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withActiveHrAccount(Builder $query): void
    {
        $query->whereIn('hr_user_id', HrUser::query()->active()->pluck('id'));
    }

    /**
     * Users whose HR account is gone or inactive, the complement of
     * withActiveHrAccount().
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withoutActiveHrAccount(Builder $query): void
    {
        $query->whereNotIn('hr_user_id', HrUser::query()->active()->pluck('id'));
    }

    /**
     * Users whose HR email or employee name contains the search.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function whereNameOrEmailLike(Builder $query, string $search): void
    {
        $query->whereIn('hr_user_id', HrUser::idsMatching($search));
    }

    /**
     * Order users by the name read from HR, then id.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function orderByName(Builder $query): void
    {
        $ids = HrUser::idsOrderedByName();

        if ($ids !== []) {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $query->orderByRaw('FIELD(users.hr_user_id, '.$placeholders.')', $ids);
        }

        $query->orderBy($query->qualifyColumn('id'));
    }

    /**
     * The HR login account this user authenticates with.
     *
     * @return BelongsTo<HrUser, $this>
     */
    public function hrUser(): BelongsTo
    {
        return $this->belongsTo(HrUser::class, 'hr_user_id');
    }

    /**
     * The HR employee record of this user.
     *
     * @return BelongsTo<HrEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    /**
     * The HR department of this user's current position, or null when they
     * have no linked employee or no active contract.
     */
    public function departmentId(): ?int
    {
        // An explicit load, not a lazy one: this runs for users read in bulk
        // too (notification recipients), where lazy loading is refused.
        return $this->loadMissing('employee')->employee?->currentDepartmentId();
    }

    /**
     * HR employee ids in this user's current department. Memoized per user
     * instance, since task visibility reads it on every task query.
     *
     * @return list<int>
     */
    public function departmentEmployeeIds(): array
    {
        return once(function (): array {
            $departmentId = $this->departmentId();

            return $departmentId === null
                ? []
                : array_map('intval', HrDepartment::employeeIdsInDepartment($departmentId));
        });
    }

    /**
     * Tracking user ids of everyone in this user's current HR department.
     * Memoized per user instance, since task edit checks read it per task.
     *
     * @return list<int>
     */
    public function departmentUserIds(): array
    {
        return once(fn (): array => $this->departmentEmployeeIds() === []
            ? []
            : array_values(static::query()->whereIn('employee_id', $this->departmentEmployeeIds())->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()));
    }

    /**
     * Whether this user is named responsible on any donor report (contracts
     * §14.3), for the My Reporting Duties sidebar item. One exists query,
     * memoized per user instance.
     */
    public function hasReportingDuties(): bool
    {
        return once(fn (): bool => $this->employee_id !== null
            && DonorReport::query()->where('responsible_employee_id', $this->employee_id)->exists());
    }

    /**
     * Programs this user manages (programs.manager_id is their HR employee).
     * Memoized per user instance.
     *
     * @return list<int>
     */
    public function managedProgramIds(): array
    {
        return once(fn (): array => $this->employee_id === null
            ? []
            : array_values(Program::query()->where('manager_id', $this->employee_id)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()));
    }

    /**
     * Projects this user answers for: the ones they own (projects.user_id is
     * their HR employee) and every project in a program they manage.
     * Memoized per user instance.
     *
     * @return list<int>
     */
    public function ownedProjectIds(): array
    {
        return once(function (): array {
            if ($this->employee_id === null) {
                return [];
            }

            $programIds = $this->managedProgramIds();

            return array_values(Project::query()
                ->where(fn (Builder $query) => $query
                    ->where('user_id', $this->employee_id)
                    ->when($programIds !== [], fn (Builder $query) => $query->orWhereIn('program_id', $programIds)))
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all());
        });
    }

    /**
     * Whether this user sees every department's tasks by default, with a
     * filter to narrow down, instead of being hard-scoped to their own.
     *
     * Drives whether the department filter dropdown is shown at all.
     */
    public function canViewAllTaskDepartments(): bool
    {
        return $this->hasAnyRole(config('access-control.task_department_scope_bypass_roles', []));
    }

    /**
     * Whether this user's default task visibility is hard-scoped to their
     * own HR department, with no filter able to widen it.
     *
     * Only the named team roles are scoped this way, and a bypass role always
     * wins: someone holding both (e.g. Managers and Team Member) sees every
     * department. An account holding neither sees every department too.
     */
    public function isTaskDepartmentScoped(): bool
    {
        return $this->hasAnyRole(config('access-control.task_department_scoped_roles', []))
            && ! $this->canViewAllTaskDepartments();
    }

    /**
     * Get the user initials.
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'email_two_factor_enabled' => 'boolean',
            'notification_popups' => 'boolean',
            'tour_seen_at' => 'datetime',
            'desk_layout' => 'array',
        ];
    }

    public function activityModule(): string
    {
        return 'access-management';
    }

    public function activityLabel(): string
    {
        return $this->name;
    }

    public function activityProgramId(): ?int
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return [];
    }

    /**
     * Logins refresh the HR employee link on every sign-in; that is not news.
     */
    protected function activityRecordsUpdates(): bool
    {
        return false;
    }

    /**
     * Record a change of roles (role pivots fire no model events).
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public function recordRoleChange(array $before, array $after): void
    {
        sort($before);
        sort($after);

        if ($before === $after) {
            return;
        }

        $this->recordActivity('user.role_changed', ['roles' => [$before, $after]], ['role' => $after === [] ? '—' : implode(', ', $after)]);
    }

    /**
     * Record a change of direct permissions (permission pivots fire no model
     * events either).
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public function recordPermissionChange(array $before, array $after): void
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added === [] && $removed === []) {
            return;
        }

        sort($added);
        sort($removed);

        $this->recordActivity('user.permissions_changed', properties: [
            'added' => $added === [] ? '—' : implode(', ', $added),
            'removed' => $removed === [] ? '—' : implode(', ', $removed),
            'added_permissions' => $added,
            'removed_permissions' => $removed,
        ]);
    }

    /**
     * Record that this user was refused an action (a 403). Logged for the
     * audit trail only: it notifies nobody.
     *
     * @param  array{action: string, ip: string|null}  $attempt
     */
    public function recordAccessDenied(array $attempt): void
    {
        app(ActivityRecorder::class)->record($this, 'user.access_denied', properties: $attempt, notify: false);
    }
}
