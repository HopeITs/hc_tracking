<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasAssignments;
use App\Concerns\HasSlug;
use App\Concerns\RecordsParentActivity;
use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Notifications\TracksActivity;
use App\Observers\MediaOwnerObserver;
use App\Observers\ProposalLocationObserver;
use Carbon\CarbonImmutable;
use Database\Factories\ProposalLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * One location row of a proposal (contracts.md §7, W-34a, D-62): a city, and
 * optionally the program, the responsible employee and the centers the work
 * runs from. `is_new` says no project exists for it yet; `city_wide` (D-88) says the
 * program runs across the city with no center, so the row holds none; `project_id` is
 * filled when the row is linked to an existing project or materialised on
 * approval. City, employee and center ids are HR ids, never copied names.
 *
 * Rows are written only by SaveProposal (as a set), the link wrappers and
 * the backfill. Their changes are recorded once on the proposal as
 * `proposal.locations_changed` with counts, so this model records no event
 * of its own.
 *
 * @property int $id
 * @property int $proposal_id
 * @property int $city_id
 * @property int|null $program_id
 * @property int|null $responsible_employee_id
 * @property bool $is_new
 * @property bool $city_wide
 * @property int|null $project_id
 * @property int $sort
 * @property string|null $slug
 * @property ProjectStatus $status
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $end_date
 * @property string $schedule_weekend
 * @property list<int>|null $working_days
 * @property string|null $description
 * @property int|null $target_beneficiaries
 * @property int|null $target_men
 * @property int|null $target_women
 * @property int|null $target_boys
 * @property int|null $target_girls
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'proposal_id',
    'city_id',
    'program_id',
    'responsible_employee_id',
    'is_new',
    'city_wide',
    'project_id',
    'sort',
    'status',
    'start_date',
    'end_date',
    'schedule_weekend',
    'working_days',
    'description',
    'target_beneficiaries',
    'target_men',
    'target_women',
    'target_boys',
    'target_girls',
])]
#[ObservedBy([MediaOwnerObserver::class, ProposalLocationObserver::class])]
class ProposalLocation extends Model
{
    /** @use HasFactory<ProposalLocationFactory> */
    use HasAssignments, HasFactory, HasSlug, RecordsParentActivity;

    /**
     * Mirror the column defaults so an unrefreshed model reads them.
     * `Model::shouldBeStrict` is on outside production, so a factory that
     * omits a defaulted column would otherwise raise MissingAttributeException.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_new' => false,
        'city_wide' => false,
        'sort' => 0,
        'status' => 'draft',
        'schedule_weekend' => 'sat-sun',
    ];

    /**
     * The row's readable route key: the proposal's internal number, the city
     * and the program. Written once by the Y-11 backfill or on create; never
     * regenerated (HasSlug).
     */
    protected function slugSource(): string
    {
        // Read from the table, not $this->proposal: callers often have the
        // relation loaded with a narrow select (ProjectSuccessor does), and
        // strict mode raises MissingAttributeException on the missing column.
        $number = Proposal::query()->whereKey($this->proposal_id)->value('internal_project_number');

        $parts = array_filter([
            $number,
            $this->city?->name,
            $this->program_id === null ? null : $this->program?->name,
        ]);

        return $parts === [] ? 'location-'.$this->proposal_id : implode(' ', $parts);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'city_id' => 'integer',
            'program_id' => 'integer',
            'responsible_employee_id' => 'integer',
            'is_new' => 'boolean',
            'city_wide' => 'boolean',
            'project_id' => 'integer',
            'sort' => 'integer',
            'status' => ProjectStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'working_days' => 'array',
            'target_beneficiaries' => 'integer',
            'target_men' => 'integer',
            'target_women' => 'integer',
            'target_boys' => 'integer',
            'target_girls' => 'integer',
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
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * The project this row stands for, once linked or materialised.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The HR centers of this row.
     *
     * @return HasMany<ProposalLocationCenter, $this>
     */
    /**
     * The HR employee responsible for this row (Y-16): the twin of
     * `Project::user()`, which the Projects screen read as its owner.
     *
     * A plain lookup against the read-only HR connection, like every other
     * HR link: no join crosses the two databases.
     *
     * @return BelongsTo<HrEmployee, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'responsible_employee_id');
    }

    public function centers(): HasMany
    {
        return $this->hasMany(ProposalLocationCenter::class)->orderBy('center_id');
    }

    /**
     * "City · program" for each row, the name a target's location carries
     * (Y-10). Cities are read from HR in one query; a row still waiting for
     * its program shows the city alone. Load `program` on the rows first.
     *
     * @param  iterable<ProposalLocation>  $rows
     * @return array<int, string> row id => label
     */
    public static function placeLabels(iterable $rows): array
    {
        $rows = collect($rows);
        $cities = HrCity::query()
            ->whereKey($rows->pluck('city_id')->unique()->values()->all())
            ->get(['id', 'name_en', 'name_ar'])
            ->keyBy('id');

        return $rows->mapWithKeys(function (ProposalLocation $row) use ($cities): array {
            $city = $cities->get($row->city_id)->name ?? __('common.unknown_city');
            $program = $row->program_id === null ? null : $row->program?->name;

            return [$row->id => $program === null ? $city : $city.' · '.$program];
        })->all();
    }

    /**
     * The proposal targets of this row (Y-10, D-91): one per indicator.
     *
     * @return HasMany<PlanIndicator, $this>
     */
    /**
     * The register risks raised against this row (Y-13, D-93). Logframe risks
     * belong to a proposal and are not here.
     *
     * @return HasMany<Risk, $this>
     */
    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class, 'proposal_location_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PlanIndicator::class, 'proposal_location_id');
    }

    /**
     * The request opened when this row names a program HR does not have.
     *
     * @return HasOne<ProgramRequest, $this>
     */
    public function programRequest(): HasOne
    {
        return $this->hasOne(ProgramRequest::class);
    }

    /**
     * The HR city of the row, from the HR connection.
     *
     * @return BelongsTo<HrCity, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(HrCity::class, 'city_id');
    }

    /**
     * The center ids of the row, ascending. Needs `centers` loaded.
     *
     * @return list<int>
     */
    public function centerIds(): array
    {
        return array_values($this->centers->map(fn (ProposalLocationCenter $center): int => $center->center_id)->all());
    }

    /**
     * The sectors this row covers (contracts.md §7, §12.5), the twin of
     * `Project::sectors()`. Written only by the Y-11 backfill and, from
     * slice 2, ProjectLocationMirror.
     *
     * @return BelongsToMany<Sector, $this>
     */
    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(Sector::class, 'proposal_location_sector')->withTimestamps();
    }

    /**
     * Files attached to this row, the twin of `Project::media()`.
     * MediaOwnerObserver removes them with the row: `media.mediable_id`
     * carries no foreign key.
     *
     * @return MorphMany<Media, $this>
     */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    /**
     * The owner assignment that stands right now, loadable for a whole list.
     * The twin of `Project::ownerAssignment()`, on the row's own field.
     *
     * @return MorphOne<Assignment, $this>
     */
    public function ownerAssignment(): MorphOne
    {
        return $this->morphOne(Assignment::class, 'assignable')->ofMany(
            ['id' => 'max'],
            fn (Builder $query) => $query->where('field', 'responsible_employee_id')->where('status', '!=', AssignmentStatus::Superseded->value),
        );
    }

    /**
     * Where the row is, for every list and card: the program, the city and
     * the centers it runs from, or "Field work" for a city-wide row (D-88).
     * The twin of `Project::locationLabel()` and the project's name.
     *
     * Reads only loaded relations and the in-memory HR lookup, so a list of
     * rows costs no query per row. Load `city`, `program` and `centers`.
     */
    public function label(): string
    {
        $city = $this->city->name ?? __('common.unknown_city');
        $program = $this->program_id === null ? null : $this->program?->name;
        $place = $program === null ? $city : $program.' · '.$city;

        if ($this->city_wide) {
            return $place.' · '.__('proposals.location_field_work');
        }

        $centers = array_filter(array_map(
            fn (int $centerId): ?string => HrCenter::remembered($centerId)?->name,
            $this->centerIds(),
        ));

        return $centers === [] ? $place : $place.' · '.implode('، ', $centers);
    }

    /**
     * Whether the row is still being delivered (ProjectStatus::isOpen()).
     */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * The four demographic targets added up, or null when none is set
     * (Y-16): the twin of `Project::targetDemographicTotal()`, on the columns
     * Y-11 moved onto the row.
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
     * Where the row stands on its way to being funded, for the badge on the
     * Projects screen; null when nothing is pending (Y-16).
     *
     * The twin of `Project::fundingState()`, and simpler for the same reason:
     * a row is of exactly one proposal (D-93), so there is one status to read
     * rather than a set to scan.
     */
    public function fundingState(): ?string
    {
        $this->loadMissing(['proposal:id,status', 'ownerAssignment']);

        if ($this->status === ProjectStatus::Approved) {
            return 'funded_awaiting_template';
        }

        if ($this->status !== ProjectStatus::Draft) {
            return null;
        }

        $proposalStatus = $this->proposal?->status;

        if ($proposalStatus?->isApprovedByDonor() === true) {
            return $this->isOwnerRejected() || $this->ownerAssignment?->status === AssignmentStatus::Acknowledged ? null : 'awaiting_owner';
        }

        return $proposalStatus === ProposalStatus::Submitted ? 'awaiting_funding' : null;
    }

    /**
     * Whether the proposal behind this row is still live (Y-16).
     *
     * The twin of `Project::hasActiveProposal()`, and simpler: a row is of
     * exactly one proposal (D-93), so there is nothing to scan.
     */
    public function hasActiveProposal(): bool
    {
        $this->loadMissing('proposal:id,status');

        return $this->proposal !== null && ! $this->proposal->status->isTerminal();
    }

    /**
     * Whether the employee responsible for this row has rejected the
     * appointment, which blocks every change to it (Y-16, D-93).
     *
     * The twin of `Project::isOwnerRejected()`, on the row's own assignment.
     */
    public function isOwnerRejected(): bool
    {
        return $this->ownerAssignment?->status === AssignmentStatus::Rejected;
    }

    /**
     * Whether the row's non-report work is paused (D-66, W-02): one of its
     * own centers exists and HR has deactivated it. A city-wide row has no
     * center and is never paused. Needs `centers` loaded.
     */
    public function isPaused(): bool
    {
        if ($this->city_wide) {
            return false;
        }

        $inactive = HrCenter::inactiveIds();

        return array_intersect($this->centerIds(), $inactive) !== [];
    }

    /**
     * The HR employee responsible for this row.
     */
    public function ownerEmployeeId(): ?int
    {
        return $this->responsible_employee_id;
    }

    /**
     * The program a row belongs to, without loading the row. The twin of
     * `Project::programIdFor()`.
     */
    public static function programIdFor(mixed $locationId): ?int
    {
        $programId = $locationId === null ? null : static::query()->whereKey($locationId)->value('program_id');

        return $programId === null ? null : (int) $programId;
    }

    /**
     * The HR employee who owns a row, without loading it. The twin of
     * `Project::ownerEmployeeIdFor()`.
     */
    public static function ownerEmployeeIdFor(mixed $locationId): ?int
    {
        $ownerId = $locationId === null ? null : static::query()->whereKey($locationId)->value('responsible_employee_id');

        return $ownerId === null ? null : (int) $ownerId;
    }

    /**
     * Rows still being delivered.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', array_values(array_map(
            fn (ProjectStatus $status): string => $status->value,
            array_filter(ProjectStatus::cases(), fn (ProjectStatus $status): bool => $status->isOpen()),
        )));
    }

    /**
     * The row's owner is asked to acknowledge being put in charge — but not
     * before Y-17.
     *
     * Until then `projects` is the writer and the row is a mirror
     * (wave4/AGENT.md §8.2): the project already asks its owner, and the
     * acknowledgement is copied onto the row by the Y-11 backfill and, from
     * slice 2, ProjectLocationMirror. Returning no field keeps
     * HasAssignments' created/updated hook from asking the same person a
     * second time, which would notify them twice for one appointment while
     * "nothing a user sees changes". Y-17 returns
     * `['responsible_employee_id']` here when the row becomes the writer.
     *
     * The readers (`assignments()`, `currentAssignment()`, `ownerAssignment()`)
     * work regardless, and that is what the mirror and Y-12…Y-16 read.
     *
     * @return list<string>
     */
    public function assignmentFields(): array
    {
        return [];
    }

    public function activityParent(): (Model&TracksActivity)|null
    {
        return $this->proposal;
    }

    /**
     * Row-level hooks record nothing: SaveProposal records the set's change.
     */
    protected function parentActivityEvent(string $hook): ?string
    {
        return null;
    }
}
