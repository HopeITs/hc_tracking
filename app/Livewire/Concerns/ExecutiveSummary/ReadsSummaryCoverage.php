<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\ExecutiveSummary;

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\MilestoneStatus;
use App\Enums\ReportStatus;
use App\Models\AnnualPlan;
use App\Models\HrCity;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Report;
use App\Models\TransfersTracking;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * The measures one fiscal year is judged by: the per-year figures every pair
 * on the page is built from, the coverage rail, the stated reach, and the
 * floor-and-cap share rules (percentageOf(), shareOfAmount()).
 *
 * Used by executive-summary/index only. The page's #[Computed] figures() and
 * priorFigures() call figuresFor(); its fiscalYear and figures are read here.
 * coverageMetrics() calls committedThisYear(), targetFigures() calls
 * committedTargets() and plansOf(), and reachFigures() calls
 * projectsRunningIn(), all from LoadsSummarySources. percentageOf() is called
 * by ReadsSummaryLedger, LoadsSummarySources and the coverage partial
 * (_index-coverage); shareOfAmount() by ReadsSummaryLedger and
 * LoadsSummarySources.
 *
 * @property-read int $fiscalYear
 * @property-read array<string, mixed> $figures
 */
trait ReadsSummaryCoverage
{
    /**
     * The four coverage measures, keeping the math they have always had.
     *
     * They close the spread as one rail rather than four cards. percentageOf()
     * still floors a partial share and caps it at 99; the year-scoped measures
     * still read source_type 'annual_plan'; geographic reach is still
     * portfolio-wide and still says so.
     *
     * @return array<int, array{key: string, label: string, covered: int, total: int, unit: string, scope: string, description: string}>
     */
    #[Computed]
    public function coverageMetrics(): array
    {
        $year = $this->fiscalYear;
        $fiscalScope = __('common.fy_year', ['year' => $year]);

        return [
            [
                'key' => 'annual-plan-coverage',
                'label' => __('executive_summary.annual_plan_coverage'),
                // annual_plans is unique on (program_id, year), so a count of
                // this year's plans is exactly a count of programs planned.
                'covered' => AnnualPlan::query()->where('year', $year)->count(),
                'total' => Program::query()->count(),
                'unit' => __('executive_summary.programs'),
                'scope' => $fiscalScope,
                'description' => __('executive_summary.programs_holding_an_annual_plan_for_this_fiscal_year'),
            ],
            [
                'key' => 'indicators-targeted',
                'label' => __('executive_summary.indicators_targeted'),
                'covered' => Indicator::query()->whereHas('planIndicators', $this->committedThisYear($year))->count(),
                'total' => Indicator::query()->count(),
                'unit' => __('executive_summary.indicators'),
                'scope' => $fiscalScope,
                'description' => __('executive_summary.indicators_carrying_a_target_committed_in_an_annual_plan'),
            ],
            [
                'key' => 'projects-targeted',
                'label' => __('executive_summary.projects_carrying_a_target'),
                'covered' => Project::query()->whereHas('planIndicators', $this->committedThisYear($year))->count(),
                'total' => Project::query()->count(),
                'unit' => __('executive_summary.projects'),
                'scope' => $fiscalScope,
                'description' => __('executive_summary.projects_named_by_a_target_in_this_year_s_annual_plans'),
            ],
            [
                'key' => 'geographic-reach',
                'label' => __('executive_summary.geographic_reach'),
                // Cities live in the HR database, so the covered count cannot
                // be a whereHas() across connections.
                'covered' => HrCity::query()->whereIn('id', Project::query()->distinct()->pluck('city_id'))->count(),
                'total' => HrCity::query()->count(),
                'unit' => __('executive_summary.cities'),
                // Projects carry no year, so this one measures the portfolio
                // rather than the fiscal year, and says so on the rail.
                'scope' => __('executive_summary.portfolio'),
                'description' => __('executive_summary.cities_reached_by_at_least_one_project'),
            ],
        ];
    }

    /**
     * The year's stated reach: the people it plans to serve, and where.
     *
     * This is a commitment with no delivered counterpart in the schema —
     * nothing records beneficiaries actually reached — so it is stated as the
     * year's ambition rather than faked into a pair.
     *
     * @return array{projects: int, beneficiaries: int, groups: array<int, array{key: string, label: string, count: int, share: int}>, cities: Collection<int, HrCity>, cityCount: int}
     */
    #[Computed]
    public function reach(): array
    {
        $reach = $this->figures['reach'];
        $total = $reach['beneficiaries'];

        $groups = collect([
            ['key' => 'women', 'label' => __('executive_summary.women'), 'count' => $reach['women']],
            ['key' => 'men', 'label' => __('executive_summary.men'), 'count' => $reach['men']],
            ['key' => 'girls', 'label' => __('executive_summary.girls'), 'count' => $reach['girls']],
            ['key' => 'boys', 'label' => __('executive_summary.boys'), 'count' => $reach['boys']],
        ])
            ->map(fn (array $group): array => [
                ...$group,
                'share' => $total > 0 ? (int) round($group['count'] / $total * 100) : 0,
            ])
            ->all();

        return [
            'projects' => $reach['projects'],
            'beneficiaries' => $total,
            'groups' => $groups,
            'cities' => $reach['cityIds'] === []
                ? collect()
                : HrCity::query()->whereIn('id', $reach['cityIds'])->orderBy(HrCity::nameColumn())->get(),
            'cityCount' => count($reach['cityIds']),
        ];
    }

    /**
     * The share of a total that is covered, or null when there is no total.
     *
     * A partial figure never rounds up to 100: a single unplanned program out of
     * a thousand must not report full coverage.
     */
    protected function percentageOf(int $covered, int $total): ?int
    {
        if ($total <= 0) {
            return null;
        }

        if ($covered >= $total) {
            return 100;
        }

        return min(99, (int) floor($covered / $total * 100));
    }

    /**
     * percentageOf() for money, which is decimal rather than a count.
     */
    protected function shareOfAmount(float $landed, float $scheduled): ?int
    {
        if ($scheduled <= 0.0) {
            return null;
        }

        if ($landed >= $scheduled) {
            return 100;
        }

        return min(99, (int) floor($landed / $scheduled * 100));
    }

    /**
     * Every measure this page needs for one fiscal year.
     *
     * @return array<string, mixed>
     */
    private function figuresFor(int $year): array
    {
        return [
            'plans' => $this->planFigures($year),
            'targets' => $this->targetFigures($year),
            'money' => $this->moneyFigures($year),
            'milestones' => $this->milestoneFigures($year),
            'reports' => $this->reportFigures($year),
            'reach' => $this->reachFigures($year),
        ];
    }

    /**
     * Programs holding a plan for the year, against every program there is.
     *
     * @return array{written: int, programs: int}
     */
    private function planFigures(int $year): array
    {
        return [
            'written' => AnnualPlan::query()->where('year', $year)->count(),
            'programs' => Program::query()->count(),
        ];
    }

    /**
     * Targets committed in the year's plans, and how far each one got.
     *
     * Attainment is a count distribution, never a summed figure: a percentage
     * indicator is a level and a numeric one accumulates, so adding their
     * targets together would produce a number that means nothing.
     *
     * @return array{committed: int, achieved: int, inProgress: int, notStarted: int, programs: int, indicators: int}
     */
    private function targetFigures(int $year): array
    {
        $targets = $this->committedTargets($year);
        $byStatus = $targets->countBy(fn (PlanIndicator $target): string => $target->achievementStatus());

        return [
            'committed' => $targets->count(),
            'achieved' => (int) $byStatus->get('achieved', 0),
            'inProgress' => (int) $byStatus->get('in_progress', 0),
            'notStarted' => (int) $byStatus->get('not_started', 0),
            'programs' => $this->plansOf($year)->pluck('program_id')->unique()->count(),
            'indicators' => $targets->pluck('indicator_id')->unique()->count(),
        ];
    }

    /**
     * What each currency was scheduled to deliver this year, and what landed.
     *
     * A tranche is scheduled into the year it was due and landed into the year
     * it was received, so a payment promised in December and banked in January
     * is honestly a gap in one year and an arrival in the next. A received
     * tranche whose net amount nobody recorded counts as nothing landed, which
     * is the rule TransfersTracking::isMissingNetAmount() already states.
     *
     * @return array<string, array{code: string, scheduled: float, landed: float, scheduledTranches: int, landedTranches: int}>
     */
    private function moneyFigures(int $year): array
    {
        $scheduled = TransfersTracking::query()
            ->selectRaw('amount_transfered_currency as code, sum(payment_amount) as amount, count(*) as tranches')
            ->whereNotNull('amount_transfered_currency')
            ->whereYear('scheduled_date', $year)
            ->groupBy('amount_transfered_currency')
            ->get()
            ->keyBy('code');

        $landed = TransfersTracking::query()
            ->selectRaw('amount_received_currency as code, sum(net_amount_received) as amount, count(*) as tranches')
            ->where('ar_status', ArStatus::Received->value)
            ->whereNotNull('amount_received_currency')
            ->whereNotNull('net_amount_received')
            ->whereYear('receive_date', $year)
            ->groupBy('amount_received_currency')
            ->get()
            ->keyBy('code');

        $lines = [];

        foreach (Currency::cases() as $currency) {
            $code = $currency->value;
            $scheduledLine = $scheduled->get($code);
            $landedLine = $landed->get($code);

            if ($scheduledLine === null && $landedLine === null) {
                continue;
            }

            $lines[$code] = [
                'code' => $code,
                'scheduled' => (float) ($scheduledLine->amount ?? 0),
                'landed' => (float) ($landedLine->amount ?? 0),
                'scheduledTranches' => (int) ($scheduledLine->tranches ?? 0),
                'landedTranches' => (int) ($landedLine->tranches ?? 0),
            ];
        }

        return $lines;
    }

    /**
     * Milestones the year was meant to land, and the ones it did.
     *
     * Dated on the planned end date, falling back to the planned start date:
     * the end columns were added late and are not backfilled, so scoping on
     * them alone would report every fiscal year as having promised nothing.
     * Cancelled milestones are not a broken promise and leave the denominator.
     *
     * @return array{due: int, reached: int, ongoing: int, postponed: int, cancelled: int}
     */
    private function milestoneFigures(int $year): array
    {
        $byStatus = Milestone::query()
            ->selectRaw('status, count(*) as total')
            ->whereRaw('year(coalesce(planned_end_date, planned_start_date)) = ?', [$year])
            ->groupBy('status')
            ->pluck('total', 'status');

        $count = fn (MilestoneStatus $status): int => (int) $byStatus->get($status->value, 0);
        $cancelled = $count(MilestoneStatus::Cancelled);

        return [
            'due' => (int) $byStatus->sum() - $cancelled,
            'reached' => $count(MilestoneStatus::Reached),
            'ongoing' => $count(MilestoneStatus::Ongoing),
            'postponed' => $count(MilestoneStatus::Postponed),
            'cancelled' => $cancelled,
        ];
    }

    /**
     * Reports the year planned for, and how many cleared review.
     *
     * A report is delivered when it is approved. Reviewed is the partial band
     * and draft has not been submitted at all.
     *
     * @return array{owed: int, approved: int, reviewed: int, draft: int}
     */
    private function reportFigures(int $year): array
    {
        $byStatus = Report::query()
            ->selectRaw('status, count(*) as total')
            ->whereYear('planned_date', $year)
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'owed' => (int) $byStatus->sum(),
            'approved' => (int) $byStatus->get(ReportStatus::Approved->value, 0),
            'reviewed' => (int) $byStatus->get(ReportStatus::Reviewed->value, 0),
            'draft' => (int) $byStatus->get(ReportStatus::Draft->value, 0),
        ];
    }

    /**
     * The people and places the year's running projects set out to serve.
     *
     * A project counts when its period overlaps the fiscal year at all; an open
     * end of that period counts as still running rather than excluding the
     * project, so a record missing a date is never silently dropped.
     *
     * @return array{projects: int, beneficiaries: int, men: int, women: int, boys: int, girls: int, cityIds: array<int, int>}
     */
    private function reachFigures(int $year): array
    {
        $totals = $this->projectsRunningIn($year)
            ->selectRaw('count(*) as projects, coalesce(sum(target_beneficiaries), 0) as beneficiaries, coalesce(sum(target_men), 0) as men, coalesce(sum(target_women), 0) as women, coalesce(sum(target_boys), 0) as boys, coalesce(sum(target_girls), 0) as girls')
            ->first();

        return [
            'projects' => (int) ($totals->projects ?? 0),
            'beneficiaries' => (int) ($totals->beneficiaries ?? 0),
            'men' => (int) ($totals->men ?? 0),
            'women' => (int) ($totals->women ?? 0),
            'boys' => (int) ($totals->boys ?? 0),
            'girls' => (int) ($totals->girls ?? 0),
            'cityIds' => $this->projectsRunningIn($year)
                ->whereNotNull('city_id')
                ->distinct()
                ->pluck('city_id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
        ];
    }
}
