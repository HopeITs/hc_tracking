<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasSlug;
use App\Concerns\RecordsActivity;
use App\Enums\Currency;
use App\Notifications\TracksActivity;
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
 * budget, a currency and the external project number the donor files it under.
 *
 * @property int $id
 * @property string $slug
 * @property int $donor_id
 * @property string $budget
 * @property Currency $currency
 * @property string|null $external_project_number
 * @property int $proposal_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['donor_id', 'budget', 'currency', 'external_project_number', 'proposal_id'])]
class DonorProposal extends Model implements TracksActivity
{
    /** @use HasFactory<DonorProposalFactory> */
    use HasFactory, HasSlug, RecordsActivity;

    /**
     * Eloquent would otherwise pluralize this to donor_proposals.
     *
     * @var string
     */
    protected $table = 'donor_proposal';

    /**
     * "donor-slug-proposal-slug" — there is no title field of its own, so the
     * slug is built from the two records this funding agreement links.
     */
    protected function slugSource(): string
    {
        return $this->donor?->slug.'-'.$this->proposal?->slug;
    }

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
     * A whole percentage of $of, floored so nothing is claimed early, but
     * never shown as 0 while something has landed: under 1% reads "<1".
     *
     * Every funding page prints its landed and committed shares through this,
     * so the same money never reads 0% on one page and <1% on the next.
     */
    public static function percentLabel(float $value, float $of): string
    {
        if ($of <= 0.0 || $value <= 0.0) {
            return '0';
        }

        $percent = (int) floor($value / $of * 100);

        return $percent === 0 ? '<1' : (string) $percent;
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

    public function activityModule(): string
    {
        return 'funding-agreements';
    }

    public function activityLabel(): string
    {
        return static::labelFor($this->getKey());
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
        return ['budget' => 'updated', 'currency' => 'updated', 'external_project_number' => 'updated'];
    }

    /**
     * "Donor · Proposal", read without loading the agreement.
     */
    public static function labelFor(mixed $id): string
    {
        $row = static::query()
            ->join('donors', 'donors.id', '=', 'donor_proposal.donor_id')
            ->join('proposals', 'proposals.id', '=', 'donor_proposal.proposal_id')
            ->where('donor_proposal.id', $id)
            ->toBase()
            ->first(['donors.name as donor', 'proposals.name as proposal']);

        return $row === null ? '' : $row->donor.' · '.$row->proposal;
    }
}
