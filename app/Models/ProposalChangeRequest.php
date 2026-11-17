<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChangeReviewVerdict;
use App\Enums\ProposalChangeRequestStatus;
use App\Support\Slots;
use Carbon\CarbonImmutable;
use Database\Factories\ProposalChangeRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A change asked for on an approved proposal (contracts.md §7, Y-09, D-89,
 * D-89a). It changes nothing until the PR Manager approves it; a decided row
 * is never edited or deleted. `changes` and `payload` hold ids and plain
 * values only, never HR names: names are resolved when read.
 *
 * @property int $id
 * @property int $proposal_id
 * @property ProposalChangeRequestStatus $status
 * @property int $requested_by
 * @property string $reason
 * @property list<array{field: string, from: mixed, to: mixed, row?: array{city_id: int, program_id: int|null}, project_id?: int}> $changes
 * @property array<string, mixed> $payload
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['proposal_id', 'status', 'requested_by', 'reason', 'changes', 'payload', 'decided_by', 'decided_at', 'decision_note'])]
class ProposalChangeRequest extends Model
{
    /** @use HasFactory<ProposalChangeRequestFactory> */
    use HasFactory;

    /** The two review slots, in the order they are shown (D-89a). */
    public const ReviewSlots = ['finance_manager', 'meal_manager'];

    /**
     * Mirror the column default so an unrefreshed model reports its status.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProposalChangeRequestStatus::class,
            'changes' => 'array',
            'payload' => 'array',
            'decided_at' => 'immutable_datetime',
        ];
    }

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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return HasMany<ProposalChangeRequestReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ProposalChangeRequestReview::class);
    }

    public function isPending(): bool
    {
        return $this->status === ProposalChangeRequestStatus::Pending;
    }

    /**
     * The fields the request changes, for activities: field names only,
     * never values or amounts. Reads the column through getAttribute():
     * inside the model `$this->changes` is Eloquent's own dirty-tracking
     * property, not the column.
     *
     * @return list<string>
     */
    public function changedFields(): array
    {
        /** @var list<array{field: string}> $changes */
        $changes = $this->getAttribute('changes') ?? [];

        return array_values(array_unique(array_map(fn (array $change): string => (string) $change['field'], $changes)));
    }

    /**
     * Mark the waiting reviews whose slot has no active holder as `skipped`
     * (D-89a: never a request nobody can move). Returns how many were
     * skipped. The caller holds the request's lock.
     */
    public function skipVacantReviews(): int
    {
        $skipped = 0;

        foreach ($this->reviews()->whereNull('verdict')->get() as $review) {
            if (Slots::holderOf($review->slot) === null) {
                $review->forceFill(['verdict' => ChangeReviewVerdict::Skipped, 'reviewed_at' => now()])->save();
                $skipped++;
            }
        }

        return $skipped;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), ProposalChangeRequestStatus::Pending->value);
    }
}
