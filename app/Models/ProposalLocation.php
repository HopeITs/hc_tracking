<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsParentActivity;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\ProposalLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['proposal_id', 'city_id', 'program_id', 'responsible_employee_id', 'is_new', 'city_wide', 'project_id', 'sort'])]
class ProposalLocation extends Model
{
    /** @use HasFactory<ProposalLocationFactory> */
    use HasFactory, RecordsParentActivity;

    /**
     * Mirror the column defaults so an unrefreshed model reads them.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_new' => false,
        'city_wide' => false,
        'sort' => 0,
    ];

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
