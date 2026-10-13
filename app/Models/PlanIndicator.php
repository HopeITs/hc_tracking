<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsActivity;
use App\Enums\IndicatorMeasure;
use App\Enums\Period;
use App\Enums\ReportStatus;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\PlanIndicatorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A target committed for one indicator, in one plan or proposal.
 *
 * @property int $id
 * @property string $source_type
 * @property int $source_id
 * @property int $indicator_id
 * @property string $target
 * @property int|null $project_id
 * @property Period $period
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period'])]
class PlanIndicator extends Model implements TracksActivity
{
    /** @use HasFactory<PlanIndicatorFactory> */
    use HasFactory, RecordsActivity;

    /**
     * The plan or proposal this target was committed in.
     *
     * There is no foreign key behind source_id, so a source can be deleted out
     * from under this row. See the migrations rule on orphans.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The indicator being targeted.
     *
     * @return BelongsTo<Indicator, $this>
     */
    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    /**
     * The project this target is attributed to, if any.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The actuals reported against this target.
     *
     * @return HasMany<Report, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * How much of this target has been achieved, counting approved reports only.
     *
     * This is the one definition every page uses. A numeric indicator
     * accumulates, so its approved values are summed; a percentage or average
     * indicator is a level, so the latest approved value stands on its own —
     * 40% then 55% is 55%, not 95%. Unapproved figures are not achievement until they clear
     * review; pendingValue() reports them separately.
     *
     * Answers from loaded `reports` and `indicator` relations when present, so
     * a list of targets does not query per row. achievedValueSql() is the same
     * rule for queries that filter or count on it, and must stay in step.
     */
    public function achievedValue(): float
    {
        $approved = $this->loadedReports()->where('status', ReportStatus::Approved);

        if ($this->measuresLevel()) {
            $latest = $approved
                ->sortBy([['planned_date', 'desc'], ['id', 'desc']])
                ->first();

            return $latest === null ? 0.0 : (float) $latest->value;
        }

        return (float) $approved->sum(fn (Report $report): float => (float) $report->value);
    }

    /**
     * Value reported but not yet approved, shown as a lighter "pending" band.
     *
     * Always zero for a percentage or average indicator: an unapproved level
     * does not add to an approved one.
     */
    public function pendingValue(): float
    {
        if ($this->measuresLevel()) {
            return 0.0;
        }

        return (float) $this->loadedReports()
            ->where('status', '!=', ReportStatus::Approved)
            ->sum(fn (Report $report): float => (float) $report->value);
    }

    /**
     * Achieved value as a percentage of the committed target, or null when
     * the target is zero and there is nothing to divide by.
     */
    public function percentAchieved(): ?float
    {
        $target = (float) $this->target;

        if ($target <= 0.0) {
            return null;
        }

        return $this->achievedValue() / $target * 100;
    }

    /**
     * 'achieved', 'in_progress' or 'not_started', by the same rule as
     * achievementStatusSql().
     */
    public function achievementStatus(): string
    {
        $achieved = $this->achievedValue();

        return match (true) {
            $achieved <= 0.0 => 'not_started',
            $achieved >= (float) $this->target => 'achieved',
            default => 'in_progress',
        };
    }

    /**
     * achievedValue() as a correlated SQL expression over `plan_indicators`.
     *
     * @return literal-string
     */
    public static function achievedValueSql(): string
    {
        $approved = ReportStatus::Approved->value;
        $sum = "(select coalesce(sum(r.value), 0) from reports r where r.plan_indicator_id = plan_indicators.id and r.status = '{$approved}')";
        $latest = "coalesce((select r.value from reports r where r.plan_indicator_id = plan_indicators.id and r.status = '{$approved}' order by r.planned_date desc, r.id desc limit 1), 0)";

        return '(case when '.self::measureSql().' in ('.self::levelMeasuresSql().") then {$latest} else {$sum} end)";
    }

    /**
     * pendingValue() as a correlated SQL expression over `plan_indicators`.
     *
     * @return literal-string
     */
    public static function pendingValueSql(): string
    {
        $approved = ReportStatus::Approved->value;

        return '(case when '.self::measureSql().' in ('.self::levelMeasuresSql().") then 0 else (select coalesce(sum(r.value), 0) from reports r where r.plan_indicator_id = plan_indicators.id and r.status <> '{$approved}') end)";
    }

    /**
     * The SQL condition for one achievementStatus() value.
     *
     * @return literal-string
     */
    public static function achievementStatusSql(string $status): string
    {
        $achieved = self::achievedValueSql();

        return match ($status) {
            'achieved' => "({$achieved} > 0 and {$achieved} >= plan_indicators.target)",
            'in_progress' => "({$achieved} > 0 and {$achieved} < plan_indicators.target)",
            default => "({$achieved} <= 0)",
        };
    }

    /**
     * Where this target does not fit the chain it sits in, as translation keys.
     *
     * Reads the loaded `source` (with a proposal's `projects`), `indicator`
     * (with `result`) and `project`; see chainIssuesFor() for the rules.
     *
     * @return list<string>
     */
    public function chainIssues(): array
    {
        return self::chainIssuesFor($this->source, $this->getRelation('indicator'), $this->project);
    }

    /**
     * Where a target committed in this source, for this indicator and project,
     * would not fit the chain.
     *
     * Four rules, each skipped when the side it compares is unset:
     * - an indicator scoped to one project can only be targeted for that project;
     * - an annual plan belongs to one program, so it can only commit targets on
     *   results of that program;
     * - a project delivering an annual-plan target must sit in the plan's program;
     * - a project delivering a proposal target must be one the proposal funds.
     *
     * @return list<string>
     */
    public static function chainIssuesFor(?Model $source, Indicator $indicator, ?Project $project): array
    {
        $issues = [];
        $resultProgramId = $indicator->relationLoaded('result') ? $indicator->result?->program_id : null;

        if ($indicator->project_id !== null && $project?->id !== $indicator->project_id) {
            $issues[] = 'targets.issue_project_differs_from_indicator';
        }

        if ($source instanceof AnnualPlan) {
            if ($resultProgramId !== null && $source->program_id !== $resultProgramId) {
                $issues[] = 'targets.issue_plan_program_differs_from_result';
            }

            if ($project !== null && $project->program_id !== $source->program_id) {
                $issues[] = 'targets.issue_project_outside_plan_program';
            }
        }

        if ($source instanceof Proposal && $project !== null) {
            $fundedProjectIds = $source->relationLoaded('projects')
                ? $source->getRelation('projects')->modelKeys()
                : $source->projects()->pluck('projects.id')->all();

            if (! in_array($project->id, $fundedProjectIds, true)) {
                $issues[] = 'targets.issue_project_not_on_proposal';
            }
        }

        return $issues;
    }

    /**
     * The reports behind this target, from the loaded relation when there is one.
     *
     * @return Collection<int, Report>
     */
    private function loadedReports(): Collection
    {
        return $this->relationLoaded('reports') ? $this->getRelation('reports') : $this->reports()->get();
    }

    /**
     * Whether this target measures a level (percentage or average) rather
     * than a running count. levelMeasuresSql() is the same list for SQL.
     */
    private function measuresLevel(): bool
    {
        $indicator = $this->relationLoaded('indicator')
            ? $this->getRelation('indicator')
            : $this->indicator()->select(['id', 'measure'])->first();

        return in_array($indicator?->measure, self::levelMeasures(), true);
    }

    /**
     * The measures read as a level: latest approved value, nothing pending.
     *
     * @return list<IndicatorMeasure>
     */
    private static function levelMeasures(): array
    {
        return [IndicatorMeasure::Percentage, IndicatorMeasure::Average];
    }

    /**
     * levelMeasures() as a quoted SQL list.
     *
     * @return literal-string
     */
    private static function levelMeasuresSql(): string
    {
        $percentage = IndicatorMeasure::Percentage->value;
        $average = IndicatorMeasure::Average->value;

        return "'{$percentage}', '{$average}'";
    }

    /**
     * @return literal-string
     */
    private static function measureSql(): string
    {
        return '(select i.measure from indicators i where i.id = plan_indicators.indicator_id)';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'target' => 'decimal:2',
            'period' => Period::class,
        ];
    }

    public function activityModule(): string
    {
        return 'targets';
    }

    /**
     * The targeted indicator's name.
     */
    public function activityLabel(): string
    {
        return (string) Indicator::query()->whereKey($this->activityAttribute('indicator_id'))->value('name');
    }

    public function activityProgramId(): ?int
    {
        return static::programIdFor($this->getKey(), $this->activityAttribute('project_id'), $this->activityAttribute('source_type'), $this->activityAttribute('source_id'));
    }

    /**
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return ['target' => 'target_changed', 'period' => 'updated', 'project_id' => 'updated', 'indicator_id' => 'updated'];
    }

    /**
     * The project owner, remembered for the deleted event.
     *
     * @return array<string, mixed>
     */
    protected function activityPropertiesBeforeDelete(): array
    {
        return ['employee_ids' => array_filter([Project::ownerEmployeeIdFor($this->activityAttribute('project_id'))])];
    }

    /**
     * The program a target rolls up to: its project's, else its annual plan's.
     * Pass the known columns to avoid a query; omit them to look the row up.
     */
    public static function programIdFor(mixed $planIndicatorId, mixed $projectId = null, mixed $sourceType = null, mixed $sourceId = null): ?int
    {
        if ($projectId === null && $sourceType === null) {
            $row = static::query()->select(['project_id', 'source_type', 'source_id'])->whereKey($planIndicatorId)->first();
            [$projectId, $sourceType, $sourceId] = [$row?->project_id, $row?->source_type, $row?->source_id];
        }

        $programId = Project::programIdFor($projectId);

        if ($programId !== null || $sourceType !== 'annual_plan') {
            return $programId;
        }

        $planProgramId = AnnualPlan::query()->whereKey($sourceId)->value('program_id');

        return $planProgramId === null ? null : (int) $planProgramId;
    }
}
