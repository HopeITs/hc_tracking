<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A login account from the HR database (hchr_users).
 *
 * @property int $id
 * @property int|null $employee_id
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Hidden(['password', 'remember_token'])]
class HrUser extends HrModel
{
    protected $table = 'users';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    /**
     * Whether this account may use hc-tracking: its employee is active in HR.
     *
     * A system account with no employee record (e.g. HR's super admin) counts
     * as active.
     */
    public function isActive(): bool
    {
        return $this->employee_id === null || $this->employee?->is_active === true;
    }

    /**
     * Accounts that may use hc-tracking, matching isActive().
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('employee_id')
            ->orWhereHas('employee', fn (Builder $query) => $query->where('is_active', true)));
    }

    /**
     * The employee this account belongs to.
     *
     * @return BelongsTo<HrEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
