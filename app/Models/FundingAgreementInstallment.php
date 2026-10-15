<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use Carbon\CarbonImmutable;
use Database\Factories\FundingAgreementInstallmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One planned payment of a funding agreement's schedule (contracts.md §7).
 *
 * The schedule is the promise; the expected `transfers_tracking` row generated
 * from it is what Finance then fills in. Changes are recorded as one activity
 * on the agreement by SaveAgreementInstallments, never per row.
 *
 * @property int $id
 * @property int $funding_agreement_id
 * @property int $sequence
 * @property string $amount
 * @property Currency $currency
 * @property CarbonImmutable $scheduled_date
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['funding_agreement_id', 'sequence', 'amount', 'currency', 'scheduled_date'])]
class FundingAgreementInstallment extends Model
{
    /** @use HasFactory<FundingAgreementInstallmentFactory> */
    use HasFactory;

    /**
     * The agreement this installment belongs to.
     *
     * @return BelongsTo<DonorProposal, $this>
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(DonorProposal::class, 'funding_agreement_id');
    }

    /**
     * The expected transfer generated from this installment, once there is one.
     *
     * @return HasOne<TransfersTracking, $this>
     */
    public function transfer(): HasOne
    {
        return $this->hasOne(TransfersTracking::class, 'installment_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'amount' => 'decimal:2',
            'currency' => Currency::class,
            'scheduled_date' => 'date',
        ];
    }
}
