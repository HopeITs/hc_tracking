<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChangeReviewVerdict;
use Carbon\CarbonImmutable;
use Database\Factories\ProposalChangeRequestReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One slot's review of a change request (contracts.md §7, Y-09, D-89a):
 * `finance_manager` or `meal_manager`, waiting while `verdict` is null.
 * Advice only: it never approves, rejects or applies anything.
 *
 * @property int $id
 * @property int $proposal_change_request_id
 * @property string $slot
 * @property ChangeReviewVerdict|null $verdict
 * @property string|null $note
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['proposal_change_request_id', 'slot', 'verdict', 'note', 'reviewed_by', 'reviewed_at'])]
class ProposalChangeRequestReview extends Model
{
    /** @use HasFactory<ProposalChangeRequestReviewFactory> */
    use HasFactory;

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'verdict' => ChangeReviewVerdict::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ProposalChangeRequest, $this>
     */
    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ProposalChangeRequest::class, 'proposal_change_request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isWaiting(): bool
    {
        return $this->verdict === null;
    }
}
