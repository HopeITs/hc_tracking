<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Programs;

use App\Enums\MilestoneStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Models\AnnualPlan;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Report;
use App\Support\ResultsChain;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The read-only figures on programs/show: annual plans and their year
 * slots, the logframe roots and summary, and milestone progress.
 *
 * Used by programs/show only. The page provides the locked $programId and the
 * program() computed (planYears() reads $this->program->strategy).
 *
 * @property int $programId
 * @property-read Program $program  #[Computed] program()
 * @property-read Collection<int, AnnualPlan> $annualPlans  #[Computed] annualPlans()
 * @property-read array<string, mixed> $chain  #[Computed] chain()
 *
 * @method array{indicator: string, center: string, donor: string} chainFilters() FiltersResultsChain
 */
trait ReadsProgramDelivery
{
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
    public function planYears(): array
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
     * The program's read-only results chain (T16): the program framework, the
     * proposal frameworks contributing to it, indicators, per-center targets,
     * achievement, gaps and the chain filters. A fixed number of queries.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function chain(): array
    {
        return ResultsChain::forProgram($this->programId, $this->chainFilters());
    }

    /**
     * Statement and indicator totals across the program's own logframe.
     *
     * @return array{levels: array<string, int>, indicators: int, unmeasured: int}
     */
    #[Computed]
    public function resultSummary(): array
    {
        return $this->chain['summary'];
    }

    /**
     * The program's weighted milestone progress, and how many of its projects
     * it is drawn from, so a figure built on one project never reads as the
     * whole program's.
     *
     * @return array{percentage: float|null, reached: float, total: float, measuredProjects: int, projects: int}
     */
    #[Computed]
    public function milestoneProgress(): array
    {
        $weights = Milestone::query()
            ->whereIn('project_id', Project::query()->select('id')->where('program_id', $this->programId))
            ->where('status', '!=', MilestoneStatus::Cancelled->value)
            ->selectRaw(
                'coalesce(sum(case when status = ? then weight_percentage else 0 end), 0) as reached_weight, coalesce(sum(weight_percentage), 0) as total_weight, count(distinct case when weight_percentage > 0 then project_id end) as measured_projects',
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
            'measuredProjects' => (int) ($weights->measured_projects ?? 0),
            'projects' => Project::query()->where('program_id', $this->programId)->count(),
        ];
    }
}
