<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DonorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name'])]
class Donor extends Model
{
    /** @use HasFactory<DonorFactory> */
    use HasFactory;

    /**
     * The funding agreements this donor has signed.
     *
     * @return HasMany<DonorProposal, $this>
     */
    public function donorProposals(): HasMany
    {
        return $this->hasMany(DonorProposal::class);
    }

    /**
     * The proposals this donor funds.
     *
     * `donor_proposal` carries budget and currency, so prefer donorProposals()
     * when those columns matter.
     *
     * @return BelongsToMany<Proposal, $this>
     */
    public function proposals(): BelongsToMany
    {
        return $this->belongsToMany(Proposal::class, 'donor_proposal')
            ->withPivot(['budget', 'currency', 'project_num'])
            ->withTimestamps();
    }
}
