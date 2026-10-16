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
     * Activities a milestone of this project may deliver (contracts §11.2):
     * an activity of a proposal funding the project, or of the project's
     * program framework. A proposal-level milestone (with a `proposal_id`)
     * delivers only its own proposal's activities. The one definition the
     * link action and every picker read.
     *
     * @return Builder<Result>
     */
    public static function allowedActivitiesFor(int $projectId, ?int $proposalId = null): Builder
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
     * activityLinkIssues() for many milestones in three queries, whatever
     * their number: the same rule as allowedActivitiesFor() and the same
     * keys, read in memory. Pages that list milestones call this instead of
     * the per-row form (ResultsChain).
     *
     * @param  iterable<Milestone>  $milestones
     * @return array<int, list<string>> keyed by milestone id
     */
    public static function activityLinkIssuesFor(iterable $milestones): array
    {
        $milestones = collect($milestones);
        $linked = $milestones->whereNotNull('result_id');
        $issues = $milestones->mapWithKeys(fn (Milestone $milestone): array => [$milestone->id => []])->all();

        if ($linked->isEmpty()) {
            return $issues;
        }

        $projectIds = $linked->pluck('project_id')->unique()->values()->all();

        $fundingByProject = DB::table('project_proposal')
            ->select(['project_id', 'proposal_id'])
            ->whereIn('project_id', $projectIds)
            ->get()
            ->groupBy('project_id')
            ->map(fn ($rows): array => $rows->pluck('proposal_id')->map(fn ($id): int => (int) $id)->all());

        $programOf = Project::query()->whereIn('id', $projectIds)->pluck('program_id', 'id');

        $activities = Result::query()
            ->select(['id', 'type', 'proposal_id', 'program_id'])
            ->whereIn('id', $linked->pluck('result_id')->unique()->values()->all())
            ->get()
            ->keyBy('id');

        foreach ($linked as $milestone) {
            $activity = $activities->get($milestone->result_id);

            if ($activity === null) {
                continue;
            }

            $allowed = $activity->type === ResultType::Activity && match (true) {
                $milestone->proposal_id !== null => $activity->proposal_id === $milestone->proposal_id,
                $activity->proposal_id !== null => in_array($activity->proposal_id, $fundingByProject->get($milestone->project_id, []), true),
                default => $activity->program_id !== null && $activity->program_id === $programOf->get($milestone->project_id),
            };

            if ($allowed) {
                continue;
            }

            $issues[$milestone->id] = match (true) {
                $activity->type !== ResultType::Activity => ['milestones.activity_link_not_activity'],
                $activity->proposal_id === null => ['milestones.activity_link_program_moved'],
                default => ['milestones.activity_link_not_funding'],
            };
        }

        return $issues;
    }

    /**
     * Weighted completion of a set of milestones, in percent: reached weight
     * over every weight that still counts (cancelled milestones are left out
     * of both sides). The PHP form of the `withSum` reads on the project
     * directory, project and program pages; null when no weight counts.
     *
     * @param  iterable<Milestone>  $milestones
     */
    public static function weightedProgress(iterable $milestones): ?float
    {
        $planned = 0.0;
        $reached = 0.0;

        foreach ($milestones as $milestone) {
            if ($milestone->status === MilestoneStatus::Cancelled) {
                continue;
            }

            $planned += (float) $milestone->weight_percentage;

            if ($milestone->status === MilestoneStatus::Reached) {
                $reached += (float) $milestone->weight_percentage;
            }
        }

        if ($planned <= 0) {
            return null;
        }

        return round(min(100, ($reached / $planned) * 100), 1);
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
            'result_id' => 'updated',
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
