<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasSlug;
use App\Concerns\HasTree;
use App\Concerns\RecordsActivity;
use App\Enums\MilestoneStatus;
use App\Notifications\TracksActivity;
use App\Observers\MediaOwnerObserver;
use App\Observers\MilestoneObserver;
use App\Observers\ScheduleItemObserver;
use Carbon\CarbonImmutable;
use Database\Factories\MilestoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property CarbonImmutable $planned_start_date
 * @property CarbonImmutable|null $planned_end_date
 * @property CarbonImmutable|null $actual_start_date
 * @property CarbonImmutable|null $actual_end_date
 * @property MilestoneStatus $status
 * @property int $sequence
 * @property string $weight_percentage
 * @property int $responsible_user_id
 * @property int|null $parent_milestone_id
 * @property int|null $proposal_id
 * @property int|null $result_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'project_id',
    'name',
    'description',
    'planned_start_date',
    'planned_end_date',
    'status',
    'sequence',
    'weight_percentage',
    'responsible_user_id',
    'parent_milestone_id',
])]
#[ObservedBy([MilestoneObserver::class, MediaOwnerObserver::class, ScheduleItemObserver::class])]
class Milestone extends Model implements TracksActivity
{
    /** @use HasFactory<MilestoneFactory> */
    use HasFactory, RecordsActivity;

    use HasSlug, HasTree;

    protected function slugSource(): string
    {
        return $this->name;
    }

    /**
     * The column pointing at the milestone this one is nested under.
     */
    public static function parentKeyName(): string
    {
        return 'parent_milestone_id';
    }

    /**
     * Milestones within a project are ordered by their planned sequence.
     */
    public static function childrenOrderColumn(): ?string
    {
        return 'sequence';
    }

    /**
     * The project this milestone belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The framework activity this milestone delivers (contracts §11.2).
     * Linked only through LinkMilestoneToActivity, so it is not fillable.
     *
     * @return BelongsTo<Result, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Result::class, 'result_id');
    }

    /**
     * The HR employee accountable for reaching this milestone.
     *
     * The column keeps its original name, `responsible_user_id`, but holds an HR
     * employee id.
     *
     * @return BelongsTo<HrEmployee, $this>
     */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'responsible_user_id');
    }

    /**
     * The tasks sourced from this milestone.
     *
     * @return MorphMany<Task, $this>
     */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'source');
    }

    /**
     * Evidence files attached to this milestone.
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
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'actual_start_date' => 'date',
            'actual_end_date' => 'date',
            'status' => MilestoneStatus::class,
            'sequence' => 'integer',
            'weight_percentage' => 'decimal:2',
        ];
    }

    /**
     * Whether the milestone finished later than planned.
     *
     * Derived rather than stored: comparing the two dates directly can never
     * drift from what actually happened, the way a cached flag could.
     */
    public function isOverdue(): bool
    {
        return $this->actual_end_date !== null
            && $this->planned_end_date !== null
            && $this->actual_end_date->gt($this->planned_end_date);
    }

    /**
     * Whether the milestone's planned end date has passed while it is still
     * sitting in Planned — nobody ever moved it to Ongoing or Reached.
     *
     * Also derived rather than stored, so it can never disagree with `status`
     * the way a separate "missed" status could.
     */
    public function isMissed(): bool
    {
        return $this->status === MilestoneStatus::Planned
            && $this->planned_end_date !== null
            && $this->planned_end_date->isPast();
    }

    public function activityModule(): string
    {
        return 'milestones';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
    }

    public function activityProgramId(): ?int
    {
        return Project::programIdFor($this->activityAttribute('project_id'));
    }

    /**
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return [
            'status' => 'status_changed',
            'responsible_user_id' => 'responsible_changed',
            'planned_start_date' => 'dates_changed',
            'planned_end_date' => 'dates_changed',
            'name' => 'updated',
        ];
    }

    /**
     * @return list<string>
     */
    public function activityEmployeeFields(): array
    {
        return ['responsible_user_id'];
    }

    /**
     * The responsible person and project owner, remembered for the deleted event.
     *
     * @return array<string, mixed>
     */
    protected function activityPropertiesBeforeDelete(): array
    {
        return ['employee_ids' => array_values(array_filter([
            (int) $this->activityAttribute('responsible_user_id'),
            Project::ownerEmployeeIdFor($this->activityAttribute('project_id')),
        ]))];
    }
}
