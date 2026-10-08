<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\AnnualPlans;

use App\Enums\ReportStatus;
use App\Models\AnnualPlan;
use App\Models\PlanIndicator;
use App\Models\Program;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Livewire\Attributes\Computed;

/**
 * The annual-plan matrix's data: the program rows, the plan in each cell,
 * the delivery signal per plan, and the year rail and cockpit figures.
 * Reads the fiscal-year columns FiltersPlanMatrix resolves.
 *
 * Used by annual-plans/index only. The page uses WithPagination and provides
 * the #[Url] filter properties.
 *
 * @property string $manager
 * @property string $programSearch
 * @property-read array<int, int> $yearColumns
 * @property-read int|null $activeYear
 * @property-read array<int, array{plans: int, targets: int, reports: int, approved: int, managers: int}> $yearStats
 * @property-read \Illuminate\Pagination\LengthAwarePaginator<int, Program> $programRows
 */
trait BuildsPlanMatrix
{
    /**
     * The programs forming the rows of the matrix.
     *
     * Without a manager filter every program is listed, including ones with no
     * plan at all: an entirely empty row is how the matrix reports a program
     * nobody has planned. With a manager filter, a program earns its row only
     * by having a plan that manager owns inside the visible fiscal years.
     *
     * @return LengthAwarePaginator<int, Program>
     */
    #[Computed]
    public function programRows(): LengthAwarePaginator
    {
        $columns = $this->yearColumns;
        $managerId = $this->selectedManagerId();

        return Program::query()
            ->select(['id', 'strategy_id'])
            ->with(['strategy:id,name,start_year,end_year'])
            ->when($managerId !== null, fn (Builder $query) => $query->whereHas(
                'annualPlans',
                function (Builder $query) use ($managerId, $columns): void {
                    $query->managedBy($managerId);

                    if ($columns !== []) {
                        $query->inYears($columns);
                    }
                },
            ))
            ->when($this->programSearch !== '', fn (Builder $query) => $query->whereNameLike($this->programSearch))
            ->orderByName()
            ->paginate(15);
    }

    /**
     * The plans of the visible rows, keyed "programId:year".
     *
     * One query fills the whole grid. Every cell is then an array lookup, so
     * the number of queries does not move as the matrix grows.
     *
     * @return array<string, AnnualPlan>
     */
    #[Computed]
    public function planCells(): array
    {
        $columns = $this->yearColumns;
        $programIds = $this->programRows->getCollection()->modelKeys();

        if ($columns === [] || $programIds === []) {
            return [];
        }

        return AnnualPlan::query()
            ->select(['id', 'slug', 'program_id', 'manager_id', 'year'])
            ->whereIn('program_id', $programIds)
            ->inYears($columns)
            ->managedBy($this->selectedManagerId())
            ->with('manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
            ->withCount('planIndicators')
            ->get()
            ->keyBy(fn (AnnualPlan $plan): string => $plan->program_id.':'.$plan->year)
            ->all();
    }

    /**
     * Delivery signal for every plan on screen, keyed by plan id.
     *
     * Read from plan_indicators rather than annual_plans, so filling the cells
     * with progress costs one query for the whole grid and leaves the matrix's
     * four reads of annual_plans untouched. Reports fan out against targets,
     * hence the distinct counts.
     *
     * @return array<int, array{targets: int, attributed: int, reports: int, approved: int}>
     */
    #[Computed]
    public function planDelivery(): array
    {
        $planIds = array_map(fn (AnnualPlan $plan): int => $plan->id, $this->planCells);

        if ($planIds === []) {
            return [];
        }

        return PlanIndicator::query()
            ->where('plan_indicators.source_type', 'annual_plan')
            ->whereIn('plan_indicators.source_id', $planIds)
            ->leftJoin('reports', 'reports.plan_indicator_id', '=', 'plan_indicators.id')
            ->groupBy('plan_indicators.source_id')
            ->selectRaw('plan_indicators.source_id as plan_id')
            ->selectRaw('count(distinct plan_indicators.id) as targets')
            ->selectRaw('count(distinct case when plan_indicators.project_id is not null then plan_indicators.id end) as attributed')
            ->selectRaw('count(distinct reports.id) as reports')
            ->selectRaw('count(distinct case when reports.status = ? then reports.id end) as approved', [ReportStatus::Approved->value])
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->plan_id => [
                'targets' => (int) $row->targets,
                'attributed' => (int) $row->attributed,
                'reports' => (int) $row->reports,
                'approved' => (int) $row->approved,
            ]])
            ->all();
    }

    /**
     * What each visible fiscal year holds across the whole filtered set.
     *
     * Counted in the database rather than from the cells, so the figures cover
     * every page of programs and not just the one on screen. The target and
     * report totals ride along on the same grouped read that already counts the
     * plans, which is why the column footers can carry delivery signal without
     * adding a query.
     *
     * @return array<int, array{plans: int, targets: int, reports: int, approved: int, managers: int}>
     */
    #[Computed]
    public function yearStats(): array
    {
        $columns = $this->yearColumns;

        if ($columns === []) {
            return [];
        }

        return AnnualPlan::query()
            ->leftJoin('plan_indicators', fn (JoinClause $join) => $join
                ->on('plan_indicators.source_id', '=', 'annual_plans.id')
                ->where('plan_indicators.source_type', '=', 'annual_plan'))
            ->leftJoin('reports', 'reports.plan_indicator_id', '=', 'plan_indicators.id')
            ->inYears($columns)
            ->managedBy($this->selectedManagerId())
            ->groupBy('annual_plans.year')
            ->selectRaw('annual_plans.year as year')
            ->selectRaw('count(distinct annual_plans.id) as plans')
            ->selectRaw('count(distinct annual_plans.manager_id) as managers')
            ->selectRaw('count(distinct plan_indicators.id) as targets')
            ->selectRaw('count(distinct reports.id) as reports')
            ->selectRaw('count(distinct case when reports.status = ? then reports.id end) as approved', [ReportStatus::Approved->value])
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->year => [
                'plans' => (int) $row->plans,
                'targets' => (int) $row->targets,
                'reports' => (int) $row->reports,
                'approved' => (int) $row->approved,
                'managers' => (int) $row->managers,
            ]])
            ->all();
    }

    /**
     * The figures the year rail draws, one entry per visible column.
     *
     * Coverage is plans over the programs in scope, so a bar reads as "how much
     * of the portfolio was planned that year". Heights are relative to the
     * busiest column rather than absolute, because the comparison the manager
     * makes here is between years, not against a ceiling.
     *
     * @return array<int, array{year: int, plans: int, targets: int, reports: int, approved: int, managers: int, coverage: int, planShare: int, targetShare: int, isCurrent: bool, isActive: bool}>
     */
    #[Computed]
    public function yearRail(): array
    {
        $stats = $this->yearStats;
        $programTotal = $this->programRows->total();
        $peakPlans = max(1, (int) collect($stats)->max('plans'));
        $peakTargets = max(1, (int) collect($stats)->max('targets'));
        $thisYear = (int) now()->year;

        return collect($this->yearColumns)
            ->map(function (int $year) use ($stats, $programTotal, $peakPlans, $peakTargets, $thisYear): array {
                $entry = $stats[$year] ?? ['plans' => 0, 'targets' => 0, 'reports' => 0, 'approved' => 0, 'managers' => 0];

                return [
                    'year' => $year,
                    ...$entry,
                    'coverage' => $programTotal > 0 ? (int) round($entry['plans'] / $programTotal * 100) : 0,
                    'planShare' => (int) round($entry['plans'] / $peakPlans * 100),
                    'targetShare' => (int) round($entry['targets'] / $peakTargets * 100),
                    'isCurrent' => $year === $thisYear,
                    'isActive' => $year === $this->activeYear,
                ];
            })
            ->all();
    }

    /**
     * The focused year's headline figures.
     *
     * @return array{year: int, plans: int, targets: int, reports: int, approved: int, managers: int, coverage: int, gaps: int, reportShare: int, programs: int, targetsDelta: int|null, plansDelta: int|null}|null
     */
    #[Computed]
    public function cockpit(): ?array
    {
        $year = $this->activeYear;

        if ($year === null) {
            return null;
        }

        $stats = $this->yearStats;
        $entry = $stats[$year] ?? ['plans' => 0, 'targets' => 0, 'reports' => 0, 'approved' => 0, 'managers' => 0];
        $previous = $stats[$year - 1] ?? null;
        $programTotal = $this->programRows->total();

        return [
            'year' => $year,
            ...$entry,
            'programs' => $programTotal,
            'coverage' => $programTotal > 0 ? (int) round($entry['plans'] / $programTotal * 100) : 0,
            'gaps' => max(0, $programTotal - $entry['plans']),
            'reportShare' => $entry['reports'] > 0 ? (int) round($entry['approved'] / $entry['reports'] * 100) : 0,
            'plansDelta' => $previous === null ? null : $entry['plans'] - $previous['plans'],
            'targetsDelta' => $previous === null ? null : $entry['targets'] - $previous['targets'],
        ];
    }

    /**
     * The plan of one program in one fiscal year, or null where none exists.
     */
    protected function planFor(int $programId, int $year): ?AnnualPlan
    {
        return $this->planCells[$programId.':'.$year] ?? null;
    }

    /**
     * The manager the grid is filtered to, or null for all of them.
     */
    private function selectedManagerId(): ?int
    {
        return $this->manager === '' ? null : (int) $this->manager;
    }
}
