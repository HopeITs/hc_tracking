<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsActivity;
use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Notifications\TracksActivity;
use App\Observers\MediaOwnerObserver;
use App\Observers\PlanIndicatorSourceObserver;
use Carbon\CarbonImmutable;
use Database\Factories\ProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property ProposalStatus $status
 * @property-read ProposalPhase $phase
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
    'notes',
])]
#[ObservedBy([PlanIndicatorSourceObserver::class, MediaOwnerObserver::class])]
class Proposal extends Model implements TracksActivity
{
    /** @use HasFactory<ProposalFactory> */
    use HasFactory, RecordsActivity;

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
        ];
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
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return ['status' => 'status_changed', 'name' => 'updated', 'planned_date_begin' => 'updated', 'planned_date_end' => 'updated'];
    }
}
