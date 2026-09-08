<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\PlanIndicatorSourceObserver;
use Carbon\CarbonImmutable;
use Database\Factories\ProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $name
 * @property CarbonImmutable $planned_date_begin
 * @property CarbonImmutable $planned_date_end
 * @property CarbonImmutable|null $actual_date_begin
 * @property CarbonImmutable|null $actual_date_end
 * @property string $status
 * @property string $phase
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'name',
    'planned_date_begin',
    'planned_date_end',
    'actual_date_begin',
    'actual_date_end',
    'status',
    'phase',
    'notes',
])]
#[ObservedBy(PlanIndicatorSourceObserver::class)]
class Proposal extends Model
{
    /** @use HasFactory<ProposalFactory> */
    use HasFactory;

    /**
     * Delete the model inside a transaction.
     *
     * Its observer deletes the targets committed in this proposal, which no
     * foreign key can reach. Those deletions bracket the delete of
     * this row, so a failure partway through must not leave them committed.
     */
    public function delete(): bool
    {
        return (bool) DB::transaction(fn () => parent::delete());
    }

    /**
     * The projects this proposal funds.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withTimestamps();
    }

    /**
     * The funding agreements signed against this proposal.
     *
     * @return HasMany<DonorProposal, $this>
     */
    public function donorProposals(): HasMany
    {
        return $this->hasMany(DonorProposal::class);
    }

    /**
     * The donors funding this proposal.
     *
     * The donor_proposal table carries budget and currency, so prefer
     * donorProposals() when those columns matter.
     *
     * @return BelongsToMany<Donor, $this>
     */
    public function donors(): BelongsToMany
    {
        return $this->belongsToMany(Donor::class, 'donor_proposal')
            ->withPivot(['budget', 'currency', 'project_num'])
            ->withTimestamps();
    }

    /**
     * The tasks carried out under this proposal.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * The audit trail of status changes on this proposal.
     *
     * @return HasMany<ProposalStatusNote, $this>
     */
    public function statusNotes(): HasMany
    {
        return $this->hasMany(ProposalStatusNote::class);
    }

    /**
     * The indicator targets committed in this proposal.
     *
     * @return MorphMany<PlanIndicator, $this>
     */
    public function planIndicators(): MorphMany
    {
        return $this->morphMany(PlanIndicator::class, 'source');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'planned_date_begin' => 'date',
            'planned_date_end' => 'date',
            'actual_date_begin' => 'date',
            'actual_date_end' => 'date',
        ];
    }
}
