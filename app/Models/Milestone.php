<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasSlug;
use App\Concerns\HasTree;
use App\Concerns\RecordsActivity;
use App\Enums\MilestoneStatus;
use App\Enums\ResultType;
use App\Notifications\TracksActivity;
use App\Observers\MediaOwnerObserver;
use App\Observers\MilestoneObserver;
use App\Observers\ScheduleItemObserver;
use Carbon\CarbonImmutable;
use Database\Factories\MilestoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int|null $project_id
 * @property int|null $proposal_id
 * @property bool $is_final_report
 * @property CarbonImmutable|null $planned_dates_edited_at
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
 * @property int|null $copied_from_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'project_id',
    'proposal_id',
    'name',
    'description',
    'planned_start_date',
    'planned_end_date',
    'status',
    'sequence',
    'weight_percentage',
    'responsible_user_id',
    'parent_milestone_id',
    'proposal_id',
    'copied_from_id',
])]
#[ObservedBy([MilestoneObserver::class, MediaOwnerObserver::class, ScheduleItemObserver::class])]
class Milestone extends Model implements TracksActivity
{
    /** @use HasFactory<MilestoneFactory> */
    use HasFactory, RecordsActivity;

    use HasSlug, HasTree;

    /**
     * Mirror the column default so a milestone that has not been
     * round-tripped through the database still reports it (strict mode).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_final_report' => false,
    ];

    protected function slugSource(): string
    {
        return $this->name;
    }

    /**
     * The milestone this one was copied from when a proposal was renewed.
     *
     * @return BelongsTo<Milestone, $this>
     */
    public function copiedFrom(): BelongsTo
    {
        return $this->belongsTo(Milestone::class, 'copied_from_id');
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
     * A user who moves the planned dates of an existing milestone marks it,
     * so the approval rebase (RebaseProposalPlan) leaves those dates alone
     * (contracts.md §12.2). The rebase itself writes through the query
     * builder, which fires no model events and so never sets this.
     */
    protected static function booted(): void
    {
        static::updating(function (Milestone $milestone): void {
            if ($milestone->isDirty(['planned_start_date', 'planned_end_date'])) {
                $milestone->planned_dates_edited_at = CarbonImmutable::now();
            }
        });
    }

    /**
     * The project this milestone belongs to: null for a milestone shared at
     * proposal level (contracts.md §12.2).
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The proposal whose plan this milestone is part of, if any.
     *
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * The project or, for a proposal-level milestone, the proposal it sits
     * under, for labels on pages that list milestones of every project.
     */
    public function ownerName(): string
    {
        $owner = $this->project_id !== null ? $this->project : $this->proposal;

        return $owner === null ? __('milestones.no_project') : $owner->name;
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
     * Activities a milestone of this project may deliver (contracts §11.2):
     * an activity of a proposal funding the project, or of the project's
     * program framework. A proposal-level milestone (with a `proposal_id`)
     * delivers only its own proposal's activities. The one definition the
     * link action and every picker read.
     *
     * @return Builder<Result>
     */
    public static function allowedActivitiesFor(?int $projectId, ?int $proposalId = null): Builder
    {
        $activities = Result::query()->where('type', ResultType::Activity->value);

        if ($proposalId !== null) {
            return $activities->where('proposal_id', $proposalId);
        }

        return $activities->where(fn (Builder $query) => $query
            ->whereIn('proposal_id', DB::table('project_proposal')->select('proposal_id')->where('project_id', $projectId))
            ->orWhere(fn (Builder $query) => $query
                ->whereNull('proposal_id')
                ->whereIn('program_id', Project::query()->select('program_id')->whereKey($projectId))));
    }

    /**
     * @return Builder<Result>
     */
    public function allowedActivities(): Builder
    {
        return self::allowedActivitiesFor($this->project_id, $this->proposal_id);
    }

    /**
     * Why the stored activity link no longer fits, as translation keys. A
     * link is never fixed automatically: unlinking the project from the
     * proposal or moving the project's program only flags it here.
     *
     * @return list<string>
     */
    public function activityLinkIssues(): array
    {
        if ($this->result_id === null || $this->allowedActivities()->whereKey($this->result_id)->exists()) {
            return [];
        }

        $activity = Result::query()->select(['id', 'type', 'proposal_id'])->find($this->result_id);

        return match (true) {
            $activity === null => [],
            $activity->type !== ResultType::Activity => ['milestones.activity_link_not_activity'],
            $activity->proposal_id === null => ['milestones.activity_link_program_moved'],
            default => ['milestones.activity_link_not_funding'],
        };
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
            'is_final_report' => 'boolean',
            'planned_dates_edited_at' => 'datetime',
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
            'result_id' => 'updated',
        ];
    }

    /**
     * The rebase bookkeeping stamp is not news.
     *
     * @return list<string>
     */
    protected function activityExcludedFields(): array
    {
        return ['planned_dates_edited_at'];
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
