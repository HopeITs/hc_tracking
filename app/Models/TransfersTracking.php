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
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property string $fees
 * @property Currency $fees_currency
 * @property string|null $amount_received
 * @property string $exchange_rate
 * @property string|null $net_amount_received
 * @property Currency $amount_received_currency
 * @property CarbonImmutable|null $receive_date
 * @property ArStatus $ar_status
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
    'fees',
    'fees_currency',
    'amount_received',
    'exchange_rate',
    'net_amount_received',
    'amount_received_currency',
    'receive_date',
    'ar_status',
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
     * The funding agreement this tranche is drawn from.
     *
     * @return BelongsTo<DonorProposal, $this>
     */
    public function donorProposal(): BelongsTo
    {
        return $this->belongsTo(DonorProposal::class);
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
     * "Donor · Proposal · amount currency".
     */
    public function activityLabel(): string
    {
        return DonorProposal::labelFor($this->activityAttribute('donor_proposal_id'))
            .' · '.number_format((float) $this->activityAttribute('payment_amount'), 2).' '.$this->getRawOriginal('amount_transfered_currency', $this->getAttributes()['amount_transfered_currency'] ?? '');
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
