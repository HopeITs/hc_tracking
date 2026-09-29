<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsActivity;
use App\Enums\Currency;
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
use Illuminate\Support\Str;

/**
 * An organisational bank account that donor funds pass through.
 *
 * `is_active` is the single source of truth for whether the account can still
 * receive a transfer; `closed_at` only records when it was closed. Call sites
 * must go through isUsable(), the active scope or close() rather than reading
 * either column directly, so the two can never be interpreted apart.
 *
 * `po_box_street` is the bank's own branch address (the same value repeats
 * across every account held at that branch); `company_address` is the
 * account holder company's own registered address, when it differs from the
 * organisation's own — kept separate rather than folded together.
 *
 * `account_number` and `iban` are encrypted at rest with the APP_KEY and never
 * written to the activity log. The database cannot search them; match them in
 * PHP (see idsMatchingAccountNumber()).
 *
 * @property int $id
 * @property string $account_name
 * @property string $bank_name
 * @property Currency $currency
 * @property string|null $authorized_person
 * @property string $account_number
 * @property string|null $iban
 * @property string|null $swift_code
 * @property string $country
 * @property string|null $po_box_street
 * @property string|null $company_address
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'account_name',
    'bank_name',
    'currency',
    'authorized_person',
    'account_number',
    'iban',
    'swift_code',
    'country',
    'po_box_street',
    'company_address',
    'opened_at',
    'closed_at',
    'is_active',
])]
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
     * Ids of the accounts whose account number contains this text, ignoring
     * case. The column is encrypted, so the database cannot match it: every
     * account number is decrypted and compared here instead (a small table).
     *
     * @return list<int>
     */
    public static function idsMatchingAccountNumber(string $search): array
    {
        $search = trim($search);

        if ($search === '') {
            return [];
        }

        return static::query()
            ->select(['id', 'account_number'])
            ->get()
            ->filter(fn (Account $account): bool => Str::contains($account->account_number, $search, ignoreCase: true))
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * The account number with everything but its last four characters hidden,
     * for people who may see the account but not change it. A number of four
     * characters or fewer is hidden completely.
     */
    public function maskedAccountNumber(): string
    {
        $number = (string) $this->account_number;

        return Str::length($number) > 4 ? '•••• '.Str::substr($number, -4) : '••••';
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
            'currency' => Currency::class,
            'account_number' => 'encrypted',
            'iban' => 'encrypted',
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
        return (string) $this->activityAttribute('account_name');
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
        return ['is_active' => 'closed', 'account_name' => 'updated'];
    }

    /**
     * Bank details stay out of the log: it is readable by more people than
     * the accounts page, and it is not encrypted.
     *
     * @return list<string>
     */
    protected function activityExcludedFields(): array
    {
        return ['account_number', 'iban'];
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
