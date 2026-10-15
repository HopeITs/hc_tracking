<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasSlug;
use App\Concerns\RecordsActivity;
use App\Enums\Currency;
use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Notifications\TracksActivity;
use App\Observers\MediaOwnerObserver;
use App\Observers\PlanIndicatorSourceObserver;
use App\Observers\ProposalObserver;
use App\Support\ProposalTransitions;
use Carbon\CarbonImmutable;
use Database\Factories\ProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $internal_project_number
 * @property int $donor_id
 * @property string|null $requested_budget
 * @property Currency|null $requested_currency
 * @property CarbonImmutable|null $status_remind_on
 * @property string|null $approval_note
 * @property CarbonImmutable|null $final_report_accepted_on
 * @property int|null $final_report_accepted_by
 * @property int|null $previous_proposal_id
 * @property CarbonImmutable $planned_date_begin
 * @property CarbonImmutable $planned_date_end
 * @property CarbonImmutable|null $actual_date_begin
 * @property CarbonImmutable|null $actual_date_end
 * @property ProposalStatus $status
 * @property-read ProposalPhase $phase
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'name',
    'donor_id',
    'internal_project_number',
    'planned_date_begin',
    'planned_date_end',
    'actual_date_begin',
    'actual_date_end',
    'notes',
    'requested_budget',
    'requested_currency',
    'status_remind_on',
    'approval_note',
    'final_report_accepted_on',
    'final_report_accepted_by',
    'previous_proposal_id',
])]
#[ObservedBy([PlanIndicatorSourceObserver::class, MediaOwnerObserver::class, ProposalObserver::class])]
class Proposal extends Model implements TracksActivity
{
    /** @use HasFactory<ProposalFactory> */
    use HasFactory, HasSlug, RecordsActivity;

    /**
     * Marks the internal numbers the migration wrote for proposals that had
     * none. Reserved: nobody may type a new number starting with it.
     */
    public const PLACEHOLDER_NUMBER_PREFIX = 'TEMP-';

    protected function slugSource(): string
    {
        return $this->name;
    }

    /**
     * Whether an internal project number is a migration placeholder still
     * waiting for the real number, compared case-insensitively.
     */
    public static function isPlaceholderNumber(?string $number): bool
    {
        return $number !== null && str_starts_with(mb_strtoupper($number), self::PLACEHOLDER_NUMBER_PREFIX);
    }

    /**
     * Mirror the column default so an unrefreshed model can still report a
     * phase.
     *
     * The factory omits `status` on purpose, so the attribute is unhydrated
     * until the model is refreshed, and `phase` would fail on a model straight
     * out of create(). `Account::$is_active` carries the same mirror for the
     * same reason. The column default is still proven independently by a raw
     * insert in tests/Feature/ProposalTest.php.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'concept note',
    ];

    /**
     * `phase` has no column, so it is appended explicitly.
     *
     * Without this it would be absent from toArray() and from any JSON an API
     * resource or Livewire payload builds, even though $proposal->phase reads
     * fine in PHP.
     *
     * @var list<string>
     */
    protected $appends = ['phase'];

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
     * The proposal this one renews, or null when it starts a funding line or
     * its predecessor was deleted.
     *
     * @return BelongsTo<Proposal, $this>
     */
    public function previousProposal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_proposal_id');
    }

    /**
     * The proposals that renew this one.
     *
     * @return HasMany<Proposal, $this>
     */
    public function renewals(): HasMany
    {
        return $this->hasMany(self::class, 'previous_proposal_id');
    }

    /**
     * The projects this proposal funds.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withPivot('target_beneficiaries')->withTimestamps();
    }

    /**
     * The one donor that funds this proposal.
     *
     * A co-funder is a separate proposal. The funding agreement, if any, must
     * name this same donor: the database enforces it.
     *
     * @return BelongsTo<Donor, $this>
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    /**
     * The user who recorded the donor's acceptance of the final report.
     *
     * @return BelongsTo<User, $this>
     */
    public function finalReportAcceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'final_report_accepted_by');
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
            ->withPivot(['budget', 'currency', 'external_project_number'])
            ->withTimestamps();
    }

    /**
     * The donors funding this proposal, by name only.
     *
     * Unlike donors() it carries no pivot, so a page that shows who funds a
     * proposal to someone who may not see the money never loads the budget.
     *
     * @return BelongsToMany<Donor, $this>
     */
    public function donorNames(): BelongsToMany
    {
        return $this->belongsToMany(Donor::class, 'donor_proposal');
    }

    /**
     * The tasks sourced directly from this proposal.
     *
     * Does not include tasks sourced from one of this proposal's funded
     * projects' milestones — see the Tasks tab query in
     * proposals/⚡show.blade.php for that broader listing.
     *
     * @return MorphMany<Task, $this>
     */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'source');
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
     * The people asked to review its concept-note and proposal stages.
     * Narrow to one stage with ->forStage().
     *
     * @return HasMany<ProposalReviewer, $this>
     */
    public function reviewers(): HasMany
    {
        return $this->hasMany(ProposalReviewer::class);
    }

    /**
     * The proposal's own logical framework (contracts §11.2): every result
     * whose `proposal_id` is this proposal, roots and children alike.
     *
     * @return HasMany<Result, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
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
     * The documents filed on this proposal itself: concept note, proposal,
     * signed agreement, MOU.
     *
     * @return MorphMany<Media, $this>
     */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    /**
     * The phase of work this proposal is in.
     *
     * Derived from `status`, never stored. There is no `phase` column: it held
     * a value that could contradict the status sitting next to it.
     *
     * Requires `status` to be loaded. A partial select that omits it (e.g.
     * `Proposal::query()->select('id', 'name')->first()->phase`) throws
     * "Call to a member function phase() on null" — the `$attributes` mirror
     * does not help here, because `newFromBuilder` replaces attributes
     * wholesale rather than merging into it.
     *
     * @return Attribute<ProposalPhase, never>
     */
    protected function phase(): Attribute
    {
        return Attribute::get(fn (): ProposalPhase => $this->status->phase());
    }

    /**
     * The status the proposal held before it last entered $status: the
     * pre-hold status for on hold, the pre-rejection one for rejected.
     *
     * Read from the audit trail in insertion order, so a backdated note does
     * not reorder it. Null when the trail does not show it.
     */
    public function statusBefore(ProposalStatus $status): ?ProposalStatus
    {
        $enteredAt = $this->statusNotes()->where('status', $status)->max('id');

        if ($enteredAt === null) {
            return null;
        }

        $before = $this->statusNotes()
            ->where('id', '<', $enteredAt)
            ->where('status', '!=', $status)
            ->latest('id')
            ->value('status');

        return $before instanceof ProposalStatus ? $before : ProposalStatus::tryFrom((string) $before);
    }

    /**
     * Whether ApproveProposal accepts the proposal now: submitted, or on hold
     * entered from submitted (contracts.md §2).
     */
    public function canBeApproved(): bool
    {
        return $this->status === ProposalStatus::Submitted
            || ($this->status === ProposalStatus::OnHold && $this->statusBefore(ProposalStatus::OnHold) === ProposalStatus::Submitted);
    }

    /**
     * The stop of the main lifecycle line the proposal sits at.
     *
     * On hold and rejected sit at the stop they left (a rejection during a
     * hold looks past the hold); the retired final reports status sits at
     * active. Reads the loaded status notes (all of them) so a register page
     * costs no extra query per row; falls back to submitted when the trail
     * does not show where it came from.
     */
    public function lifecycleStop(): ProposalStatus
    {
        $notes = $this->statusNotes->sortByDesc('id')->values();

        $before = function (ProposalStatus $left) use ($notes): ?ProposalStatus {
            $position = $notes->search(fn (ProposalStatusNote $note): bool => $note->status === $left);

            return $position === false
                ? null
                : $notes->slice($position + 1)->first(fn (ProposalStatusNote $note): bool => $note->status !== $left)?->status;
        };

        $stop = match ($this->status) {
            ProposalStatus::OnHold => $before(ProposalStatus::OnHold),
            ProposalStatus::Rejected => $before(ProposalStatus::Rejected) === ProposalStatus::OnHold
                ? $before(ProposalStatus::OnHold)
                : $before(ProposalStatus::Rejected),
            ProposalStatus::FinalReports => ProposalStatus::Active,
            default => $this->status,
        };

        return $stop !== null && in_array($stop, ProposalStatus::lifecycle(), true) ? $stop : ProposalStatus::Submitted;
    }

    /**
     * The statuses TransitionProposal may move this proposal to.
     *
     * For a proposal on hold the pre-hold status comes from the loaded status
     * notes when they are loaded (register rows), else from the audit trail.
     *
     * @return list<ProposalStatus>
     */
    public function transitionTargets(): array
    {
        $preHold = match (true) {
            $this->status !== ProposalStatus::OnHold => null,
            $this->relationLoaded('statusNotes') => $this->lifecycleStop(),
            default => $this->statusBefore(ProposalStatus::OnHold),
        };

        return ProposalTransitions::targets($this->status, $preHold);
    }

    /**
     * The paperwork stage this proposal is at: concept_note, proposal or grant.
     *
     * Derived, never stored. On hold and rejected keep the stage of the
     * status they left; a rejection during a hold looks past the hold to the
     * status before it.
     *
     * @return 'concept_note'|'proposal'|'grant'
     */
    public function stage(): string
    {
        $before = in_array($this->status, [ProposalStatus::OnHold, ProposalStatus::Rejected], true)
            ? $this->statusBefore($this->status)
            : null;

        if ($this->status === ProposalStatus::Rejected && $before === ProposalStatus::OnHold) {
            $before = $this->statusBefore(ProposalStatus::OnHold);
        }

        return $this->status->stage($before);
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
            'planned_date_begin' => 'date',
            'planned_date_end' => 'date',
            'actual_date_begin' => 'date',
            'actual_date_end' => 'date',
            'requested_budget' => 'decimal:2',
            'requested_currency' => Currency::class,
            'status_remind_on' => 'date',
            'final_report_accepted_on' => 'date',
        ];
    }

    /**
     * The requested budget is money: it never reaches the activity log.
     *
     * @return list<string>
     */
    protected function activityExcludedFields(): array
    {
        return ['requested_budget'];
    }

    /**
     * A renewal's creation names the proposal it renews.
     *
     * @return array<string, mixed>
     */
    protected function activityProperties(string $event): array
    {
        $previousId = $this->activityAttribute('previous_proposal_id');

        return $event === $this->getMorphClass().'.created' && $previousId !== null
            ? ['renewed_from' => (int) $previousId]
            : [];
    }

    public function activityModule(): string
    {
        return 'proposals';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
    }

    public function activityProgramId(): ?int
    {
        return null;
    }

    /**
     * Donor approval is its own event (contracts.md §15); the other status
     * moves stay proposal.status_changed.
     */
    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        if ($field === 'status' && $new === ProposalStatus::Approved->value) {
            return 'approved';
        }

        return $this->activityEvents()[$field] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return ['status' => 'status_changed', 'name' => 'updated', 'internal_project_number' => 'updated', 'planned_date_begin' => 'updated', 'planned_date_end' => 'updated'];
    }
}
