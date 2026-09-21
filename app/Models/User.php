<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Mirror the columns' DB defaults so a user that has not been round-tripped
     * through the database still reports them under strict mode: no language
     * chosen and no linked HR employee.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'employee_id' => null,
        'locale' => null,
    ];

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
     * Every HR account may use tracking. A new user starts with no role,
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
     * Create the missing tracking users for every HR account.
     *
     * @return int The number of users created.
     */
    public static function syncFromHrAccounts(): int
    {
        $existingEmails = static::query()->pluck('email')->map(fn (string $email): string => mb_strtolower($email))->flip();
        $created = 0;

        HrUser::query()->with('employee')->orderBy('id')->each(function (HrUser $hrUser) use ($existingEmails, &$created): void {
            if ($existingEmails->has(mb_strtolower($hrUser->email))) {
                return;
            }

            static::forHrAccount($hrUser);
            $created++;
        });

        return $created;
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
        ];
    }
}
