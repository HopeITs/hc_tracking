<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\AnnualPlans;

use App\Enums\Period;
use App\Enums\ReportStatus;
use App\Models\AnnualPlan;
use App\Models\HrCenter;
use App\Models\HrCity;
use App\Models\PlanIndicator;
use App\Models\Report;
use App\Models\Result;
use App\Support\ResultsChain;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;

/**
 * The annual plan detail page's readings: the targets committed in the plan,
 * the report status counts, the headline summary, the result groups, the
 * period mix, the places the targets are for and the recent reports.
 *
 * Used by annual-plans/show only. The page provides the locked
 * $annualPlanId; every reading after targets() is built from the memoised
 * targets() and reportStatusCounts().
 *
 * @property int $annualPlanId
 * @property-read Collection<int, PlanIndicator> $targets  #[Computed] targets()
 * @property-read array<value-of<ReportStatus>, int> $reportStatusCounts  #[Computed] reportStatusCounts()
 * @property-read array<string, mixed> $chain  #[Computed] chain()
 *
 * @method array{indicator: string, center: string, donor: string} chainFilters() FiltersResultsChain
 */
trait ReadsAnnualPlanSummary
{
    /**
     * The targets committed in this plan, with everything the page reads to
     * draw the logframe connection (result → indicator → place) and the
     * reporting signal on each one. A target's place is its Y-08 columns
     * (city, program, centre or field work), not its project (Y-15).
     *
     * Filtered on the morph map key 'annual_plan', never a class name: the
     * column is VARCHAR(50) and the map is enforced application-wide.
     *
     * @return Collection<int, PlanIndicator>
     */
    #[Computed]
    public function targets(): Collection
    {
        return PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'city_id', 'program_id', 'center_id', 'city_wide', 'all_centers', 'period'])
            ->where('source_type', 'annual_plan')
            ->where('source_id', $this->annualPlanId)
            ->with([
                'indicator:id,name,result_id,measure',
                'indicator.result:id,statement,type',
            ])
            ->withCount('reports')
            ->get();
    }

    /**
     * Reports submitted against this plan's targets, grouped by review status.
     *
     * One extra query beyond the four-reads guarantee that binds the coverage
     * matrix — that guarantee is scoped to the index page's grid, not this
     * single-record detail view.
     *
     * @return array<value-of<ReportStatus>, int>
     */
    #[Computed]
    public function reportStatusCounts(): array
    {
        $targetIds = $this->targets->modelKeys();

        if ($targetIds === []) {
            return [];
        }

        return Report::query()
            ->whereIn('plan_indicator_id', $targetIds)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }

    /**
     * The headline figures for the plan pulse board.
     *
     * @return array{
     *     targets: int,
     *     projects: int,
     *     orgWide: int,
     *     results: int,
     *     reported: int,
     *     unreported: int,
     *     reportingShare: int,
     *     reportsTotal: int,
     *     reportsApproved: int,
     *     reportsReviewed: int,
     *     reportsDraft: int,
     *     approvedShare: int,
     * }
     */
    #[Computed]
    public function summary(): array
    {
        $targets = $this->targets;
        $statusCounts = $this->reportStatusCounts;

        $reported = $targets->filter(fn (PlanIndicator $target): bool => $target->reports_count > 0)->count();
        $reportsTotal = array_sum($statusCounts);
        $approved = $statusCounts[ReportStatus::Approved->value] ?? 0;

        return [
            'targets' => $targets->count(),
            'projects' => $this->linkedPlaces->count(),
            'orgWide' => $targets->whereNull('city_id')->count(),
            'results' => $targets->pluck('indicator.result_id')->filter()->unique()->count(),
            'reported' => $reported,
            'unreported' => $targets->count() - $reported,
            'reportingShare' => $targets->isEmpty() ? 0 : (int) round($reported / $targets->count() * 100),
            'reportsTotal' => $reportsTotal,
            'reportsApproved' => $approved,
            'reportsReviewed' => $statusCounts[ReportStatus::Reviewed->value] ?? 0,
            'reportsDraft' => $statusCounts[ReportStatus::Draft->value] ?? 0,
            'approvedShare' => $reportsTotal > 0 ? (int) round($approved / $reportsTotal * 100) : 0,
        ];
    }

    /**
     * Targets grouped under the logframe result they measure, ordered by
     * result type (impact, then outcome, then output) so the plan reads top
     * down the same way the logframe does.
     *
     * Grouped from the already-loaded targets collection, so this costs no
     * additional query.
     *
     * @return SupportCollection<int, array{result: Result, targets: Collection<int, PlanIndicator>}>
     */
    #[Computed]
    public function resultGroups(): SupportCollection
    {
        return $this->targets
            ->groupBy('indicator.result_id')
            ->map(fn (Collection $targets): array => [
                'result' => $targets->first()->indicator->result,
                'targets' => $targets,
            ])
            ->sortBy(fn (array $group): int => $group['result']->type->rank())
            ->values();
    }

    /**
     * The read-only results chain of the plan (T16): the program framework
     * measured by this plan's targets only.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function chain(): array
    {
        $plan = AnnualPlan::query()->select(['id', 'program_id', 'year'])->findOrFail($this->annualPlanId);

        return ResultsChain::forAnnualPlan($plan, $this->chainFilters());
    }

    /**
     * How many committed targets fall in each reporting period, for the
     * period-mix bar.
     *
     * @return array<int, array{period: Period, count: int, share: int}>
     */
    #[Computed]
    public function periodMix(): array
    {
        $targets = $this->targets;
        $total = max(1, $targets->count());

        return collect(Period::cases())
            ->map(fn (Period $period): array => [
                'period' => $period,
                'count' => $count = $targets->where('period', $period)->count(),
                'share' => (int) round($count / $total * 100),
            ])
            ->filter(fn (array $row): bool => $row['count'] > 0)
            ->values()
            ->all();
    }

    /**
     * The distinct places this plan's targets are for, for the connections
     * panel (Y-15: a target's place, not its project). Org-wide targets (no
     * place) are excluded: they are already their own figure in the plan
     * pulse. A place is named by its centre, else "Field work" or "Center not
     * set", with its city. Cities come from HR in one query; centres from the
     * remembered HR lookup.
     *
     * @return SupportCollection<int, array{key: string, name: string, city: string}>
     */
    #[Computed]
    public function linkedPlaces(): SupportCollection
    {
        $placed = $this->targets->filter(fn (PlanIndicator $target): bool => $target->city_id !== null);
        $cities = $placed->isEmpty() ? collect() : HrCity::query()
            ->whereKey($placed->pluck('city_id')->unique()->values()->all())
            ->get(['id', 'name_en', 'name_ar'])
            ->keyBy('id');

        return $placed
            ->map(fn (PlanIndicator $target): array => [
                'key' => implode(':', [$target->city_id, $target->program_id, $target->center_id ?? ($target->city_wide ? 'field' : ($target->all_centers ? 'all' : 'unset'))]),
                'name' => match (true) {
                    $target->center_id !== null => (string) (HrCenter::remembered((int) $target->center_id)->name ?? __('monthly_reports.place_center_not_set')),
                    $target->city_wide => (string) __('monthly_reports.place_field_work'),
                    default => (string) __('monthly_reports.place_center_not_set'),
                },
                'city' => (string) ($cities->get($target->city_id)->name ?? __('common.unknown_city')),
            ])
            ->unique('key')
            ->sortBy(fn (array $place): string => $place['city'].' '.$place['name'], SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * The most recent activity reported against this plan, newest first.
     *
     * @return Collection<int, Report>
     */
    #[Computed]
    public function recentReports(): Collection
    {
        $targetIds = $this->targets->modelKeys();

        if ($targetIds === []) {
            return new Collection;
        }

        return Report::query()
            ->select(['id', 'plan_indicator_id', 'status', 'value', 'period_start', 'period_end', 'submit_date', 'created_at'])
            ->whereIn('plan_indicator_id', $targetIds)
            ->with(['planIndicator:id,indicator_id,project_id', 'planIndicator.indicator:id,name,measure', 'planIndicator.project:id,name'])
            ->latest('created_at')
            ->limit(8)
            ->get();
    }
}
