<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Locations\ApproveLocationFromProposal;
use App\Concerns\HasAssignments;
use App\Concerns\HasSlug;
use App\Concerns\RecordsParentActivity;
use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Notifications\ActivityRecorder;
use App\Notifications\TracksActivity;
use App\Observers\MediaOwnerObserver;
use App\Observers\ProposalLocationObserver;
use App\Support\Locations\MonthlyReportsAtRows;
use App\Support\Reporting\ReportPlace;
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
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;

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
class ProposalLocation extends Model implements TracksActivity
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
     * Whether this row's first centre is one HR has deactivated (D-66), the
     * twin of `Project::hasInactiveCenter()`. Read from the in-memory HR
     * lookup, so a page of rows costs no query per row. A city-wide row is
     * field work (D-88) and has no centre to be inactive.
     */
    public function hasInactiveCenter(): bool
    {
        $centerId = $this->centerIds()[0] ?? null;

        if ($centerId === null) {
            return false;
        }

        try {
            return HrCenter::remembered($centerId)?->is_active === false;
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * The project indicator instances scoped to this row (Y-16, D-93).
     *
     * An instance with no row is shared by the proposal's rows; the project
     * page adds those separately (ReadsProjectDelivery), so this relation is
     * the row's own set.
     *
     * @return HasMany<Indicator, $this>
     */
    public function indicators(): HasMany
    {
        return $this->hasMany(Indicator::class, 'proposal_location_id');
    }

    /**
     * The monthly reports of this row's place, oldest first (Y-16).
     *
     * Not a relation: a monthly report belongs to a **template**, and a
     * template reaches a row only by matching its place, which no foreign key
     * expresses (App\Support\Locations\MonthlyReportsAtRows). Joining on the
     * project instead would hand the same report to every row of that project,
     * and two proposals may share a place (D-77).
     *
     * A list of rows should fill this in one read with setMonthlyReports();
     * a single row may read it here.
     *
     * @return Collection<int, MonthlyReport>
     */
    /**
     * The reports of this row's place once read; see monthlyReports().
     *
     * @var Collection<int, MonthlyReport>|null
     */
    protected ?Collection $monthlyReportsAtPlace = null;

    public function monthlyReports(): Collection
    {
        return $this->monthlyReportsAtPlace ??= app(MonthlyReportsAtRows::class)->forRow($this);
    }

    /**
     * Hand the row the reports a list already read for it (one query for the
     * page instead of two per row).
     *
     * @param  Collection<int, MonthlyReport>  $reports
     */
    public function setMonthlyReports(Collection $reports): void
    {
        $this->monthlyReportsAtPlace = $reports;
    }

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
     * The sectors this row covers (contracts.md §7, §12.5). Written only
     * by SyncLocationSectors (Y-17), which is their one writer.
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
     * The owner assignment this place stands for, wherever it is filed.
     *
     * The place is what a person is put in charge of (Y-16, D-93), but the
     * owner column still lives on the project until Y-18, and so does the
     * assignment raised when it changes. A place that holds none of its own
     * answers with the project's, so the banner, the bell and "mark as seen"
     * all see the same pending request.
     */
    public function currentOwnerAssignment(): ?Assignment
    {
        $own = $this->currentAssignment('responsible_employee_id');

        if ($own !== null || $this->project_id === null) {
            return $own;
        }

        return Assignment::query()
            ->current()
            ->where('assignable_type', 'project')
            ->where('assignable_id', $this->project_id)
            ->where('field', 'user_id')
            ->latest('id')
            ->first();
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
     * The place this row delivers at (D-75): the city, the program and either
     * field work (D-88), its centre (D-90) or "centre not set" (D-82).
     *
     * Two rows of two proposals may stand at one place (D-77), and a report
     * template names a place, not a row. Load `centers`.
     */
    public function place(): ReportPlace
    {
        // The program is cast the way MealCoverage::responsibleForLocation()
        // casts it, so one row never produces two different place keys.
        return new ReportPlace($this->city_id, (int) $this->program_id, $this->centerIds()[0] ?? null, (bool) $this->city_wide);
    }

    /**
     * What a list calls this row: its proposal's name, since a row is of
     * exactly one proposal (D-93) and the register "names its proposal
     * first" (Y-16). `label()` says where it is; the two read together as
     * "<proposal> / <programme> · <city> · <centre>".
     *
     * Load `proposal`; a row whose proposal is gone falls back to its place.
     */
    public function headline(): string
    {
        return $this->proposal?->name ?? $this->label();
    }

    /**
     * Whether the row is still being delivered (ProjectStatus::isOpen()).
     */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * How this row is named wherever a record is listed by name — the
     * sidebar's recently used records, for one (Y-16).
     *
     * Its proposal's name, which is what the Projects screen and the row's
     * own page call it (`headline()`).
     */
    public function activityLabel(): string
    {
        $this->loadMissing(['proposal', 'city', 'program', 'centers']);

        return $this->headline();
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
     * The ids of every paused place: a row whose own centre HR has
     * deactivated (D-66). Read once per render, the twin of
     * `Project::pausedIds()`.
     *
     * @return list<int>
     */
    public static function pausedIds(): array
    {
        $inactive = HrCenter::inactiveIds();

        if ($inactive === []) {
            return [];
        }

        return array_values(static::query()
            ->where('city_wide', false)
            ->whereHas('centers', fn (Builder $center) => $center->whereIn('center_id', $inactive))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
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
    /**
     * Only the rows whose work is paused: one of their own centres HR has
     * deactivated (D-66), the twin of `Project::paused()`. A city-wide row
     * is field work (D-88) and is never paused by a centre.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function paused(Builder $query): void
    {
        $inactive = HrCenter::inactiveIds();

        if ($inactive === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('city_wide', false)
            ->whereHas('centers', fn (Builder $center) => $center->whereIn('center_id', $inactive));
    }

    /**
     * Leave out rows whose work is paused: a centre HR has deactivated
     * (D-66), the twin of `Project::notPaused()`. A city-wide row is field
     * work (D-88) and is never paused by a centre.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function notPaused(Builder $query): void
    {
        $inactive = HrCenter::inactiveIds();

        if ($inactive === []) {
            return;
        }

        $query->whereDoesntHave('centers', fn (Builder $center) => $center->whereIn('center_id', $inactive));
    }

    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', array_values(array_map(
            fn (ProjectStatus $status): string => $status->value,
            array_filter(ProjectStatus::cases(), fn (ProjectStatus $status): bool => $status->isOpen()),
        )));
    }

    /**
     * The row's responsible is asked to acknowledge being put in charge
     * (Y-17): the row is the writer now, so the request is raised here and
     * nowhere else. Until Y-17 the project asked and the mirror copied the
     * answer onto the row.
     *
     * @return list<string>
     */
    public function assignmentFields(): array
    {
        return ['responsible_employee_id'];
    }

    /**
     * The responsible acknowledged being in charge: a draft that waits on a
     * donor-approved proposal becomes approved. A no-op otherwise. The twin
     * of `Project::assignmentAcknowledged()` (contracts.md §3).
     */
    public function assignmentAcknowledged(string $field): void
    {
        if ($field === 'responsible_employee_id') {
            app(ApproveLocationFromProposal::class)->handle($this, 'owner_acknowledgement');
        }
    }

    /**
     * The row's own activities (Y-17). The row records no created / updated
     * / deleted event of its own — `SaveProposal` records the set's change
     * for the whole proposal — but it IS the subject of the lifecycle
     * activities its actions write by hand, so it answers the recorder.
     * The module and the event keys stay `projects` / `project.*`: they name
     * a screen, not a table (wave4/AGENT.md §8.5).
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @param  array<string, mixed>  $properties
     */
    public function recordActivity(string $event, array $changes = [], array $properties = []): ?Activity
    {
        return app(ActivityRecorder::class)->record($this, $event, $changes, $properties);
    }

    public function activityModule(): string
    {
        return 'projects';
    }

    public function activityProgramId(): ?int
    {
        return $this->program_id === null ? null : (int) $this->program_id;
    }

    /**
     * @return list<string>
     */
    public function activityEmployeeFields(): array
    {
        return ['responsible_employee_id'];
    }

    public function activityParent(): (Model&TracksActivity)|null
    {
        return $this->proposal;
    }

    /**
     * Row-level hooks record nothing on the proposal: SaveProposal records
     * the set's change once, with counts.
     */
    protected function parentActivityEvent(string $hook): ?string
    {
        return null;
    }
}
