<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsParentActivity;
use App\Enums\ProposalStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ProposalStatusNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One entry in the audit trail of a proposal status change.
 *
 * @property int $id
 * @property int $proposal_id
 * @property ProposalStatus $status
 * @property string $context
 * @property CarbonImmutable $date
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['proposal_id', 'status', 'context', 'date'])]
class ProposalStatusNote extends Model
{
    /** @use HasFactory<ProposalStatusNoteFactory> */
    use HasFactory, RecordsParentActivity;

    /**
     * The proposal whose status changed.
     *
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'date' => 'date',
        ];
    }

    /**
     * Status notes are logged on their proposal.
     */
    public function activityParent(): ?Proposal
    {
        return $this->proposal()->first();
    }

    protected function parentActivityEvent(string $hook): ?string
    {
        return $hook === 'created' ? 'proposal.status_note_added' : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parentActivityProperties(string $hook): array
    {
        return ['note' => Str::limit((string) $this->context, 120)];
    }
}
