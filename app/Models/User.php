<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\RecordsActivity;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * A person who can use hc-tracking.
 *
 * They log in with their HR account (see AuthenticateWithHrAccount), matched by
 * email; this row holds only two-factor settings, passkeys, and (via
 * Spatie\Permission\Traits\HasRoles) role/permission assignments. `name` is
 * copied from the HR employee at each login, and `password` is a leftover
 * that is never checked.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property int|null $hr_user_id
 * @property int|null $employee_id
 * @property int|null $active_role_id
 * @property string|null $locale
 * @property bool $notification_popups
 * @property CarbonImmutable|null $email_verified_at
 * @property string|null $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, PasskeyUser, TracksActivity
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, RecordsActivity, TwoFactorAuthenticatable;

    /**
     * Mirror the columns' DB defaults so a user that has not been round-tripped
     * through the database still reports them under strict mode: no language
     * chosen, no linked HR employee and no desktop notification popups.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'employee_id' => null,
        'locale' => null,
        'notification_popups' => false,
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
     * The tracking user for an HR account, created on first use.
     *
     * Only active HR accounts (see HrUser::isActive()) may use tracking; the
     * caller checks that first. A new user starts with no role,
     * except an email listed in auth.hr_admin_emails, which starts as Super
     * Admin.
     */
    public static function forHrAccount(HrUser $hrUser): self
    {
        $user = static::query()->where('email', $hrUser->email)->first();

        if ($user !== null) {
            return $user;
        }

        $role = in_array(mb_strtolower($hrUser->email), config('auth.hr_admin_emails', []), true)
            ? 'Super Admin'
            : null;

        return static::createFromHrAccount($hrUser, $role);
    }

    /**
     * Create the missing tracking users for every active HR account.
     *
     * The HR mirror is not somebody's action, so it records no activity.
     *
     * @return int The number of users created.
     */
    public static function syncFromHrAccounts(): int
    {
        return Activity::withoutRecording(function (): int {
            $existingEmails = static::query()->pluck('email')->map(fn (string $email): string => mb_strtolower($email))->flip();
            $created = 0;

            HrUser::query()->active()->with('employee')->orderBy('id')->each(function (HrUser $hrUser) use ($existingEmails, &$created): void {
                if ($existingEmails->has(mb_strtolower($hrUser->email))) {
                    return;
                }

                static::forHrAccount($hrUser);
                $created++;
            });

            return $created;
        });
    }

    /**
     * Delete the tracking users whose HR account is gone or no longer active.
     *
     * A user still referenced by records that restrict deletion (reports,
     * indicator readings, uploads, created tasks) is kept. Part of the HR
     * mirror, so it records no activity.
     *
     * @return array{deleted: int, kept: list<string>}
     */
    public static function pruneInactiveHrAccounts(): array
    {
        return Activity::withoutRecording(function (): array {
            $activeEmails = HrUser::query()->active()->pluck('email')->map(fn (string $email): string => mb_strtolower($email))->flip();
            $deleted = 0;
            $kept = [];

            static::query()->orderBy('id')->each(function (User $user) use ($activeEmails, &$deleted, &$kept): void {
                if ($activeEmails->has(mb_strtolower($user->email))) {
                    return;
                }

                try {
                    $user->delete();
                    $deleted++;
                } catch (QueryException) {
                    $kept[] = $user->email;
                }
            });

            return ['deleted' => $deleted, 'kept' => $kept];
        });
    }

    /**
     * Give an HR account access to hc-tracking, optionally with a role.
     *
     * Name and email are taken from HR. No local password is stored: people
     * always log in with their HR password.
     */
    public static function createFromHrAccount(HrUser $hrUser, ?string $roleName, bool $verified = true): self
    {
        $user = new self;

        $user->forceFill([
            'name' => $hrUser->employee?->name_en ?: $hrUser->email,
            'email' => $hrUser->email,
            'hr_user_id' => $hrUser->id,
            'employee_id' => $hrUser->employee_id,
            'password' => null,
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
     * Record the HR account this user logged in with and refresh their name.
     *
     * Only tracking columns are written; the HR account is read, never changed.
     */
    public function linkToHrAccount(HrUser $hrUser): void
    {
        $this->forceFill([
            'hr_user_id' => $hrUser->id,
            'employee_id' => $hrUser->employee_id,
            'name' => $hrUser->employee?->name_en ?: $this->name,
        ])->save();
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
            'password' => 'hashed',
            'notification_popups' => 'boolean',
        ];
    }

    public function activityModule(): string
    {
        return 'access-management';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
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
     * Logins refresh the name and HR ids on every sign-in; that is not news.
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
}
