<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProposalPlanShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One approval rebase of a proposal's plan (contracts.md §12.2): the unique
 * approval_note_id makes the shift happen once per approval. Written only by
 * App\Actions\ProposalPlan\RebaseProposalPlan; the movement itself is logged
 * on the proposal (`proposal.plan_rebased`), so this bookkeeping row records
 * no activity of its own.
 *
 * @property int $id
 * @property int $proposal_id
 * @property int $approval_note_id
 * @property int $delta_days
 * @property int $shifted
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['proposal_id', 'approval_note_id', 'delta_days', 'shifted'])]
class ProposalPlanShift extends Model
{
    /** @use HasFactory<ProposalPlanShiftFactory> */
    use HasFactory;

    /**
     * The proposal whose plan was shifted.
     *
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * The approval status note this shift answers.
     *
     * @return BelongsTo<ProposalStatusNote, $this>
     */
    public function approvalNote(): BelongsTo
    {
        return $this->belongsTo(ProposalStatusNote::class, 'approval_note_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proposal_id' => 'integer',
            'approval_note_id' => 'integer',
            'delta_days' => 'integer',
            'shifted' => 'integer',
        ];
    }
}
