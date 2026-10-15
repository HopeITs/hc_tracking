<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Programs;

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Models\AnnualPlan;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Report;
use App\Models\Result;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The read-only figures on programs/show: the delivery window, annual plans and
 * their year timeline, the logframe roots and summary, milestone progress and
 * the project status segments.
 *
 * Used by programs/show only. The page provides the locked $programId and the
 * program() computed (planTimeline() reads $this->program->strategy).
 *
 * @property int $programId
 * @property-read Program $program  #[Computed] program()
 * @property-read Collection<int, AnnualPlan> $annualPlans  #[Computed] annualPlans()
 * @property-read Collection<int, Result> $resultRoots  #[Computed] resultRoots()
 */
trait ReadsProgramDelivery
{
    /**
     * The date range every project bar is plotted against: the program's
     * project dates, today, and each project's milestone dates.
     *
     * A milestone only stretches the range when it falls within a year of its
     * own project's dates, so one mistyped date cannot squash every bar. Such
     * milestones are still drawn, pinned to the edge and flagged as outside
     * the delivery window.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    #[Computed]
    public function deliveryWindow(): ?array
    {
        $projects = Project::query()
            ->select(['id', 'start_date', 'end_date'])
            ->where('program_id', $this->programId)
            ->get()
            ->keyBy('id');

        $milestoneBounds = Milestone::query()
            ->whereIn('project_id', $projects->keys())
            ->where('status', '!=', MilestoneStatus::Cancelled->value)
            ->groupBy('project_id')
            ->selectRaw('project_id, min(planned_start_date) as first_planned, max(planned_start_date) as last_planned')
            ->toBase()
            ->get();

        $dates = $projects
            ->flatMap(fn (Project $project): array => [$project->start_date, $project->end_date])
            ->filter()
            ->map(fn ($date): CarbonImmutable => CarbonImmutable::parse($date));

        foreach ($milestoneBounds as $bounds) {
            $project = $projects->get($bounds->project_id);
            $earliest = $project?->start_date ?? $project?->end_date;
            $latest = $project?->end_date ?? $project?->start_date;

            foreach ([$bounds->first_planned, $bounds->last_planned] as $planned) {
                $plannedDate = CarbonImmutable::parse($planned);

                if ($earliest === null || $plannedDate->betweenIncluded(CarbonImmutable::parse($earliest)->subYear(), CarbonImmutable::parse($latest)->addYear())) {
                    $dates->push($plannedDate);
                }
            }
        }

        if ($dates->isEmpty()) {
            return null;
        }

        $dates->push(CarbonImmutable::today());

        return [
            'start' => $dates->min()->startOfMonth(),
            'end' => $dates->max()->endOfMonth(),
        ];
    }

    /** @return Collection<int, AnnualPlan> */
    #[Computed]
    public function annualPlans(): Collection
    {
        return AnnualPlan::query()
            ->select(['id', 'program_id', 'manager_id', 'year'])
            ->where('program_id', $this->programId)
            ->with(['manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar'])
            ->withCount('planIndicators')
            ->orderByDesc('year')
            ->get();
    }

    /**
     * One slot per fiscal year across the strategy window and every planned
     * year, each carrying its plan (or null for a gap) and the plan's target
     * and reporting totals.
     *
     * Totals come from two grouped reads over all of the program's plans, so
     * the slot count never adds queries.
     *
     * @return array<int, array{year: int, plan: AnnualPlan|null, targets: int, attributed: int, levels: array<string, int>, reports: int, approved: int}>
     */
    #[Computed]
    public function planTimeline(): array
    {
        $plans = $this->annualPlans->keyBy('year');
        $planIds = $this->annualPlans->pluck('id');

        $targetTotals = PlanIndicator::query()
            ->where('plan_indicators.source_type', 'annual_plan')
            ->whereIn('plan_indicators.source_id', $planIds)
            ->leftJoin('indicators', 'indicators.id', '=', 'plan_indicators.indicator_id')
            ->leftJoin('results', 'results.id', '=', 'indicators.result_id')
            ->groupBy('plan_indicators.source_id')
            ->selectRaw('plan_indicators.source_id as plan_id, count(*) as targets')
            ->selectRaw('sum(case when plan_indicators.project_id is not null then 1 else 0 end) as attributed')
            ->selectRaw('sum(case when results.type = ? then 1 else 0 end) as impact', [ResultType::Impact->value])
            ->selectRaw('sum(case when results.type = ? then 1 else 0 end) as outcome', [ResultType::Outcome->value])
            ->selectRaw('sum(case when results.type = ? then 1 else 0 end) as output', [ResultType::Output->value])
            ->selectRaw('sum(case when results.type = ? then 1 else 0 end) as activity', [ResultType::Activity->value])
            ->selectRaw('sum(case when results.type = ? then 1 else 0 end) as cross_cutting', [ResultType::CrossCutting->value])
            ->toBase()
            ->get()
            ->keyBy('plan_id');

        $reportTotals = Report::query()
            ->join('plan_indicators', 'plan_indicators.id', '=', 'reports.plan_indicator_id')
            ->where('plan_indicators.source_type', 'annual_plan')
            ->whereIn('plan_indicators.source_id', $planIds)
            ->groupBy('plan_indicators.source_id')
            ->selectRaw('plan_indicators.source_id as plan_id, count(*) as reports')
            ->selectRaw('sum(case when reports.status = ? then 1 else 0 end) as approved', [ReportStatus::Approved->value])
            ->toBase()
            ->get()
            ->keyBy('plan_id');

        $years = $plans->keys()->all();
        $strategy = $this->program->strategy;

        if ($strategy !== null && $strategy->start_year !== null && $strategy->end_year !== null && $strategy->end_year - $strategy->start_year < 15) {
            $years = [...$years, ...range((int) $strategy->start_year, (int) $strategy->end_year)];
        }

        if ($years === []) {
            return [];
        }

        return collect(range(min($years), max($years)))
            ->map(function (int $year) use ($plans, $targetTotals, $reportTotals): array {
                $plan = $plans->get($year);
                $targets = $plan === null ? null : $targetTotals->get($plan->id);
                $reports = $plan === null ? null : $reportTotals->get($plan->id);

                return [
                    'year' => $year,
                    'plan' => $plan,
                    'targets' => (int) ($targets->targets ?? 0),
                    'attributed' => (int) ($targets->attributed ?? 0),
                    'levels' => [
                        ResultType::Impact->value => (int) ($targets->impact ?? 0),
                        ResultType::Outcome->value => (int) ($targets->outcome ?? 0),
                        ResultType::Output->value => (int) ($targets->output ?? 0),
                        ResultType::Activity->value => (int) ($targets->activity ?? 0),
                        ResultType::CrossCutting->value => (int) ($targets->cross_cutting ?? 0),
                    ],
                    'reports' => (int) ($reports->reports ?? 0),
                    'approved' => (int) ($reports->approved ?? 0),
                ];
            })
            ->all();
    }

    /**
     * The program's logframe as root results with their subtrees attached.
     *
     * Loads every statement in one query with its indicator count, then wires
     * childrenRecursive in memory so every level carries the count.
     *
     * @return Collection<int, Result>
     */
    #[Computed]
    public function resultRoots(): Collection
    {
        $results = Result::query()
            ->select(['id', 'statement', 'parent_id', 'program_id', 'type', 'checkout_period'])
            ->where('program_id', $this->programId)
            ->withCount('indicators')
            ->orderBy('id')
            ->get();

        $childrenByParent = $results->groupBy('parent_id');

        foreach ($results as $result) {
            $result->setRelation('childrenRecursive', new Collection($childrenByParent->get($result->id, [])));
        }

        return $results
            ->filter(fn (Result $result): bool => $result->parent_id === null || ! $results->contains('id', $result->parent_id))
            ->values();
    }

    /**
     * Statement and indicator totals across the program's logframe.
     *
     * @return array{levels: array<string, int>, indicators: int, unmeasured: int}
     */
    #[Computed]
    public function resultSummary(): array
    {
        $all = $this->resultRoots->flatMap(fn (Result $root) => collect([$root])->merge($root->descendants()));

        return [
            'levels' => collect(ResultType::cases())
                ->mapWithKeys(fn (ResultType $type): array => [$type->value => $all->where('type', $type)->count()])
                ->all(),
            'indicators' => (int) $all->sum('indicators_count'),
            'unmeasured' => $all->where('indicators_count', 0)->count(),
        ];
    }

    /** @return array{percentage: float|null, reached: float, total: float} */
    #[Computed]
    public function milestoneProgress(): array
    {
        $weights = Milestone::query()
            ->whereIn('project_id', Project::query()->select('id')->where('program_id', $this->programId))
            ->where('status', '!=', MilestoneStatus::Cancelled->value)
            ->selectRaw(
                'coalesce(sum(case when status = ? then weight_percentage else 0 end), 0) as reached_weight, coalesce(sum(weight_percentage), 0) as total_weight',
                [MilestoneStatus::Reached->value],
            )
            ->toBase()
            ->first();

        $reached = (float) ($weights->reached_weight ?? 0);
        $total = (float) ($weights->total_weight ?? 0);

        return [
            'percentage' => $total > 0 ? round(($reached / $total) * 100, 1) : null,
            'reached' => $reached,
            'total' => $total,
        ];
    }

    /** @return array<int, array{label: string, count: int, tone: string}> */
    #[Computed]
    public function projectStatusSegments(): array
    {
        $counts = Project::query()
            ->where('program_id', $this->programId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(ProjectStatus::live())
            ->map(fn (ProjectStatus $status): array => [
                'label' => $status->label(),
                'count' => (int) ($counts[$status->value] ?? 0),
                'tone' => match ($status) {
                    ProjectStatus::Active => 'bg-status-good',
                    ProjectStatus::Approved => 'bg-status-warn',
                    default => 'bg-secondary-light',
                },
            ])
            ->all();
    }
}
