<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Observers\MediaOwnerObserver;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
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

/**
 * @property int $id
 * @property string $name
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
 * @property int $program_id
 * @property int $user_id
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
    'program_id',
    'user_id',
])]
#[ObservedBy(MediaOwnerObserver::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

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
        return $this->belongsToMany(Proposal::class)->withTimestamps();
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
        $query->whereIn('status', [
            ProjectStatus::Draft->value,
            ProjectStatus::Submitted->value,
            ProjectStatus::Approved->value,
            ProjectStatus::Active->value,
        ]);
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
        ];
    }
}
