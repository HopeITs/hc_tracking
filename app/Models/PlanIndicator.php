<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsActivity;
use App\Enums\DenominatorBasis;
use App\Enums\IndicatorMeasure;
use App\Enums\Period;
use App\Enums\ReportStatus;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\PlanIndicatorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;

/**
 * A target committed for one indicator, in one plan or proposal.
 *
 * @property int $id
 * @property string $source_type
 * @property int $source_id
 * @property int $indicator_id
 * @property string $target
 * @property string|null $target_population
 * @property int|null $project_id
 * @property Period $period
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['source_type', 'source_id', 'indicator_id', 'target', 'target_population', 'project_id', 'period'])]
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
     * The report template lines reporting this target (read only here; lines
     * are written by the report template actions).
     *
     * @return HasMany<ReportTemplateLine, $this>
     */
    public function reportTemplateLines(): HasMany
    {
        return $this->hasMany(ReportTemplateLine::class);
    }

    /**
     * The dates a figure for this target may cover: its annual plan's year,
     * or its proposal's period (actual dates where they are known, else
     * planned). Null when the source is gone. Reads the `source` relation, so
     * eager-load it for a list. Used by the report form and the monthly
     * report line builder.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function coverableWindow(): ?array
    {
        $source = $this->source;

        return match (true) {
            $source instanceof AnnualPlan => [
                CarbonImmutable::create($source->year)->startOfYear(),
                CarbonImmutable::create($source->year)->endOfYear(),
            ],
            $source instanceof Proposal => [
                $source->actual_date_begin ?? $source->planned_date_begin,
                $source->actual_date_end ?? $source->planned_date_end,
            ],
            default => null,
        };
    }

    /**
     * The targets a project delivers in a period (contracts §12.1): proposal
     * targets whose proposal runs inside the window (actual dates once both
     * are set, else planned), and annual-plan targets whose plan year
     * overlaps it. Read by T17, T18, T19 and T33.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forProjectInWindow(Builder $query, int $projectId, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $query->where('plan_indicators.project_id', $projectId)->where(function (Builder $sources) use ($from, $to): void {
            $sources->where(function (Builder $proposalTargets) use ($from, $to): void {
                $proposalTargets->where('plan_indicators.source_type', 'proposal')->whereExists(function (QueryBuilder $proposal) use ($from, $to): void {
                    $proposal->selectRaw('1')
                        ->from('proposals')
                        ->whereColumn('proposals.id', 'plan_indicators.source_id')
                        ->whereRaw('(case when proposals.actual_date_begin is not null and proposals.actual_date_end is not null then proposals.actual_date_begin else proposals.planned_date_begin end) <= ?', [$to->toDateString()])
                        ->whereRaw('(case when proposals.actual_date_begin is not null and proposals.actual_date_end is not null then proposals.actual_date_end else proposals.planned_date_end end) >= ?', [$from->toDateString()]);
                });
            })->orWhere(function (Builder $planTargets) use ($from, $to): void {
                $planTargets->where('plan_indicators.source_type', 'annual_plan')->whereExists(function (QueryBuilder $plan) use ($from, $to): void {
                    $plan->selectRaw('1')
                        ->from('annual_plans')
                        ->whereColumn('annual_plans.id', 'plan_indicators.source_id')
                        ->whereBetween('annual_plans.year', [$from->year, $to->year]);
                });
            });
        });
    }

    /**
     * How much of this target has been achieved, counting approved reports only.
     *
     * This is the one definition every page uses. A numeric indicator
     * accumulates, so its approved values are summed; a percentage or average
     * indicator is a level, so the latest approved value stands on its own —
     * 40% then 55% is 55%, not 95%. Unapproved figures are not achievement until they clear
     * review; pendingValue() reports them separately. A NULL value (a monthly
     * report line marked Not measured, contracts §13) is no figure: it adds
     * nothing and never becomes the latest level.
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
                ->filter(fn (Report $report): bool => $report->value !== null)
                ->sortBy([['planned_date', 'desc'], ['id', 'desc']])
                ->first();

            return $latest === null ? 0.0 : (float) $latest->value;
        }

        return (float) $approved->sum(fn (Report $report): float => (float) $report->value);
    }

    /**
     * What the renewed proposal's matching target reached last year, shown
     * beside a target as a reference only (T10, D-11).
     *
     * The match is the previous proposal's target on the same project whose
     * indicator is linked to the same library entry. Returns null for a
     * target that is not in a renewal, has no library link, or has no match.
     *
     * @return array{value: ?float, percent: ?float}|null
     */
    public static function previousYearReference(self $target): ?array
    {
        if ($target->source_type !== 'proposal') {
            return null;
        }

        $libraryId = $target->indicator()->value('library_indicator_id');
        $previousId = Proposal::query()->whereKey($target->source_id)->value('previous_proposal_id');

        if ($libraryId === null || $previousId === null || $target->project_id === null) {
            return null;
        }

        $match = self::query()
            ->where('source_type', 'proposal')
            ->where('source_id', $previousId)
            ->where('project_id', $target->project_id)
            ->whereHas('indicator', fn (Builder $query) => $query->where('library_indicator_id', $libraryId))
            ->with(['reports', 'indicator'])
            ->orderBy('id')
            ->first();

        return $match === null ? null : ['value' => $match->achievedValue(), 'percent' => $match->percentAchieved()];
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
        $latest = "coalesce((select r.value from reports r where r.plan_indicator_id = plan_indicators.id and r.status = '{$approved}' and r.value is not null order by r.planned_date desc, r.id desc limit 1), 0)";

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
     * The pooled ratio of a set of targets over a window (contracts §13
     * Pooling, D-25): Σ numerators ÷ Σ denominators, ×100 for a percentage,
     * never an average of percentages. An aggregate over a `plan_indicators`
     * query (one row per target); T18 and T19 pool only through it.
     *
     * Per target, over its approved `reports` rows planned inside the window
     * that carry a denominator:
     * - `fixed` basis: numerator = Σ the monthly counts (the latest when the
     *   library entry is cumulative), denominator = the fixed denominator;
     * - `entered` basis: the latest row's numerator and denominator.
     * A target whose approved rows carry no denominator is left out and
     * counted by pooledExcludedCountSql(). pooledRatio() is the PHP twin.
     *
     * @return Expression<literal-string&non-falsy-string>
     */
    public static function pooledRatioSql(CarbonImmutable $from, CarbonImmutable $to): Expression
    {
        [$numerator, $denominator] = self::pooledTermsSql($from, $to);
        $percentage = IndicatorMeasure::Percentage->value;

        return new Expression("(sum(case when {$denominator} > 0 then {$numerator} end) / nullif(sum(case when {$denominator} > 0 then {$denominator} end), 0) * (case when max(".self::measureSql().") = '{$percentage}' then 100 else 1 end))");
    }

    /**
     * How many targets pooledRatioSql() left out: approved rows in the
     * window, none with a denominator.
     *
     * @return Expression<literal-string&non-falsy-string>
     */
    public static function pooledExcludedCountSql(CarbonImmutable $from, CarbonImmutable $to): Expression
    {
        [, $denominator, $approved] = self::pooledTermsSql($from, $to);

        return new Expression("coalesce(sum(case when {$denominator} is null and exists(select 1 from reports r where {$approved}) then 1 else 0 end), 0)");
    }

    /**
     * pooledRatioSql() and pooledExcludedCountSql() in PHP, over targets with
     * `reports` and `indicator.libraryIndicator` loaded.
     *
     * @param  iterable<self>  $targets
     * @return array{ratio: ?float, excluded: int}
     */
    public static function pooledRatio(iterable $targets, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $numerators = 0.0;
        $denominators = 0.0;
        $excluded = 0;
        $isPercentage = false;

        foreach ($targets as $target) {
            $indicator = $target->getRelation('indicator');
            $library = $indicator?->getRelation('libraryIndicator');
            $isPercentage = $isPercentage || $indicator?->measure === IndicatorMeasure::Percentage;

            $approved = $target->loadedReports()
                ->filter(fn (Report $report): bool => $report->status === ReportStatus::Approved
                    && $report->planned_date->betweenIncluded($from->startOfDay(), $to->endOfDay()));
            $measured = $approved
                ->filter(fn (Report $report): bool => $report->denominator !== null && (float) $report->denominator > 0)
                ->sortBy([['planned_date', 'desc'], ['id', 'desc']]);

            if ($measured->isEmpty()) {
                $excluded += $approved->isEmpty() ? 0 : 1;

                continue;
            }

            $latest = $measured->first();
            $sumsCounts = $library?->denominator_basis === DenominatorBasis::Fixed && ! $library->is_cumulative;

            $numerators += $sumsCounts
                ? (float) $measured->sum(fn (Report $report): float => (float) $report->numerator)
                : (float) $latest->numerator;
            $denominators += (float) $latest->denominator;
        }

        return [
            'ratio' => $denominators > 0.0 ? $numerators / $denominators * ($isPercentage ? 100 : 1) : null,
            'excluded' => $excluded,
        ];
    }

    /**
     * The per-target numerator and denominator of the pooled ratio, and the
     * approved-in-window condition they share, as correlated SQL.
     *
     * The window goes in as Ymd digits only (sqlDigits()).
     *
     * @return array{0: literal-string, 1: literal-string, 2: literal-string}
     */
    private static function pooledTermsSql(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $status = ReportStatus::Approved->value;
        $fixed = DenominatorBasis::Fixed->value;
        $start = self::sqlDigits($from->format('Ymd'));
        $end = self::sqlDigits($to->format('Ymd'));
        $approved = "r.plan_indicator_id = plan_indicators.id and r.status = '{$status}' and r.planned_date between cast({$start} as date) and cast({$end} as date)";
        $measured = "{$approved} and r.denominator > 0";
        $library = 'from indicators i join library_indicators li on li.id = i.library_indicator_id where i.id = plan_indicators.indicator_id';

        $sumsCounts = "coalesce((select li.denominator_basis = '{$fixed}' and li.is_cumulative = 0 {$library}), 0) = 1";
        $sum = "(select sum(r.numerator) from reports r where {$measured})";
        $latestNumerator = "(select r.numerator from reports r where {$measured} order by r.planned_date desc, r.id desc limit 1)";
        $latestDenominator = "(select r.denominator from reports r where {$measured} order by r.planned_date desc, r.id desc limit 1)";

        return ["(case when {$sumsCounts} then {$sum} else {$latestNumerator} end)", $latestDenominator, $approved];
    }

    /**
     * A digit string rebuilt one allowed character at a time, so nothing but
     * 0–9 can reach the SQL; anything else throws.
     *
     * @return literal-string
     */
    private static function sqlDigits(string $digits): string
    {
        $sql = '';

        foreach (str_split($digits) as $digit) {
            $sql .= match ($digit) {
                '0' => '0', '1' => '1', '2' => '2', '3' => '3', '4' => '4',
                '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9',
                default => throw new InvalidArgumentException('Only digits may reach the pooled ratio SQL.'),
            };
        }

        return $sql;
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
     * Five rules, each skipped when the side it compares is unset:
     * - an indicator scoped to one project can only be targeted for that project;
     * - an annual plan commits targets on the program framework only, never on
     *   a proposal framework's indicator (T12);
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
            if ($indicator->relationLoaded('result') && $indicator->result?->proposal_id !== null) {
                $issues[] = 'targets.issue_plan_target_on_proposal_framework';
            }

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
            'target_population' => 'decimal:2',
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
