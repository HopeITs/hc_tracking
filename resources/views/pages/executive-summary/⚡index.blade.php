<?php

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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.executive_summary')] class extends Component {
    /**
     * The fiscal year picked in the header, or '' while the picker is untouched.
     *
     * Kept as the raw string the select writes so a hand-edited URL never
     * throws; selectedYear() is what every figure on the page is built from.
     */
    #[Url(except: '')]
    public string $year = '';

    /**
     * The ledger row opened to the rows that produced its gap, or ''.
     */
    #[Url(except: '')]
    public string $openRow = '';

    /**
     * How many rows a decomposed gap shows before it stops.
     */
    private const BreakdownLimit = 8;

    /**
     * Open a ledger row on to its breakdown, or close the one already open.
     */
    public function toggleRow(string $key): void
    {
        $this->openRow = $this->openRow === $key ? '' : $key;
    }

    /**
     * The fiscal years that can be summarised, newest first.
     *
     * The current year is always offered even when nothing has been planned for
     * it yet: "no plan written this year" is a finding, not a reason to hide the
     * year from the picker.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function yearOptions(): array
    {
        return AnnualPlan::query()
            ->select('year')
            ->distinct()
            ->pluck('year')
            ->map(fn ($year): int => (int) $year)
            ->push((int) now()->year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    /**
     * The fiscal year every figure on this page is built from.
     *
     * Public so the header picker can render the year actually being
     * summarised. Binding the picker to $year instead would show its first
     * option while the page reported a different year — the defect this
     * rebuild carries a fix for.
     */
    #[Computed]
    public function fiscalYear(): int
    {
        $year = (int) $this->year;

        return in_array($year, $this->yearOptions, true) ? $year : (int) now()->year;
    }

    /**
     * Everything this page measures, for the selected fiscal year.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function figures(): array
    {
        return $this->figuresFor($this->fiscalYear);
    }

    /**
     * The same measures one fiscal year earlier, for the deltas.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function priorFigures(): array
    {
        return $this->figuresFor($this->fiscalYear - 1);
    }

    /**
     * The struck balance: the three pairs that state the year in one line.
     *
     * Each entry is a share of a real pair, never a bare total — money is the
     * leading currency's own share, because USD, EUR and SYP are not summable.
     *
     * @return array<int, array{key: string, label: string, covered: int|float, total: int|float, unit: string, share: int|null, delta: int|null, tone: string, currency: string|null, href: string}>
     */
    #[Computed]
    public function balance(): array
    {
        $now = $this->figures;
        $before = $this->priorFigures;
        $money = $this->leadingCurrency($now['money']);
        $priorMoney = $money === null ? null : ($before['money'][$money['code']] ?? null);

        return [
            [
                'key' => 'planned',
                'label' => __('executive_summary.programs_planned'),
                'covered' => $now['plans']['written'],
                'total' => $now['plans']['programs'],
                'unit' => __('executive_summary.programs'),
                'share' => $this->percentageOf($now['plans']['written'], $now['plans']['programs']),
                'delta' => $this->deltaBetween(
                    $this->percentageOf($now['plans']['written'], $now['plans']['programs']),
                    $this->percentageOf($before['plans']['written'], $before['plans']['programs']),
                ),
                'tone' => $this->toneFor($this->percentageOf($now['plans']['written'], $now['plans']['programs'])),
                'currency' => null,
                'href' => route('annual-plans.index'),
            ],
            [
                'key' => 'targets',
                'label' => __('executive_summary.targets_met'),
                'covered' => $now['targets']['achieved'],
                'total' => $now['targets']['committed'],
                'unit' => __('executive_summary.targets'),
                'share' => $this->percentageOf($now['targets']['achieved'], $now['targets']['committed']),
                'delta' => $this->deltaBetween(
                    $this->percentageOf($now['targets']['achieved'], $now['targets']['committed']),
                    $this->percentageOf($before['targets']['achieved'], $before['targets']['committed']),
                ),
                'tone' => $this->toneFor($this->percentageOf($now['targets']['achieved'], $now['targets']['committed'])),
                'currency' => null,
                'href' => route('targets.index'),
            ],
            [
                'key' => 'money',
                'label' => __('executive_summary.money_landed'),
                'covered' => $money['landed'] ?? 0.0,
                'total' => $money['scheduled'] ?? 0.0,
                'unit' => $money['code'] ?? '',
                'share' => $money === null ? null : $this->shareOfAmount($money['landed'], $money['scheduled']),
                'delta' => $money === null || $priorMoney === null ? null : $this->deltaBetween(
                    $this->shareOfAmount($money['landed'], $money['scheduled']),
                    $this->shareOfAmount($priorMoney['landed'], $priorMoney['scheduled']),
                ),
                'tone' => $this->toneFor($money === null ? null : $this->shareOfAmount($money['landed'], $money['scheduled'])),
                'currency' => $money['code'] ?? null,
                'href' => route('transfers.index'),
            ],
        ];
    }

    /**
     * The spread: every commitment the year made, against what answered it.
     *
     * Money contributes one row per currency present in the year, because the
     * three currencies are never summed or drawn on one scale.
     *
     * @return array<int, array{key: string, kind: string, committedLabel: string, committed: int|float, committedUnit: string, committedNote: string, deliveredLabel: string, delivered: int|float, deliveredUnit: string, deliveredNote: string, gap: int|float, gapLabel: string, share: int|null, bands: array<int, array{share: int, tone: string}>, tone: string, delta: int|null, currency: string|null, href: string, hrefLabel: string}>
     */
    #[Computed]
    public function ledger(): array
    {
        $now = $this->figures;
        $before = $this->priorFigures;
        $rows = [];

        $targets = $now['targets'];
        $targetShare = $this->percentageOf($targets['achieved'], $targets['committed']);
        $rows[] = [
            'key' => 'targets',
            'kind' => 'count',
            'committedLabel' => __('executive_summary.targets_committed'),
            'committed' => $targets['committed'],
            'committedUnit' => __('executive_summary.targets'),
            'committedNote' => __('executive_summary.across_programs_indicators', [
                'programs' => $targets['programs'],
                'indicators' => $targets['indicators'],
            ]),
            'deliveredLabel' => __('executive_summary.targets_reached'),
            'delivered' => $targets['achieved'],
            'deliveredUnit' => __('executive_summary.met'),
            'deliveredNote' => __('executive_summary.in_progress_not_started', [
                'progress' => $targets['inProgress'],
                'idle' => $targets['notStarted'],
            ]),
            'gap' => $targets['committed'] - $targets['achieved'],
            'gapLabel' => __('executive_summary.unmet'),
            'share' => $targetShare,
            'bands' => $this->bandsFor([
                [$targets['achieved'], 'good'],
                [$targets['inProgress'], 'warn'],
            ], $targets['committed']),
            'tone' => $this->toneFor($targetShare),
            'delta' => $this->deltaBetween($targetShare, $this->percentageOf($before['targets']['achieved'], $before['targets']['committed'])),
            'currency' => null,
            'href' => route('targets.index'),
            'hrefLabel' => __('executive_summary.open_targets'),
        ];

        foreach ($now['money'] as $code => $line) {
            $moneyShare = $this->shareOfAmount($line['landed'], $line['scheduled']);
            $priorLine = $before['money'][$code] ?? null;

            $rows[] = [
                'key' => 'money-'.$code,
                'kind' => 'money',
                'committedLabel' => __('executive_summary.scheduled_to_arrive'),
                'committed' => $line['scheduled'],
                'committedUnit' => $code,
                'committedNote' => trans_choice('executive_summary.count_tranches_scheduled', $line['scheduledTranches'], ['count' => $line['scheduledTranches']]),
                'deliveredLabel' => __('executive_summary.landed_in_account'),
                'delivered' => $line['landed'],
                'deliveredUnit' => $code,
                'deliveredNote' => trans_choice('executive_summary.count_tranches_received', $line['landedTranches'], ['count' => $line['landedTranches']]),
                'gap' => max(0.0, $line['scheduled'] - $line['landed']),
                'gapLabel' => __('executive_summary.outstanding'),
                'share' => $moneyShare,
                'bands' => $this->bandsFor([[$line['landed'], 'funding']], $line['scheduled']),
                'tone' => $this->toneFor($moneyShare),
                'delta' => $priorLine === null ? null : $this->deltaBetween($moneyShare, $this->shareOfAmount($priorLine['landed'], $priorLine['scheduled'])),
                'currency' => $code,
                'href' => route('transfers.index'),
                'hrefLabel' => __('executive_summary.open_transfers'),
            ];
        }

        $milestones = $now['milestones'];
        $milestoneShare = $this->percentageOf($milestones['reached'], $milestones['due']);
        $rows[] = [
            'key' => 'milestones',
            'kind' => 'count',
            'committedLabel' => __('executive_summary.milestones_due'),
            'committed' => $milestones['due'],
            'committedUnit' => __('executive_summary.milestones'),
            'committedNote' => __('executive_summary.dated_in_this_year'),
            'deliveredLabel' => __('executive_summary.milestones_reached'),
            'delivered' => $milestones['reached'],
            'deliveredUnit' => __('executive_summary.reached'),
            'deliveredNote' => __('executive_summary.ongoing_postponed', [
                'ongoing' => $milestones['ongoing'],
                'postponed' => $milestones['postponed'],
            ]),
            'gap' => $milestones['due'] - $milestones['reached'],
            'gapLabel' => __('executive_summary.not_reached'),
            'share' => $milestoneShare,
            'bands' => $this->bandsFor([
                [$milestones['reached'], 'good'],
                [$milestones['ongoing'], 'warn'],
            ], $milestones['due']),
            'tone' => $this->toneFor($milestoneShare),
            'delta' => $this->deltaBetween($milestoneShare, $this->percentageOf($before['milestones']['reached'], $before['milestones']['due'])),
            'currency' => null,
            'href' => route('milestones.index'),
            'hrefLabel' => __('executive_summary.open_milestones'),
        ];

        $reports = $now['reports'];
        $reportShare = $this->percentageOf($reports['approved'], $reports['owed']);
        $rows[] = [
            'key' => 'reports',
            'kind' => 'count',
            'committedLabel' => __('executive_summary.reports_owed'),
            'committed' => $reports['owed'],
            'committedUnit' => __('executive_summary.reports'),
            'committedNote' => __('executive_summary.planned_for_this_year'),
            'deliveredLabel' => __('executive_summary.reports_approved'),
            'delivered' => $reports['approved'],
            'deliveredUnit' => __('executive_summary.approved'),
            'deliveredNote' => __('executive_summary.in_review_still_draft', [
                'review' => $reports['reviewed'],
                'draft' => $reports['draft'],
            ]),
            'gap' => $reports['owed'] - $reports['approved'],
            'gapLabel' => __('executive_summary.not_accepted'),
            'share' => $reportShare,
            'bands' => $this->bandsFor([
                [$reports['approved'], 'good'],
                [$reports['reviewed'], 'warn'],
            ], $reports['owed']),
            'tone' => $this->toneFor($reportShare),
            'delta' => $this->deltaBetween($reportShare, $this->percentageOf($before['reports']['approved'], $before['reports']['owed'])),
            'currency' => null,
            'href' => route('reports.index'),
            'hrefLabel' => __('executive_summary.open_reports'),
        ];

        return $rows;
    }

    /**
     * The rows that produced the open row's gap, widest gap first.
     *
     * @return array<int, array{key: string, name: string, context: string, committed: int|float, delivered: int|float, share: int|null, tone: string, href: string|null}>
     */
    #[Computed]
    public function breakdown(): array
    {
        if ($this->openRow === '') {
            return [];
        }

        if ($this->openRow === 'targets') {
            return $this->targetsByProgram();
        }

        if (str_starts_with($this->openRow, 'money-')) {
            return $this->moneyByAgreement(substr($this->openRow, strlen('money-')));
        }

        if ($this->openRow === 'milestones') {
            return $this->milestonesByProject();
        }

        if ($this->openRow === 'reports') {
            return $this->reportsByIndicator();
        }

        return [];
    }

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
    public function percentageOf(int $covered, int $total): ?int
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
    public function shareOfAmount(float $landed, float $scheduled): ?int
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

    /**
     * Projects whose period overlaps the fiscal year.
     *
     * @return Builder<Project>
     */
    private function projectsRunningIn(int $year): Builder
    {
        return Project::query()
            ->where(fn (Builder $query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', $year.'-12-31'))
            ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $year.'-01-01'));
    }

    /**
     * The targets committed in the year's plans, with everything the
     * achievement rule needs already loaded.
     *
     * @return Collection<int, PlanIndicator>
     */
    private function committedTargets(int $year): Collection
    {
        return PlanIndicator::query()
            ->with([
                'indicator:id,name,measure',
                'reports:id,plan_indicator_id,status,value,planned_date',
            ])
            ->where('source_type', 'annual_plan')
            ->whereIn('source_id', $this->plansOf($year)->pluck('id'))
            ->get();
    }

    /**
     * The year's annual plans, with the program each one covers.
     *
     * @return Collection<int, AnnualPlan>
     */
    private function plansOf(int $year): Collection
    {
        return AnnualPlan::query()
            ->select(['id', 'program_id', 'year'])
            ->with('program:id,name')
            ->where('year', $year)
            ->get();
    }

    /**
     * The targets gap, decomposed into the programs that own it.
     *
     * @return array<int, array{key: string, name: string, context: string, committed: int|float, delivered: int|float, share: int|null, tone: string, href: string|null}>
     */
    private function targetsByProgram(): array
    {
        $plans = $this->plansOf($this->fiscalYear)->keyBy('id');
        $targets = $this->committedTargets($this->fiscalYear);

        return $targets
            ->groupBy(fn (PlanIndicator $target): int => (int) ($plans->get($target->source_id)?->program_id ?? 0))
            ->map(function (Collection $group, int $programId) use ($plans): array {
                $program = $plans->firstWhere('program_id', $programId)?->program;
                $achieved = $group->filter(fn (PlanIndicator $target): bool => $target->achievementStatus() === 'achieved')->count();
                $share = $this->percentageOf($achieved, $group->count());

                return [
                    'key' => 'program-'.$programId,
                    'name' => $program?->name ?? __('executive_summary.unassigned_program'),
                    'context' => trans_choice('executive_summary.count_indicators', $group->pluck('indicator_id')->unique()->count(), [
                        'count' => $group->pluck('indicator_id')->unique()->count(),
                    ]),
                    'committed' => $group->count(),
                    'delivered' => $achieved,
                    'share' => $share,
                    'tone' => $this->toneFor($share),
                    'href' => $program === null ? null : route('programs.show', $program),
                ];
            })
            ->sortBy([['share', 'asc'], ['name', 'asc']])
            ->take(self::BreakdownLimit)
            ->values()
            ->all();
    }

    /**
     * One currency's gap, decomposed into the agreements that owe it.
     *
     * @return array<int, array{key: string, name: string, context: string, committed: int|float, delivered: int|float, share: int|null, tone: string, href: string|null}>
     */
    private function moneyByAgreement(string $code): array
    {
        $year = $this->fiscalYear;

        return TransfersTracking::query()
            ->with(['donorProposal:id,donor_id,proposal_id,project_num', 'donorProposal.donor:id,name', 'donorProposal.proposal:id,name'])
            ->where(function (Builder $query) use ($code, $year): void {
                $query
                    ->where(fn (Builder $scheduled) => $scheduled->where('amount_transfered_currency', $code)->whereYear('scheduled_date', $year))
                    ->orWhere(fn (Builder $landed) => $landed->where('amount_received_currency', $code)->whereYear('receive_date', $year));
            })
            ->get()
            ->groupBy('donor_proposal_id')
            ->map(function (Collection $tranches) use ($code, $year): array {
                $agreement = $tranches->first()->donorProposal;
                $scheduled = (float) $tranches
                    ->filter(fn (TransfersTracking $tranche): bool => $tranche->amount_transfered_currency?->value === $code
                        && (int) $tranche->scheduled_date?->year === $year)
                    ->sum(fn (TransfersTracking $tranche): float => (float) $tranche->payment_amount);
                $landed = (float) $tranches
                    ->filter(fn (TransfersTracking $tranche): bool => $tranche->ar_status === ArStatus::Received
                        && $tranche->net_amount_received !== null
                        && $tranche->amount_received_currency?->value === $code
                        && (int) $tranche->receive_date?->year === $year)
                    ->sum(fn (TransfersTracking $tranche): float => (float) $tranche->net_amount_received);
                $share = $this->shareOfAmount($landed, $scheduled);

                return [
                    'key' => 'agreement-'.($agreement?->id ?? 0),
                    'name' => $agreement?->donor?->name ?? __('executive_summary.unassigned_agreement'),
                    'context' => $agreement?->proposal?->name ?? '—',
                    'committed' => $scheduled,
                    'delivered' => $landed,
                    'share' => $share,
                    'tone' => $this->toneFor($share),
                    'href' => $agreement === null ? null : route('funding-agreements.show', $agreement),
                ];
            })
            ->sortBy([['share', 'asc'], ['name', 'asc']])
            ->take(self::BreakdownLimit)
            ->values()
            ->all();
    }

    /**
     * The milestone gap, decomposed into the projects that own it.
     *
     * @return array<int, array{key: string, name: string, context: string, committed: int|float, delivered: int|float, share: int|null, tone: string, href: string|null}>
     */
    private function milestonesByProject(): array
    {
        return Milestone::query()
            ->select(['id', 'project_id', 'status'])
            ->with('project:id,name')
            ->whereRaw('year(coalesce(planned_end_date, planned_start_date)) = ?', [$this->fiscalYear])
            ->where('status', '!=', MilestoneStatus::Cancelled)
            ->get()
            ->groupBy('project_id')
            ->map(function (Collection $milestones): array {
                $project = $milestones->first()->project;
                $reached = $milestones->where('status', MilestoneStatus::Reached)->count();
                $share = $this->percentageOf($reached, $milestones->count());

                return [
                    'key' => 'project-'.($project?->id ?? 0),
                    'name' => $project?->name ?? __('executive_summary.unassigned_project'),
                    'context' => trans_choice('executive_summary.count_milestones', $milestones->count(), ['count' => $milestones->count()]),
                    'committed' => $milestones->count(),
                    'delivered' => $reached,
                    'share' => $share,
                    'tone' => $this->toneFor($share),
                    'href' => $project === null ? null : route('projects.show', $project),
                ];
            })
            ->sortBy([['share', 'asc'], ['name', 'asc']])
            ->take(self::BreakdownLimit)
            ->values()
            ->all();
    }

    /**
     * The reporting gap, decomposed into the indicators being reported on.
     *
     * @return array<int, array{key: string, name: string, context: string, committed: int|float, delivered: int|float, share: int|null, tone: string, href: string|null}>
     */
    private function reportsByIndicator(): array
    {
        return Report::query()
            ->select(['id', 'plan_indicator_id', 'status'])
            ->with(['planIndicator:id,indicator_id', 'planIndicator.indicator:id,name'])
            ->whereYear('planned_date', $this->fiscalYear)
            ->get()
            ->groupBy(fn (Report $report): int => (int) ($report->planIndicator?->indicator_id ?? 0))
            ->map(function (Collection $reports): array {
                $indicator = $reports->first()->planIndicator?->indicator;
                $approved = $reports->where('status', ReportStatus::Approved)->count();
                $share = $this->percentageOf($approved, $reports->count());

                return [
                    'key' => 'indicator-'.($indicator?->id ?? 0),
                    'name' => $indicator?->name ?? __('executive_summary.unassigned_indicator'),
                    'context' => trans_choice('executive_summary.count_reports', $reports->count(), ['count' => $reports->count()]),
                    'committed' => $reports->count(),
                    'delivered' => $approved,
                    'share' => $share,
                    'tone' => $this->toneFor($share),
                    'href' => route('reports.index'),
                ];
            })
            ->sortBy([['share', 'asc'], ['name', 'asc']])
            ->take(self::BreakdownLimit)
            ->values()
            ->all();
    }

    /**
     * The currency carrying the most money this year, or null when none moved.
     *
     * @param  array<string, array{code: string, scheduled: float, landed: float, scheduledTranches: int, landedTranches: int}>  $money
     * @return array{code: string, scheduled: float, landed: float, scheduledTranches: int, landedTranches: int}|null
     */
    private function leadingCurrency(array $money): ?array
    {
        return collect($money)
            ->sortByDesc(fn (array $line): float => max($line['scheduled'], $line['landed']))
            ->first();
    }

    /**
     * The measure's segments, as whole percentages of the commitment.
     *
     * @param  array<int, array{0: int|float, 1: string}>  $parts
     * @return array<int, array{share: int, tone: string}>
     */
    private function bandsFor(array $parts, int|float $total): array
    {
        if ($total <= 0) {
            return [];
        }

        $bands = [];
        $used = 0;

        foreach ($parts as [$value, $tone]) {
            $share = min(100 - $used, (int) floor((float) $value / (float) $total * 100));

            if ($share > 0) {
                $bands[] = ['share' => $share, 'tone' => $tone];
                $used += $share;
            }
        }

        return $bands;
    }

    /**
     * How a share reads: met, part-met, or nothing delivered at all.
     *
     * Red is reserved for a commitment nothing has answered, so that an alarm
     * on this page always means the same thing.
     */
    private function toneFor(?int $share): string
    {
        return match (true) {
            $share === null => 'neutral',
            $share >= 100 => 'good',
            $share > 0 => 'warn',
            default => 'critical',
        };
    }

    /**
     * The movement between two shares in percentage points, or null when
     * either year has nothing to compare.
     */
    private function deltaBetween(?int $now, ?int $before): ?int
    {
        if ($now === null || $before === null) {
            return null;
        }

        return $now - $before;
    }

    /**
     * Constrain plan indicators to the targets committed in a year's plans.
     *
     * @return callable(Builder<PlanIndicator>): void
     */
    private function committedThisYear(int $year): callable
    {
        return function (Builder $query) use ($year): void {
            $query
                ->where('source_type', 'annual_plan')
                ->whereIn('source_id', AnnualPlan::query()->select('id')->where('year', $year));
        };
    }
};
?>

@php
    /** Whole units at specimen scale: cents on a seven-figure sum are noise. */
    $amount = fn (int|float $value): string => number_format((float) round((float) $value));

    $toneInk = [
        'good' => 'text-status-good',
        'warn' => 'text-status-warn',
        'critical' => 'text-status-critical',
        'funding' => 'text-funding',
        'neutral' => 'text-secondary-medium dark:text-secondary-soft',
    ];

    $toneFill = [
        'good' => 'bg-status-good',
        'warn' => 'bg-status-warn',
        'critical' => 'bg-status-critical',
        'funding' => 'bg-funding',
        'neutral' => 'bg-secondary-light',
    ];

    $hairline = 'border-secondary-soft dark:border-secondary-medium';
    $muted = 'text-secondary-medium dark:text-secondary-soft';
    $ink = 'text-secondary-dark dark:text-white';
@endphp

<section
    class="exec-ledger admin-directory @container/ledger flex min-h-full w-full flex-1 flex-col gap-9 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white [&_[data-flux-heading]]:text-secondary-dark! [&_[data-flux-label]]:text-secondary-dark! dark:[&_[data-flux-heading]]:text-white! dark:[&_[data-flux-label]]:text-white!"
    aria-labelledby="executive-summary-heading"
>
    <x-ui.page-header heading-id="executive-summary-heading" :title="__('common.executive_summary')">
        <x-slot:filters>
            <x-ui.header-filter-select
                :label="__('executive_summary.fiscal_year')"
                wire:model.live="year"
                :value="(string) $this->fiscalYear"
            >
                @foreach ($this->yearOptions as $yearOption)
                    <flux:select.option :value="$yearOption" :wire:key="'summary-year-'.$yearOption">{{ $yearOption }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>
    </x-ui.page-header>

    {{-- The struck balance: the year in one line, every figure half of a pair. --}}
    <div class="grid gap-x-10 gap-y-7 @2xl/ledger:grid-cols-3">
        @foreach ($this->balance as $item)
            @php
                $share = $item['share'];
                $isMoney = $item['currency'] !== null;
            @endphp

            <div @class(['min-w-0', '@2xl/ledger:border-s @2xl/ledger:ps-8 '.$hairline => ! $loop->first]) wire:key="balance-{{ $item['key'] }}">
                <p class="type-label {{ $muted }}">{{ $item['label'] }}</p>

                <p class="mt-2 flex items-baseline gap-2.5 text-3xl leading-none font-extrabold tracking-tight tabular-nums @sm/ledger:text-4xl @xl/ledger:text-5xl {{ $share === null ? $muted : $toneInk[$item['tone']] }}">
                    @if ($share === null)
                        <span aria-hidden="true">—</span>
                        <span class="sr-only">{{ __('ui.no_records') }}</span>
                    @else
                        <span>{{ $share }}<span class="text-xl font-bold tracking-normal @xl/ledger:text-2xl">%</span></span>
                    @endif

                    @if ($item['delta'] !== null && $item['delta'] !== 0)
                        <span @class([
                            'inline-flex items-baseline gap-0.5 text-xs font-bold tracking-normal tabular-nums',
                            'text-status-good' => $item['delta'] > 0,
                            'text-status-critical' => $item['delta'] < 0,
                        ])>
                            {{ $item['delta'] > 0 ? '+' : '−' }}{{ abs($item['delta']) }}
                            <span class="sr-only">{{ __('executive_summary.points_against_prior_year', ['year' => $this->fiscalYear - 1]) }}</span>
                            <span aria-hidden="true" class="font-semibold">{{ __('executive_summary.points_short') }}</span>
                        </span>
                    @endif
                </p>

                <p class="mt-2.5 type-meta {{ $muted }}">
                    @if ($isMoney)
                        {{ __('executive_summary.amount_of_amount_unit', [
                            'covered' => $amount($item['covered']),
                            'total' => $amount($item['total']),
                            'unit' => $item['unit'],
                        ]) }}
                    @else
                        {{ __('executive_summary.covered_of_total_unit', [
                            'covered' => $item['covered'],
                            'total' => $item['total'],
                            'unit' => $item['unit'],
                        ]) }}
                    @endif
                </p>

                <div class="measure-track mt-3.5 h-[3px] w-full overflow-hidden" role="presentation">
                    <div
                        class="ledger-fill h-full {{ $toneFill[$item['tone']] }}"
                        style="width: {{ $share ?? 0 }}%; --row-order: {{ $loop->index }}"
                    ></div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- The spread. Committed on the start side, delivered on the end side,
         one variance spine between them carrying every row's shortfall. --}}
    <div>
        {{-- The column heads only exist where the columns do; below that the
             spread stacks and each half names itself. --}}
        <div class="hidden border-b pb-2.5 {{ $hairline }} @2xl/ledger:grid @2xl/ledger:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] @2xl/ledger:items-end @2xl/ledger:gap-x-8">
            <p class="type-label text-end {{ $muted }}">{{ __('executive_summary.committed') }}</p>
            <p class="type-label w-28 text-center {{ $muted }}">{{ __('executive_summary.gap') }}</p>
            <p class="type-label {{ $muted }}">{{ __('executive_summary.delivered') }}</p>
        </div>

        <ul class="ledger-spread relative">
            @foreach ($this->ledger as $row)
                @php
                    $isOpen = $this->openRow === $row['key'];
                    $share = $row['share'];
                    $hasGap = $share !== null && $share < 100;
                @endphp

                <li wire:key="ledger-{{ $row['key'] }}" data-ledger-row="{{ $row['key'] }}">
                    <div @class(['border-b '.$hairline, 'bg-secondary-soft/20 dark:bg-secondary-medium/15' => $isOpen])>
                        <button
                            type="button"
                            wire:click="toggleRow('{{ $row['key'] }}')"
                            class="ledger-open group grid w-full grid-cols-1 items-center gap-y-3 px-1 py-5 text-start @2xl/ledger:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] @2xl/ledger:gap-x-8 @2xl/ledger:gap-y-0"
                            aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                            aria-controls="breakdown-{{ $row['key'] }}"
                        >
                            {{-- What the year promised. --}}
                            <span class="min-w-0 @2xl/ledger:text-end">
                                <span class="block truncate type-meta {{ $muted }}">{{ $row['committedLabel'] }}</span>
                                <span class="mt-1 block text-2xl leading-none font-bold tracking-tight tabular-nums {{ $ink }} @xl/ledger:text-3xl">
                                    {{ $row['kind'] === 'money' ? $amount($row['committed']) : $row['committed'] }}<span class="ms-1.5 align-baseline text-[11px] font-semibold tracking-normal {{ $muted }}">{{ $row['committedUnit'] }}</span>
                                </span>
                                <span class="mt-1.5 block truncate type-meta {{ $muted }}">{{ $row['committedNote'] }}</span>
                            </span>

                            {{-- The spine, struck with this row's gap. Stacked, it
                                 lies flat between the pair; in columns it sits on
                                 the continuous rule the spread draws behind it. --}}
                            <span class="ledger-strike relative flex items-center justify-center self-stretch @2xl/ledger:w-28 @2xl/ledger:shrink-0">
                                @if ($share === null)
                                    <span class="ledger-chip relative z-10 px-2 type-meta {{ $muted }}" aria-hidden="true">—</span>
                                @elseif ($hasGap)
                                    <span class="ledger-gap-mark ledger-chip relative z-10 flex items-baseline gap-1.5 px-2 @2xl/ledger:flex-col @2xl/ledger:items-center @2xl/ledger:gap-0.5" style="--row-order: {{ $loop->index }}">
                                        <span class="text-base leading-none font-bold tabular-nums {{ $toneInk[$row['tone']] }} @2xl/ledger:text-xl">
                                            −{{ $row['kind'] === 'money' ? $amount($row['gap']) : $row['gap'] }}
                                        </span>
                                        <span class="type-label {{ $muted }}">{{ $row['gapLabel'] }}</span>
                                    </span>
                                @else
                                    <span class="ledger-gap-mark ledger-chip relative z-10 flex items-center gap-1.5 px-2 text-status-good" style="--row-order: {{ $loop->index }}">
                                        <flux:icon.check class="size-4 shrink-0" />
                                        <span class="type-label">{{ __('executive_summary.honoured') }}</span>
                                    </span>
                                @endif
                            </span>

                            {{-- What answered it. --}}
                            <span class="min-w-0">
                                <span class="flex items-center gap-1.5 truncate type-meta {{ $muted }}">
                                    <span class="size-1.5 shrink-0 rounded-full {{ $toneFill[$row['tone']] }}" aria-hidden="true"></span>
                                    {{ $row['deliveredLabel'] }}
                                </span>
                                <span class="mt-1 flex items-baseline gap-2 text-2xl leading-none font-bold tracking-tight tabular-nums {{ $toneInk[$row['tone']] }} @xl/ledger:text-3xl">
                                    <span>{{ $row['kind'] === 'money' ? $amount($row['delivered']) : $row['delivered'] }}<span class="ms-1.5 align-baseline text-[11px] font-semibold tracking-normal {{ $muted }}">{{ $row['deliveredUnit'] }}</span></span>

                                    @if ($row['delta'] !== null && $row['delta'] !== 0)
                                        <span @class([
                                            'text-[11px] font-bold tracking-normal tabular-nums',
                                            'text-status-good' => $row['delta'] > 0,
                                            'text-status-critical' => $row['delta'] < 0,
                                        ])>
                                            {{ $row['delta'] > 0 ? '+' : '−' }}{{ abs($row['delta']) }}{{ __('executive_summary.points_short') }}
                                            <span class="sr-only">{{ __('executive_summary.points_against_prior_year', ['year' => $this->fiscalYear - 1]) }}</span>
                                        </span>
                                    @endif
                                </span>
                                <span class="mt-1.5 flex items-center gap-2 truncate type-meta {{ $muted }}">
                                    {{ $row['deliveredNote'] }}
                                    <flux:icon.chevron-down class="size-3.5 shrink-0 transition-transform duration-200 {{ $isOpen ? 'rotate-180' : '' }}" />
                                </span>
                            </span>
                        </button>

                        {{-- The commitment drawn at full length, with what answered
                             it filled in. Nothing answered it at all, and the track
                             is dashed rather than merely empty. --}}
                        <div
                            @class([
                                'mb-5 h-1 w-full overflow-hidden',
                                'measure-track' => $share !== 0,
                                'ledger-unanswered' => $share === 0,
                            ])
                            role="progressbar"
                            aria-valuenow="{{ $share ?? 0 }}"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-label="{{ $row['committedLabel'] }} — {{ $row['deliveredLabel'] }}"
                        >
                            <div class="flex h-full w-full">
                                @foreach ($row['bands'] as $band)
                                    <div class="ledger-fill h-full {{ $toneFill[$band['tone']] }}" style="width: {{ $band['share'] }}%; --row-order: {{ $loop->parent->index }}"></div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- The gap, decomposed into the records that own it. --}}
                    @if ($isOpen)
                        <div id="breakdown-{{ $row['key'] }}" class="ledger-reveal border-b bg-secondary-soft/20 px-1 pb-5 {{ $hairline }} dark:bg-secondary-medium/15">
                            @if ($this->breakdown === [])
                                <p class="py-4 text-center type-meta {{ $muted }}">{{ __('executive_summary.nothing_behind_this_figure') }}</p>
                            @else
                                <p class="pb-3 type-label {{ $muted }}">{{ __('executive_summary.widest_gap_first') }}</p>

                                <ul class="grid gap-3">
                                    @foreach ($this->breakdown as $item)
                                        <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1.5 @2xl/ledger:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] @2xl/ledger:gap-x-8" wire:key="breakdown-{{ $row['key'] }}-{{ $item['key'] }}">
                                            <span class="min-w-0 @2xl/ledger:text-end">
                                                @if ($item['href'] !== null)
                                                    <a href="{{ $item['href'] }}" wire:navigate class="block truncate text-sm font-semibold underline decoration-secondary-light underline-offset-4 hover:decoration-primary {{ $ink }}">{{ $item['name'] }}</a>
                                                @else
                                                    <span class="block truncate text-sm font-semibold {{ $ink }}">{{ $item['name'] }}</span>
                                                @endif
                                                <span class="block truncate type-meta {{ $muted }}">{{ $item['context'] }}</span>
                                            </span>

                                            <span class="ledger-strike flex shrink-0 items-center justify-center self-stretch @2xl/ledger:w-28">
                                                <span class="ledger-chip relative z-10 px-2 text-xs font-bold tabular-nums {{ $item['share'] === null ? $muted : $toneInk[$item['tone']] }}">
                                                    {{ $item['share'] === null ? '—' : $item['share'].'%' }}
                                                </span>
                                            </span>

                                            <span class="col-span-2 min-w-0 @2xl/ledger:col-span-1">
                                                <span class="block type-meta tabular-nums {{ $muted }}">
                                                    {{ $row['kind'] === 'money' ? $amount($item['delivered']).' / '.$amount($item['committed']) : $item['delivered'].' / '.$item['committed'] }}
                                                </span>
                                                <span class="measure-track mt-1.5 block h-1 w-full overflow-hidden">
                                                    <span class="block h-full {{ $toneFill[$item['tone']] }}" style="width: {{ $item['share'] ?? 0 }}%"></span>
                                                </span>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>

                                <p class="pt-4 text-end">
                                    <a href="{{ $row['href'] }}" wire:navigate class="type-meta font-semibold underline decoration-secondary-light underline-offset-4 hover:decoration-primary {{ $ink }}">{{ $row['hrefLabel'] }}</a>
                                </p>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>

    {{-- The coverage rail: the four measures, as one strip rather than four cards. --}}
    <div>
        <p class="type-label {{ $muted }}">{{ __('executive_summary.how_much_of_the_portfolio_is_planned_targeted_and_reached') }}</p>

        <dl class="mt-4 grid gap-x-8 gap-y-6 @xl/ledger:grid-cols-2 @5xl/ledger:grid-cols-4">
            @foreach ($this->coverageMetrics as $metric)
                @php
                    $percentage = $this->percentageOf($metric['covered'], $metric['total']);
                @endphp

                <div @class(['min-w-0', 'border-s ps-8 '.$hairline => ! $loop->first]) wire:key="coverage-{{ $metric['key'] }}">
                    <dt class="flex items-baseline justify-between gap-2">
                        <span class="truncate type-meta font-semibold {{ $ink }}">{{ $metric['label'] }}</span>
                        <span class="shrink-0 type-label {{ $muted }}">{{ $metric['scope'] }}</span>
                    </dt>
                    <dd class="mt-1.5">
                        <p class="flex items-baseline gap-2 text-2xl leading-none font-bold tracking-tight tabular-nums {{ $percentage === null ? $muted : $ink }}">
                            {{ $percentage === null ? '—' : $percentage.'%' }}
                            <span class="type-meta font-medium {{ $muted }}">
                                {{ __('executive_summary.covered_of_total_unit', ['covered' => $metric['covered'], 'total' => $metric['total'], 'unit' => $metric['unit']]) }}
                            </span>
                        </p>
                        <div
                            class="measure-track mt-2.5 h-[3px] w-full overflow-hidden"
                            role="progressbar"
                            aria-valuenow="{{ $percentage ?? 0 }}"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-label="{{ $metric['label'] }}"
                        >
                            <div class="ledger-fill h-full bg-secondary-dark dark:bg-secondary-soft" style="width: {{ $percentage ?? 0 }}%; --row-order: {{ $loop->index }}"></div>
                        </div>
                        <p class="mt-2 type-meta {{ $muted }}">{{ $metric['description'] }}</p>
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>

    {{-- The year's stated reach. A commitment the schema records no delivery
         against, so it is stated as ambition rather than faked into a pair. --}}
    <div class="border-t pt-8 {{ $hairline }}">
        <div class="grid gap-x-10 gap-y-7 @3xl/ledger:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
            <div class="min-w-0">
                <p class="type-label {{ $muted }}">{{ __('executive_summary.people_this_year_sets_out_to_reach') }}</p>
                <p class="mt-2 text-3xl leading-none font-extrabold tracking-tight tabular-nums {{ $ink }} @xl/ledger:text-5xl">
                    {{ $amount($this->reach['beneficiaries']) }}
                </p>
                <p class="mt-2.5 type-meta {{ $muted }}">
                    {{ trans_choice('executive_summary.planned_across_count_projects', $this->reach['projects'], ['count' => $this->reach['projects']]) }}
                </p>
            </div>

            <div class="min-w-0">
                @if ($this->reach['beneficiaries'] <= 0)
                    <p class="type-meta {{ $muted }}">{{ __('executive_summary.no_beneficiary_targets_recorded') }}</p>
                @else
                    <div class="measure-track flex h-2.5 w-full overflow-hidden" role="presentation">
                        @foreach ($this->reach['groups'] as $group)
                            <div
                                class="ledger-fill h-full {{ ['women' => 'bg-funding', 'men' => 'bg-status-active', 'girls' => 'bg-status-warn', 'boys' => 'bg-secondary-light'][$group['key']] }}"
                                style="width: {{ $group['share'] }}%; --row-order: {{ $loop->index }}"
                            ></div>
                        @endforeach
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 @xl/ledger:grid-cols-4">
                        @foreach ($this->reach['groups'] as $group)
                            <div class="min-w-0" wire:key="group-{{ $group['key'] }}">
                                <dt class="flex items-center gap-1.5 truncate type-meta {{ $muted }}">
                                    <span class="size-1.5 shrink-0 rounded-full {{ ['women' => 'bg-funding', 'men' => 'bg-status-active', 'girls' => 'bg-status-warn', 'boys' => 'bg-secondary-light'][$group['key']] }}" aria-hidden="true"></span>
                                    {{ $group['label'] }}
                                </dt>
                                <dd class="mt-0.5 text-lg leading-none font-bold tabular-nums {{ $ink }}">
                                    {{ $amount($group['count']) }}<span class="ms-1.5 text-[11px] font-semibold {{ $muted }}">{{ $group['share'] }}%</span>
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </div>
        </div>

        @if ($this->reach['cityCount'] > 0)
            <div class="mt-7 flex flex-wrap items-baseline gap-x-2 gap-y-1.5">
                <span class="type-label {{ $muted }}">{{ trans_choice('executive_summary.across_count_cities', $this->reach['cityCount'], ['count' => $this->reach['cityCount']]) }}</span>
                @foreach ($this->reach['cities'] as $city)
                    <span class="type-meta {{ $ink }}" wire:key="city-{{ $city->id }}">{{ $city->name }}@if (! $loop->last)<span class="{{ $muted }}"> ·</span>@endif</span>
                @endforeach
            </div>
        @endif
    </div>

    <span wire:loading.delay.flex wire:target="year" class="hidden items-center gap-1.5 type-meta {{ $muted }}">
        <flux:icon.arrow-path class="size-3.5 animate-spin" /> {{ __('common.updating') }}
    </span>
</section>
