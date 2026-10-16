<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Projects\ApproveProjectFromProposal;
use App\Concerns\HasAssignments;
use App\Concerns\HasSlug;
use App\Concerns\RecordsActivity;
use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Notifications\TracksActivity;
use App\Observers\MediaOwnerObserver;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ProjectStatus $status
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $end_date
 * @property int|null $target_beneficiaries
 * @property int|null $target_men
 * @property int|null $target_women
 * @property int|null $target_boys
 * @property int|null $target_girls
 * @property int $city_id
 * @property int|null $center_id
 * @property int $program_id
 * @property int $user_id
 * @property string $schedule_weekend
 * @property list<int>|null $working_days
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'name',
    'description',
    'status',
    'start_date',
    'end_date',
    'target_beneficiaries',
    'target_men',
    'target_women',
    'target_boys',
    'target_girls',
    'city_id',
    'center_id',
    'program_id',
    'user_id',
    'schedule_weekend',
])]
#[ObservedBy(MediaOwnerObserver::class)]
class Project extends Model implements TracksActivity
{
    /** @use HasFactory<ProjectFactory> */
    use HasAssignments, HasFactory, HasSlug, RecordsActivity;

    /**
     * How the pending save came about, read by activityProperties().
     *
     * @var array<string, mixed>
     */
    protected array $activityContext = [];

    protected function slugSource(): string
    {
        return $this->name;
    }

    /**
     * Mirror the column default so a project that has not been round-tripped
     * through the database still reports its status.
     *
     * Without this, `Project::factory()->create()->status` raises
     * MissingAttributeException under strict mode, because the factory omits
     * the defaulted column — the same trap Account::$is_active documents.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'schedule_weekend' => 'sat-sun',
    ];

    /**
     * The HR city this project is delivered in.
     *
     * @return BelongsTo<HrCity, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(HrCity::class, 'city_id');
    }

    /**
     * The HR center this project runs from (D-30), read from the in-memory
     * HR lookup rather than a relation, since centers live on another
     * connection. Null while the project is not mapped to a center.
     */
    public function center(): ?HrCenter
    {
        $centerId = $this->activityAttribute('center_id');

        return $centerId === null ? null : HrCenter::remembered((int) $centerId);
    }

    /**
     * The open (draft, approved, active) project already holding a center and
     * program, if any: there is at most one per pair (D-30a).
     *
     * Locks the program row first, so a caller inside a transaction holds off
     * every other create, restore or relink for the same program until it
     * commits. Call it inside the transaction that writes the project.
     */
    public static function openProjectFor(int $centerId, int $programId, ?int $exceptId = null): ?self
    {
        Program::query()->whereKey($programId)->lockForUpdate()->value('id');

        return static::query()
            ->where('center_id', $centerId)
            ->where('program_id', $programId)
            ->whereIn('status', self::openStatusValues())
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->orderBy('id')
            ->first(['id', 'slug', 'name', 'status', 'center_id', 'program_id']);
    }

    /**
     * The status values of a project still being delivered, taken from
     * ProjectStatus::isOpen() so there is one list.
     *
     * @return list<string>
     */
    public static function openStatusValues(): array
    {
        return array_values(array_map(
            fn (ProjectStatus $status): string => $status->value,
            array_filter(ProjectStatus::cases(), fn (ProjectStatus $status): bool => $status->isOpen()),
        ));
    }

    /**
     * Whether a linked proposal is still in play: not rejected or completed
     * (D-06: a draft whose only proposal was rejected shows "No active
     * proposal"). Reads the eager-loaded `proposals:id,status`.
     */
    public function hasActiveProposal(): bool
    {
        $this->loadMissing('proposals:proposals.id,proposals.status');

        return $this->proposals->contains(fn (Proposal $proposal): bool => ! $proposal->status->isTerminal());
    }

    /**
     * Where the project stands on its way to being funded, for the badge on
     * the list and the detail page; null when nothing is pending. Display
     * only, never stored. Reads the eager-loaded `proposals:id,status` and
     * `ownerAssignment`.
     *
     * - `awaiting_owner`: a draft with a donor-approved proposal whose owner
     *   has not acknowledged (and has not rejected).
     * - `awaiting_funding`: a draft whose proposals are submitted, not yet approved.
     * - `funded_awaiting_template`: approved, waiting for MEAL's report template.
     */
    public function fundingState(): ?string
    {
        $this->loadMissing(['proposals:proposals.id,proposals.status', 'ownerAssignment']);

        if ($this->status === ProjectStatus::Approved) {
            return 'funded_awaiting_template';
        }

        if ($this->status !== ProjectStatus::Draft) {
            return null;
        }

        if ($this->proposals->contains(fn (Proposal $proposal): bool => $proposal->status->isApprovedByDonor())) {
            return $this->isOwnerRejected() || $this->ownerAssignment?->status === AssignmentStatus::Acknowledged ? null : 'awaiting_owner';
        }

        return $this->proposals->contains(fn (Proposal $proposal): bool => $proposal->status === ProposalStatus::Submitted) ? 'awaiting_funding' : null;
    }

    /**
     * The phase to show for this project. Display only, never stored: a draft
     * takes the phase of its current (latest in-play) proposal, or Ideation
     * when it has none.
     */
    public function phase(): ProposalPhase
    {
        return match ($this->status) {
            ProjectStatus::Archived => ProposalPhase::Archived,
            ProjectStatus::Closed => ProposalPhase::Closure,
            ProjectStatus::Approved => ProposalPhase::Implementation,
            ProjectStatus::Active => ProposalPhase::Monitoring,
            ProjectStatus::Draft, ProjectStatus::Submitted => $this->draftPhase(),
        };
    }

    private function draftPhase(): ProposalPhase
    {
        $this->loadMissing('proposals:proposals.id,proposals.status');

        $current = $this->proposals
            ->filter(fn (Proposal $proposal): bool => ! $proposal->status->isTerminal())
            ->sortByDesc('id')
            ->first();

        return $current?->status->phase() ?? ProposalPhase::Ideation;
    }

    /**
     * The program this project belongs to.
     *
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * The HR employee responsible for this project.
     *
     * The column keeps its original name, `user_id`, but holds an HR employee id.
     *
     * @return BelongsTo<HrEmployee, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'user_id');
    }

    /**
     * The proposals this project is funded through.
     *
     * @return BelongsToMany<Proposal, $this>
     */
    public function proposals(): BelongsToMany
    {
        return $this->belongsToMany(Proposal::class)->withPivot('target_beneficiaries')->withTimestamps();
    }

    /**
     * The sectors this project covers (contracts.md §12.5). Written only
     * through App\Actions\Projects\SyncProjectSectors.
     *
     * @return BelongsToMany<Sector, $this>
     */
    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(Sector::class)->withTimestamps();
    }

    /**
     * The proposals the donor has approved for this project, each with its donor.
     *
     * Funding is official at donor approval, not at submission. The query
     * never selects an amount unless asked, so a caller without a money
     * permission keeps the figures out of memory, not just out of the view;
     * pass $withAmounts only after authorizing it.
     *
     * @return Collection<int, Proposal>
     */
    public function fundingSources(bool $withAmounts = false): Collection
    {
        $statuses = array_values(array_filter(ProposalStatus::cases(), fn (ProposalStatus $status): bool => $status->isApprovedByDonor()));

        $columns = ['proposals.id', 'proposals.name', 'proposals.slug', 'proposals.status', 'proposals.donor_id'];

        return $this->proposals()
            ->whereIn('proposals.status', $statuses)
            ->select($withAmounts ? [...$columns, 'proposals.requested_budget', 'proposals.requested_currency'] : $columns)
            ->with(['donor:id,name,slug,is_internal', ...($withAmounts ? ['donorProposals:id,proposal_id,budget,currency'] : [])])
            ->orderBy('proposals.name')
            ->get();
    }

    /**
     * The risks logged against this project.
     *
     * @return HasMany<Risk, $this>
     */
    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class);
    }

    /**
     * The milestones planned for this project.
     *
     * @return HasMany<Milestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class);
    }

    /**
     * The indicator targets attributed to this project.
     *
     * @return HasMany<PlanIndicator, $this>
     */
    public function planIndicators(): HasMany
    {
        return $this->hasMany(PlanIndicator::class);
    }

    /**
     * One monthly report per month of the project period (contracts §13).
     *
     * @return HasMany<MonthlyReport, $this>
     */
    public function monthlyReports(): HasMany
    {
        return $this->hasMany(MonthlyReport::class);
    }

    /**
     * The project's monthly report template (contracts.md §12.4), once its
     * first proposal is approved.
     *
     * @return HasOne<ReportTemplate, $this>
     */
    public function reportTemplate(): HasOne
    {
        return $this->hasOne(ReportTemplate::class);
    }

    /**
     * Indicators scoped directly to this project.
     *
     * `indicators.project_id` is nullable, so most indicators are programme
     * level and belong to no project at all.
     *
     * @return HasMany<Indicator, $this>
     */
    public function indicators(): HasMany
    {
        return $this->hasMany(Indicator::class);
    }

    /**
     * Files attached to this project — proposal PDFs, signed agreements, MoUs
     * and photos.
     *
     * @return MorphMany<Media, $this>
     */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    /**
     * The sum of the four demographic targets, or null when none are set.
     *
     * Deliberately separate from `target_beneficiaries`: a proposal often
     * commits a headline total before the disaggregation is agreed, so the two
     * are allowed to disagree and the UI can show that they do.
     */
    public function targetDemographicTotal(): ?int
    {
        $parts = [$this->target_men, $this->target_women, $this->target_boys, $this->target_girls];

        if (array_filter($parts, fn (?int $part): bool => $part !== null) === []) {
            return null;
        }

        return (int) array_sum($parts);
    }

    /**
     * Limit the query to projects still being delivered.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', self::openStatusValues());
    }

    /**
     * Limit the query to projects a proposal may be linked to: draft,
     * approved, active or closed (a closed one is reopened as a draft on
     * link), never archived, and never one whose owner rejected it.
     *
     * The one server-side rule behind every project picker and every link,
     * so a tampered id is refused by the same query that built the list.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function linkableToProposals(Builder $query): void
    {
        $query
            ->whereIn('status', [
                ProjectStatus::Draft->value,
                ProjectStatus::Approved->value,
                ProjectStatus::Active->value,
                ProjectStatus::Closed->value,
            ])
            ->whereDoesntHave('ownerAssignment', fn (Builder $assignment) => $assignment->where('status', AssignmentStatus::Rejected->value));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'target_beneficiaries' => 'integer',
            'target_men' => 'integer',
            'target_women' => 'integer',
            'target_boys' => 'integer',
            'target_girls' => 'integer',
            'working_days' => 'array',
        ];
    }

    public function activityModule(): string
    {
        return 'projects';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
    }

    public function activityProgramId(): ?int
    {
        $programId = $this->activityAttribute('program_id');

        return $programId === null ? null : (int) $programId;
    }

    /**
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return [
            'status' => 'status_changed',
            'user_id' => 'owner_changed',
            'start_date' => 'dates_changed',
            'end_date' => 'dates_changed',
            'name' => 'updated',
        ];
    }

    /**
     * A move to approved or active is its own event (contracts.md §15), so one
     * change raises one event; any other status move stays status_changed.
     */
    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        if ($field === 'status') {
            return match ($new) {
                ProjectStatus::Approved->value => 'approved',
                ProjectStatus::Active->value => 'activated',
                default => 'status_changed',
            };
        }

        return $this->activityEvents()[$field] ?? null;
    }

    /**
     * Remember how the next save came about (the approval path and the
     * proposal behind it) so the activity it records carries it.
     *
     * @param  array<string, mixed>  $context
     */
    public function setActivityContext(array $context): void
    {
        $this->activityContext = $context;
    }

    /**
     * @return array<string, mixed>
     */
    protected function activityProperties(string $event): array
    {
        return in_array($event, ['project.approved', 'project.activated'], true) ? $this->activityContext : [];
    }

    /**
     * @return list<string>
     */
    public function activityEmployeeFields(): array
    {
        return ['user_id'];
    }

    /**
     * The owner is asked to acknowledge or reject being put in charge.
     *
     * @return list<string>
     */
    public function assignmentFields(): array
    {
        return ['user_id'];
    }

    /**
     * The owner acknowledged being in charge: a draft that waits on a
     * donor-approved proposal becomes approved. A no-op otherwise.
     */
    public function assignmentAcknowledged(string $field): void
    {
        if ($field === 'user_id') {
            app(ApproveProjectFromProposal::class)->handle($this, 'owner_acknowledgement');
        }
    }

    /**
     * The owner assignment that stands right now, loadable for a whole list
     * (a rejected one locks the project, see ProjectPolicy).
     *
     * @return MorphOne<Assignment, $this>
     */
    public function ownerAssignment(): MorphOne
    {
        return $this->morphOne(Assignment::class, 'assignable')->ofMany(
            ['id' => 'max'],
            fn (Builder $query) => $query->where('field', 'user_id')->where('status', '!=', AssignmentStatus::Superseded->value),
        );
    }

    /**
     * Whether the owner rejected being put in charge: nothing more can be
     * done on the project (it can only be archived).
     */
    public function isOwnerRejected(): bool
    {
        return $this->ownerAssignment?->status === AssignmentStatus::Rejected;
    }

    /**
     * The owner, remembered for the deleted event.
     *
     * @return array<string, mixed>
     */
    protected function activityPropertiesBeforeDelete(): array
    {
        return ['employee_ids' => array_filter([(int) $this->activityAttribute('user_id')])];
    }

    /**
     * The program a project belongs to, without loading the project.
     */
    public static function programIdFor(mixed $projectId): ?int
    {
        $programId = $projectId === null ? null : static::query()->whereKey($projectId)->value('program_id');

        return $programId === null ? null : (int) $programId;
    }

    /**
     * The HR employee who owns a project, without loading it.
     */
    public static function ownerEmployeeIdFor(mixed $projectId): ?int
    {
        $ownerId = $projectId === null ? null : static::query()->whereKey($projectId)->value('user_id');

        return $ownerId === null ? null : (int) $ownerId;
    }
}
