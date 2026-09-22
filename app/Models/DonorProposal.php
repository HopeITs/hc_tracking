<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use Carbon\CarbonImmutable;
use Database\Factories\DonorProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A funding agreement between one donor and one proposal.
 *
 * Despite the singular, pivot-style table name this is an entity: it carries a
 * budget, a currency and the project number the donor files it under.
 *
 * @property int $id
 * @property int $donor_id
 * @property string $budget
 * @property Currency $currency
 * @property string|null $project_num
 * @property int $proposal_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['donor_id', 'budget', 'currency', 'project_num', 'proposal_id'])]
class DonorProposal extends Model
{
    /** @use HasFactory<DonorProposalFactory> */
    use HasFactory;

    /**
     * Eloquent would otherwise pluralize this to donor_proposals.
     *
     * @var string
     */
    protected $table = 'donor_proposal';

    /**
     * The donor funding this agreement.
     *
     * @return BelongsTo<Donor, $this>
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    /**
     * The proposal being funded.
     *
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * The payment tranches scheduled against this agreement.
     *
     * @return HasMany<TransfersTracking, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(TransfersTracking::class);
    }

    /**
     * The reports owed to the donor under this agreement.
     *
     * @return HasMany<DonorReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(DonorReport::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'budget' => 'decimal:2',
            'currency' => Currency::class,
        ];
    }
}
