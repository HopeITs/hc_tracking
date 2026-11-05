<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsParentActivity;
use App\Enums\ProposalStatus;
use App\Enums\StatusNoteKind;
use Carbon\CarbonImmutable;
use Database\Factories\ProposalStatusNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * One entry in the audit trail of a proposal status change.
 *
 * @property int $id
 * @property int $proposal_id
 * @property ProposalStatus $status
 * @property string $context
 * @property StatusNoteKind $kind
 * @property CarbonImmutable $date
 * @property CarbonImmutable|null $meeting_at
 * @property bool $is_correction
 * @property int|null $changed_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['proposal_id', 'status', 'context', 'kind', 'date', 'meeting_at', 'is_correction', 'changed_by'])]
class ProposalStatusNote extends Model
{
    /** @use HasFactory<ProposalStatusNoteFactory> */
    use HasFactory, RecordsParentActivity;

    /**
     * Mirror the column defaults so an unrefreshed note still reports them.
     *
     * @var array<string, bool|string>
     */
    protected $attributes = [
        'is_correction' => false,
        'kind' => StatusNoteKind::Transition->value,
    ];

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
     * The user who made the move; null for notes older than T04 or when that
     * account was deleted.
     *
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * The files attached to a negotiation or extension note.
     *
     * @return MorphMany<Media, $this>
     */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
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
            'kind' => StatusNoteKind::class,
            'date' => 'date',
            'meeting_at' => 'immutable_datetime',
            'is_correction' => 'boolean',
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
        // Negotiation and extension notes log their own event (AddNegotiationNote, ExtendProposal) with names and dates only.
        return $hook === 'created' && ! in_array($this->kind, [StatusNoteKind::Negotiation, StatusNoteKind::Extension], true) ? 'proposal.status_note_added' : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parentActivityProperties(string $hook): array
    {
        return ['note' => Str::limit((string) $this->context, 120)];
    }
}
