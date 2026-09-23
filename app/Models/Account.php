<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsActivity;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\AccountFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organisational bank account that donor funds pass through.
 *
 * `is_active` is the single source of truth for whether the account can still
 * receive a transfer; `closed_at` only records when it was closed. Call sites
 * must go through isUsable(), the active scope or close() rather than reading
 * either column directly, so the two can never be interpreted apart.
 *
 * @property int $id
 * @property string $name
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'opened_at', 'closed_at', 'is_active'])]
class Account extends Model implements TracksActivity
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory, RecordsActivity;

    /**
     * Mirror the column default so a model that has not been round-tripped
     * through the database still reports itself as usable.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * The transfers that have landed in this account.
     *
     * @return HasMany<TransfersTracking, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(TransfersTracking::class);
    }

    /**
     * Whether this account can still receive a transfer.
     */
    public function isUsable(): bool
    {
        return $this->is_active;
    }

    /**
     * Close this account so it can no longer receive transfers.
     *
     * Both columns are written together, so a closed account is never left
     * looking active. An account money has passed through can never be deleted,
     * because transfers_tracking.account_id restricts on delete.
     */
    public function close(?DateTimeInterface $on = null): bool
    {
        return $this->forceFill([
            'closed_at' => $on ?? now(),
            'is_active' => false,
        ])->save();
    }

    /**
     * Limit the query to accounts that can still receive transfers.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'date',
            'closed_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function activityModule(): string
    {
        return 'accounts';
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
        return ['is_active' => 'closed', 'name' => 'updated'];
    }

    /**
     * Only deactivating is "closed"; reopening is a plain update.
     */
    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        if ($field === 'is_active') {
            return (bool) $new ? 'updated' : 'closed';
        }

        return $this->activityEvents()[$field] ?? null;
    }
}
