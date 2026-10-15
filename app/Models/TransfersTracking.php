<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsActivity;
use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\TransfersTrackingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * One payment tranche scheduled against a funding agreement.
 *
 * @property int $id
 * @property int $donor_proposal_id
 * @property int|null $account_id
 * @property string $payment_amount
 * @property Currency $amount_transfered_currency
 * @property CarbonImmutable|null $scheduled_date
 * @property CarbonImmutable|null $transfer_date
 * @property CarbonImmutable|null $arrival_date
 * @property string $fees
 * @property Currency $fees_currency
 * @property string|null $amount_received
 * @property string $exchange_rate
 * @property string|null $net_amount_received
 * @property Currency $amount_received_currency
 * @property CarbonImmutable|null $receive_date
 * @property ArStatus $ar_status
 * @property string $source 'manual' (typed by Finance) or 'auto' (generated from an installment)
 * @property int|null $installment_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'donor_proposal_id',
    'account_id',
    'payment_amount',
    'amount_transfered_currency',
    'scheduled_date',
    'transfer_date',
    'arrival_date',
    'fees',
    'fees_currency',
    'amount_received',
    'exchange_rate',
    'net_amount_received',
    'amount_received_currency',
    'receive_date',
    'ar_status',
    'source',
    'installment_id',
])]
class TransfersTracking extends Model implements TracksActivity
{
    /** @use HasFactory<TransfersTrackingFactory> */
    use HasFactory, RecordsActivity;

    /**
     * Eloquent would otherwise pluralize this to transfers_trackings.
     *
     * @var string
     */
    protected $table = 'transfers_tracking';

    /**
     * The states every funding page shares, in the vocabulary of state().
     *
     * @var list<string>
     */
    public const STATES = ['received', 'failed', 'transit', 'late', 'upcoming'];

    /**
     * The date column each date basis of a filter reads.
     *
     * @var array<string, string>
     */
    public const DATE_COLUMNS = ['scheduled' => 'scheduled_date', 'sent' => 'transfer_date', 'received' => 'receive_date'];

    /**
     * The funding agreement this tranche is drawn from.
     *
     * @return BelongsTo<DonorProposal, $this>
     */
    public function donorProposal(): BelongsTo
    {
        return $this->belongsTo(DonorProposal::class);
    }

    /**
     * The installment this row was generated from; null for a manual row.
     *
     * @return BelongsTo<FundingAgreementInstallment, $this>
     */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(FundingAgreementInstallment::class, 'installment_id');
    }

    /**
     * Whether Finance has recorded anything real on this row: a sent, arrival
     * or receive date, or an amount that landed. Such a row is no longer a
     * placeholder, so the schedule can neither delete nor replace it.
     */
    public function hasActualData(): bool
    {
        return $this->transfer_date !== null
            || $this->arrival_date !== null
            || $this->receive_date !== null
            || $this->amount_received !== null;
    }

    /**
     * The account the money landed in, once one is assigned.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Where this tranche stands, in the one vocabulary every funding page uses.
     *
     * Money the donor has sent is in transit until it lands, however far past
     * its scheduled date. A tranche is late only while it is still unsent, and
     * only from the day after its date, never on it.
     *
     * @return 'received'|'failed'|'transit'|'late'|'upcoming'
     */
    public function state(): string
    {
        return match (true) {
            $this->ar_status === ArStatus::Received => 'received',
            $this->ar_status === ArStatus::Failed => 'failed',
            $this->transfer_date !== null => 'transit',
            $this->scheduled_date !== null && $this->scheduled_date->lt(CarbonImmutable::today()) => 'late',
            default => 'upcoming',
        };
    }

    /**
     * Received, but nobody has recorded what actually landed. Such a tranche
     * counts as nothing landed until its net amount is filled in.
     */
    public function isMissingNetAmount(): bool
    {
        return $this->ar_status === ArStatus::Received && $this->net_amount_received === null;
    }

    /**
     * Received, with its net recorded, and landed in the currency it was sent
     * in: the only tranche whose two ends can honestly be compared.
     */
    public function isSettled(): bool
    {
        return $this->ar_status === ArStatus::Received
            && $this->net_amount_received !== null
            && $this->amount_received_currency === $this->amount_transfered_currency;
    }

    /**
     * Received in a currency other than the one it was sent in.
     */
    public function landedInAnotherCurrency(): bool
    {
        return $this->ar_status === ArStatus::Received && $this->amount_received_currency !== $this->amount_transfered_currency;
    }

    /**
     * What landed minus what was sent, for a settled tranche only: two
     * currencies are never subtracted.
     */
    public function difference(): ?float
    {
        return $this->isSettled() ? round((float) $this->net_amount_received - (float) $this->payment_amount, 2) : null;
    }

    /**
     * state() as an SQL condition, so a filter, a count and a sum read a
     * tranche exactly as its row does.
     */
    public static function stateSql(string $state): string
    {
        $table = 'transfers_tracking';
        $today = CarbonImmutable::today()->toDateString();
        $pending = "{$table}.ar_status = '".ArStatus::Pending->value."'";

        return match ($state) {
            'received' => "({$table}.ar_status = '".ArStatus::Received->value."')",
            'failed' => "({$table}.ar_status = '".ArStatus::Failed->value."')",
            'transit' => "({$pending} and {$table}.transfer_date is not null)",
            'late' => "({$pending} and {$table}.transfer_date is null and {$table}.scheduled_date is not null and {$table}.scheduled_date < '{$today}')",
            'upcoming' => "({$pending} and {$table}.transfer_date is null and ({$table}.scheduled_date is null or {$table}.scheduled_date >= '{$today}'))",
            default => throw new InvalidArgumentException("Unknown tranche state [{$state}]."),
        };
    }

    /**
     * isSettled() as an SQL condition.
     */
    public static function settledSql(): string
    {
        return '('.self::stateSql('received').' and transfers_tracking.net_amount_received is not null and transfers_tracking.amount_received_currency = transfers_tracking.amount_transfered_currency)';
    }

    /**
     * A settled tranche that landed with less than was sent.
     */
    public static function landedShortSql(): string
    {
        return '('.self::settledSql().' and transfers_tracking.net_amount_received < transfers_tracking.payment_amount)';
    }

    /**
     * isMissingNetAmount() as an SQL condition.
     */
    public static function missingNetSql(): string
    {
        return '('.self::stateSql('received').' and transfers_tracking.net_amount_received is null)';
    }

    /**
     * landedInAnotherCurrency() as an SQL condition.
     */
    public static function convertedSql(): string
    {
        return '('.self::stateSql('received').' and transfers_tracking.amount_received_currency <> transfers_tracking.amount_transfered_currency)';
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function inState(Builder $query, string $state): void
    {
        $query->whereRaw(self::stateSql($state));
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function settled(Builder $query): void
    {
        $query->whereRaw(self::settledSql());
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function missingNet(Builder $query): void
    {
        $query->whereRaw(self::missingNetSql());
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function converted(Builder $query): void
    {
        $query->whereRaw(self::convertedSql());
    }

    /**
     * Tranches whose chosen date falls inside the range. An unknown basis
     * reads the scheduled date; a tranche without that date is left out.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function datedWithin(Builder $query, string $basis, ?string $from, ?string $to): void
    {
        $column = 'transfers_tracking.'.(self::DATE_COLUMNS[$basis] ?? 'scheduled_date');

        $query->when($from !== null, fn (Builder $query) => $query->where($column, '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->where($column, '<=', $to))
            ->when($from === null && $to === null, fn (Builder $query) => $query->whereNotNull($column));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'payment_amount' => 'decimal:2',
            'scheduled_date' => 'date',
            'transfer_date' => 'date',
            'arrival_date' => 'date',
            'fees' => 'decimal:2',
            'amount_received' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'net_amount_received' => 'decimal:2',
            'receive_date' => 'date',
            'ar_status' => ArStatus::class,
            'amount_transfered_currency' => Currency::class,
            'fees_currency' => Currency::class,
            'amount_received_currency' => Currency::class,
        ];
    }

    public function activityModule(): string
    {
        return 'transfers';
    }

    /**
     * "Donor · Proposal · scheduled date". Never the amount: labels travel
     * into activities and notifications (contracts.md §15.1).
     */
    public function activityLabel(): string
    {
        $scheduled = $this->activityAttribute('scheduled_date');

        return DonorProposal::labelFor($this->activityAttribute('donor_proposal_id'))
            .($scheduled === null ? '' : ' · '.CarbonImmutable::parse($scheduled)->toDateString());
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
        return ['ar_status' => 'status_changed', 'scheduled_date' => 'updated', 'payment_amount' => 'updated'];
    }

    /**
     * A new tranche is a scheduled one.
     */
    protected function activityCreatedEvent(): string
    {
        return 'transfer.scheduled';
    }

    /**
     * Received and failed are their own events.
     */
    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        return match (true) {
            $field === 'ar_status' && $new === ArStatus::Received->value => 'received',
            $field === 'ar_status' && $new === ArStatus::Failed->value => 'failed',
            default => $this->activityEvents()[$field] ?? null,
        };
    }
}
