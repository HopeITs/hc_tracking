<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewerSlot;
use App\Enums\ReviewStage;
use Carbon\CarbonImmutable;
use Database\Factories\ProposalReviewerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person asked to review one stage of a proposal (contracts.md §7).
 *
 * The person is an HR employee id, never a copied name. Changes are recorded
 * as activities on the proposal by the review actions, not by this model.
 *
 * @property int $id
 * @property int $proposal_id
 * @property ReviewStage $stage
 * @property int $employee_id
 * @property ReviewerSlot $slot
 * @property CarbonImmutable|null $seen_at
 * @property string|null $note
 * @property int|null $added_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['proposal_id', 'stage', 'employee_id', 'slot', 'seen_at', 'note', 'added_by'])]
class ProposalReviewer extends Model
{
    /** @use HasFactory<ProposalReviewerFactory> */
    use HasFactory;

    /**
     * The name of the unique index that keeps one row per person and stage.
     */
    public const UNIQUE_INDEX = 'proposal_reviewers_proposal_stage_employee_unique';

    /**
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forStage(Builder $query, ReviewStage $stage): void
    {
        $query->where('stage', $stage->value);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unseen(Builder $query): void
    {
        $query->whereNull('seen_at');
    }

    /**
     * Only an extra reviewer who has neither seen the proposal nor written a
     * note may be taken off; anything else is part of the review record.
     */
    public function isRemovable(): bool
    {
        return $this->slot === ReviewerSlot::Additional && $this->seen_at === null && $this->note === null;
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'stage' => ReviewStage::class,
            'slot' => ReviewerSlot::class,
            'employee_id' => 'integer',
            'seen_at' => 'immutable_datetime',
        ];
    }
}
