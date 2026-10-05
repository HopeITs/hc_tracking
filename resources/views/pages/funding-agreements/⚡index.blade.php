<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Livewire\Concerns\ManagesAgreementForm;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use App\Support\AgreementMoney;
use App\Support\CsvExport;
use App\Support\Like;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('titles.funding_agreements')] class extends Component {
    use FiltersFinanceRegister;
    use ManagesAgreementForm;
    use WithPagination;

    /**
     * Where an agreement's money can be stuck. Each one is a filter the page
     * can be narrowed to.
     *
     * @var list<string>
     */
    public const STAGES = ['overdue', 'failed', 'upcoming', 'unscheduled', 'in_transit', 'received'];

    /**
     * Conditions that are not about where the money is.
     *
     * @var list<string>
     */
    public const FLAGS = ['report_overdue', 'net_missing', 'other_currency', 'no_tranches'];

    /**
     * Every property a filter control writes.
     *
     * @var list<string>
     */
    private const FILTERS = ['search', 'donor', 'currency', 'stage', 'flag', 'min', 'max'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $donor = '';

    #[Url(except: '')]
    public string $currency = '';

    #[Url(except: '')]
    public string $stage = '';

    #[Url(except: '')]
    public string $flag = '';

    /** The smallest budget to show. */
    #[Url(except: '')]
    public string $min = '';

    /** The largest budget to show. */
    #[Url(except: '')]
    public string $max = '';

    /**
     * Everything here arrives from the URL. Normalise on the way in, so a
     * hand-typed value never leaves a filter applied with no control showing it.
     */
    public function mount(): void
    {
        $this->normaliseFilters();
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->normaliseFilters();
            $this->resetPage();
        }
    }

    protected function normaliseFilters(): void
    {
        if (! in_array($this->stage, self::STAGES, true)) {
            $this->stage = '';
        }

        if (! in_array($this->flag, self::FLAGS, true)) {
            $this->flag = '';
        }

        if (Currency::tryFrom($this->currency) === null) {
            $this->currency = '';
        }

        if (! ctype_digit($this->donor)) {
            $this->donor = '';
        }

        foreach (['min', 'max'] as $bound) {
            $value = $this->{$bound};
            $this->{$bound} = is_numeric($value) && (float) $value >= 0 && (float) $value <= self::MAX_BUDGET ? $value : '';
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'donor', 'currency', 'stage', 'flag', 'min', 'max']);
        $this->resetRegisterFilters();
        $this->resetPage();
    }

    /**
     * Toggle the stage filter from a ledger figure, a collection-line segment
     * or a stage chip.
     */
    public function toggleStage(string $stage): void
    {
        $this->stage = $this->stage === $stage || ! in_array($stage, self::STAGES, true) ? '' : $stage;
        $this->resetPage();
    }

    /**
     * Narrow to one flag, or clear it by choosing it again.
     */
    public function toggleFlag(string $flag): void
    {
        $this->flag = $this->flag === $flag || ! in_array($flag, self::FLAGS, true) ? '' : $flag;
        $this->resetPage();
    }

    /**
     * Agreements matching every active filter.
     *
     * The ledger and the stage chips pass $withStage false so they keep
     * showing the whole picture while the stage filter narrows the lanes.
     *
     * @return Builder<DonorProposal>
     */
    protected function filteredQuery(bool $withStage = true): Builder
    {
        $search = trim($this->search);

        return DonorProposal::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('external_project_number', 'like', Like::contains($search))
                        ->orWhereHas('donor', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))
                        ->orWhereHas('proposal', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                            ->where('name', 'like', Like::contains($search))
                            ->orWhere('internal_project_number', 'like', Like::contains($search))));
                });
            })
            ->when($this->donor !== '', fn (Builder $query) => $query->where('donor_id', (int) $this->donor))
            ->when(Currency::tryFrom($this->currency), fn (Builder $query, Currency $currency) => $query->where('currency', $currency))
            ->when($this->min !== '', fn (Builder $query) => $query->where('donor_proposal.budget', '>=', (float) $this->min))
            ->when($this->max !== '', fn (Builder $query) => $query->where('donor_proposal.budget', '<=', (float) $this->max))
            ->when($this->flag !== '', fn (Builder $query) => $this->applyFlag($query, $this->flag))
            ->when($this->selectedYear() !== null, fn (Builder $query) => $this->applyYear($query))
            ->when($withStage && in_array($this->stage, self::STAGES, true), fn (Builder $query) => $this->applyStage($query, $this->stage));
    }

    /**
     * Narrow a query to agreements with money stuck at one stage.
     *
     * Only tranches in the agreement's own currency count towards scheduling
     * and full receipt: a budget and a payment in different currencies cannot
     * be compared.
     *
     * @param  Builder<DonorProposal>  $query
     * @return Builder<DonorProposal>
     */
    protected function applyStage(Builder $query, string $stage): Builder
    {
        $today = CarbonImmutable::today()->toDateString();
        $sameCurrencyTotal = fn (string $condition): string => 'donor_proposal.budget > (select coalesce(sum(t.payment_amount), 0) from transfers_tracking t where t.donor_proposal_id = donor_proposal.id and t.amount_transfered_currency = donor_proposal.currency'.$condition.')';

        return match ($stage) {
            'overdue' => $query->whereHas('transfers', fn (Builder $query) => $query
                ->where('ar_status', ArStatus::Pending)
                ->whereNull('transfer_date')
                ->whereDate('scheduled_date', '<', $today)),
            'upcoming' => $query->whereHas('transfers', fn (Builder $query) => $query
                ->where('ar_status', ArStatus::Pending)
                ->whereNull('transfer_date')
                ->where(fn (Builder $query) => $query->whereDate('scheduled_date', '>=', $today)->orWhereNull('scheduled_date'))),
            'in_transit' => $query->whereHas('transfers', fn (Builder $query) => $query
                ->where('ar_status', ArStatus::Pending)
                ->whereNotNull('transfer_date')),
            'failed' => $query->whereHas('transfers', fn (Builder $query) => $query->where('ar_status', ArStatus::Failed)),
            'unscheduled' => $query->whereRaw($sameCurrencyTotal('')),
            'received' => $query->where('budget', '>', 0)->whereRaw('not '.$sameCurrencyTotal(" and t.ar_status = '".ArStatus::Received->value."'")),
            default => $query,
        };
    }

    /**
     * Narrow a query to agreements carrying one flag.
     *
     * @param  Builder<DonorProposal>  $query
     * @return Builder<DonorProposal>
     */
    protected function applyFlag(Builder $query, string $flag): Builder
    {
        $today = CarbonImmutable::today()->toDateString();

        return match ($flag) {
            'report_overdue' => $query->whereHas('reports', fn (Builder $query) => $query->whereNull('submitted_on')->whereDate('due_on', '<', $today)),
            'net_missing' => $query->whereHas('transfers', fn (Builder $query) => $query->missingNet()),
            'other_currency' => $query->whereHas('transfers', fn (Builder $query) => $query->whereColumn('transfers_tracking.amount_transfered_currency', '<>', 'donor_proposal.currency')),
            'no_tranches' => $query->doesntHave('transfers'),
            default => $query,
        };
    }

    /**
     * Keep only agreements with money in the chosen year: a tranche due in it
     * or, while the lifetime figures are shown, one that landed in it.
     *
     * @param  Builder<DonorProposal>  $query
     * @return Builder<DonorProposal>
     */
    protected function applyYear(Builder $query): Builder
    {
        $bounds = AgreementMoney::yearBounds($this->selectedYear());
        $yearOnly = $this->yearOnly();

        return $query->whereHas('transfers', fn (Builder $query) => $query->where(fn (Builder $query) => $query
            ->whereBetween('scheduled_date', $bounds)
            ->when(! $yearOnly, fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query
                ->where('ar_status', ArStatus::Received)
                ->whereBetween('receive_date', $bounds)))));
    }

    /**
     * The visible page, most urgent first: the earliest unsent tranche leads,
     * so anything overdue sits at the top.
     *
     * @return LengthAwarePaginator<int, DonorProposal>
     */
    #[Computed]
    public function agreements(): LengthAwarePaginator
    {
        $today = CarbonImmutable::today()->toDateString();

        return $this->filteredQuery()
            ->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'external_project_number', 'updated_at'])
            ->addSelect(['next_due' => TransfersTracking::query()
                ->select('scheduled_date')
                ->whereColumn('transfers_tracking.donor_proposal_id', 'donor_proposal.id')
                ->where('ar_status', ArStatus::Pending)
                ->whereNull('transfer_date')
                ->whereNotNull('scheduled_date')
                ->orderBy('scheduled_date')
                ->limit(1),
            ])
            ->with([
                'donor:id,name',
                'proposal:id,name,internal_project_number,status',
                'proposal.projects' => fn ($query) => $query->select(['projects.id', 'projects.name'])->orderBy('projects.name'),
                'transfers' => fn ($query) => $query
                    ->select(['id', 'donor_proposal_id', 'account_id', 'payment_amount', 'amount_transfered_currency', 'scheduled_date', 'transfer_date', 'fees', 'fees_currency', 'amount_received', 'net_amount_received', 'amount_received_currency', 'receive_date', 'ar_status'])
                    ->orderByRaw('scheduled_date is null, scheduled_date')
                    ->orderBy('id'),
                'transfers.account:id,account_name',
            ])
            ->withCount(['reports as overdue_reports_count' => fn (Builder $query) => $query
                ->whereNull('submitted_on')
                ->whereDate('due_on', '<', $today)])
            ->withMin(['reports as next_report_due' => fn (Builder $query) => $query
                ->whereNull('submitted_on')
                ->whereDate('due_on', '>=', $today)], 'due_on')
            ->orderByRaw('next_due is null')
            ->orderBy('next_due')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * The collection ledger, one line per currency, across every agreement the
     * filters match (not only the visible page, and ignoring the stage filter).
     *
     * Received is net money that landed in the agreement's own currency, the
     * same reading the agreement page gives. A received tranche with no net
     * amount recorded counts as nothing landed; it is reported beside the line
     * so someone fills it in. Committed splits into received (with what fees
     * and exchange took, and what is still unrecorded), in transit, upcoming,
     * overdue, failed and not scheduled. Only tranches in the agreement's own
     * currency count; any others are counted aside so nothing is silently
     * dropped.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function ledger(): Collection
    {
        $today = CarbonImmutable::today()->toDateString();
        $ids = $this->filteredQuery(withStage: false)->pluck('id');

        $committed = DonorProposal::query()
            ->toBase()
            ->whereIn('id', $ids)
            ->selectRaw('currency, sum(budget) as total, count(*) as agreements')
            ->groupBy('currency')
            ->get()
            ->keyBy('currency');

        $pending = ArStatus::Pending->value;
        $received = ArStatus::Received->value;
        $failed = ArStatus::Failed->value;

        $landedHere = 'ar_status = ? and net_amount_received is not null and amount_received_currency = donor_proposal.currency';

        $money = TransfersTracking::query()
            ->toBase()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->whereIn('donor_proposal.id', $ids)
            ->whereColumn('transfers_tracking.amount_transfered_currency', 'donor_proposal.currency')
            ->selectRaw(
                "donor_proposal.currency as currency,
                sum(payment_amount) as scheduled,
                sum(case when ar_status = ? then payment_amount else 0 end) as received_gross,
                sum(case when ar_status = ? then 1 else 0 end) as received_count,
                sum(case when {$landedHere} then net_amount_received else 0 end) as net,
                sum(case when {$landedHere} and payment_amount > net_amount_received then payment_amount - net_amount_received else 0 end) as lost,
                sum(case when {$landedHere} and net_amount_received > payment_amount then net_amount_received - payment_amount else 0 end) as gained,
                sum(case when ar_status = ? and net_amount_received is null then payment_amount else 0 end) as net_missing,
                sum(case when ar_status = ? and net_amount_received is null then 1 else 0 end) as net_missing_count,
                sum(case when ar_status = ? and net_amount_received is not null and amount_received_currency <> donor_proposal.currency then payment_amount else 0 end) as landed_elsewhere,
                sum(case when ar_status = ? and transfer_date is null and scheduled_date < ? then payment_amount else 0 end) as overdue,
                sum(case when ar_status = ? and transfer_date is null and scheduled_date < ? then 1 else 0 end) as overdue_count,
                sum(case when ar_status = ? and transfer_date is null and (scheduled_date >= ? or scheduled_date is null) then payment_amount else 0 end) as upcoming,
                sum(case when ar_status = ? and transfer_date is null and (scheduled_date >= ? or scheduled_date is null) then 1 else 0 end) as upcoming_count,
                min(case when ar_status = ? and transfer_date is null and scheduled_date >= ? then scheduled_date end) as next_upcoming,
                sum(case when ar_status = ? and transfer_date is not null then payment_amount else 0 end) as in_transit,
                sum(case when ar_status = ? and transfer_date is not null then 1 else 0 end) as in_transit_count,
                sum(case when ar_status = ? then payment_amount else 0 end) as failed,
                sum(case when ar_status = ? then 1 else 0 end) as failed_count,
                sum(case when ar_status = ? and transfer_date is not null then payment_amount else 0 end) as failed_sent",
                [
                    $received,
                    $received,
                    $received,
                    $received,
                    $received,
                    $received,
                    $received,
                    $received,
                    $pending, $today,
                    $pending, $today,
                    $pending, $today,
                    $pending, $today,
                    $pending, $today,
                    $pending,
                    $pending,
                    $failed,
                    $failed,
                    $failed,
                ],
            )
            ->groupBy('donor_proposal.currency')
            ->get()
            ->keyBy('currency');

        $otherCurrency = TransfersTracking::query()
            ->toBase()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->whereIn('donor_proposal.id', $ids)
            ->whereColumn('transfers_tracking.amount_transfered_currency', '<>', 'donor_proposal.currency')
            ->selectRaw('donor_proposal.currency as currency, count(*) as tranches')
            ->groupBy('donor_proposal.currency')
            ->pluck('tranches', 'currency');

        return $committed->keys()
            ->sort()
            ->values()
            ->map(function (string $currency) use ($committed, $money, $otherCurrency): array {
                $row = $money[$currency] ?? null;
                $number = fn (string $column): float => (float) ($row->{$column} ?? 0);

                $committedTotal = (float) $committed[$currency]->total;
                $receivedGross = $number('received_gross');
                $inTransit = $number('in_transit');

                return [
                    'currency' => $currency,
                    'agreements' => (int) $committed[$currency]->agreements,
                    'committed' => $committedTotal,
                    'scheduled' => $number('scheduled'),
                    'sent' => $receivedGross + $inTransit + $number('failed_sent'),
                    'received' => $number('net'),
                    'receivedGross' => $receivedGross,
                    'receivedCount' => (int) $number('received_count'),
                    'lost' => $number('lost'),
                    'gained' => $number('gained'),
                    'netMissing' => $number('net_missing'),
                    'netMissingCount' => (int) $number('net_missing_count'),
                    'landedElsewhere' => $number('landed_elsewhere'),
                    'unscheduled' => max(0, $committedTotal - $number('scheduled')),
                    'overscheduled' => max(0, $number('scheduled') - $committedTotal),
                    'overdue' => $number('overdue'),
                    'overdueCount' => (int) $number('overdue_count'),
                    'upcoming' => $number('upcoming'),
                    'upcomingCount' => (int) $number('upcoming_count'),
                    'nextUpcoming' => filled($row->next_upcoming ?? null) ? CarbonImmutable::parse($row->next_upcoming) : null,
                    'inTransit' => $inTransit,
                    'inTransitCount' => (int) $number('in_transit_count'),
                    'failed' => $number('failed'),
                    'failedCount' => (int) $number('failed_count'),
                    'otherCurrencyTranches' => (int) ($otherCurrency[$currency] ?? 0),
                ];
            });
    }

    /**
     * How many matching agreements sit at each stage, ignoring the stage filter.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function stageCounts(): array
    {
        return collect(self::STAGES)
            ->mapWithKeys(fn (string $stage): array => [$stage => $this->applyStage($this->filteredQuery(withStage: false), $stage)->count()])
            ->all();
    }

    /**
     * The cash-flow calendar: one month grid shared by every lane, and what is
     * expected to land in each month, per currency.
     *
     * Built from every tranche of every matching agreement (not only the
     * visible page), so the month and quarter totals are the true pipeline.
     * The grid always shows a little of the past and a stretch of the future
     * around today, and runs in whole quarters so the quarter zoom never
     * shows a clipped quarter at either end.
     *
     * @return array{start: CarbonImmutable, count: int, today: float, months: list<array{key: string, quarterKey: string, label: string, year: ?string, current: bool, date: CarbonImmutable, quarter: bool}>, quarters: list<array{key: string, label: string, index: int, current: bool}>, inflow: array<string, array{max: float, quarterMax: float, months: array<string, array{received: float, pending: float, late: float, failed: float, total: float}>, quarters: array<string, array{received: float, pending: float, late: float, failed: float, total: float}>}>}
     */
    #[Computed]
    public function calendar(): array
    {
        $today = CarbonImmutable::today();

        $tranches = TransfersTracking::query()
            ->toBase()
            ->whereIn('donor_proposal_id', $this->filteredQuery()->select('donor_proposal.id')->toBase())
            ->whereNotNull('scheduled_date')
            ->get(['payment_amount', 'amount_transfered_currency', 'scheduled_date', 'transfer_date', 'ar_status']);

        $dates = $tranches->map(fn (object $tranche): CarbonImmutable => CarbonImmutable::parse($tranche->scheduled_date));
        $start = $dates->push($today->subMonths(2))->min()->startOfQuarter();
        $end = $dates->push($today->addMonths(5))->max()->startOfQuarter()->addMonths(2);

        $months = [];
        $quarters = [];
        for ($month = $start; $month->lessThanOrEqualTo($end); $month = $month->addMonth()) {
            if ($month->month % 3 === 1) {
                $quarters[] = [
                    'key' => $this->quarterKey($month),
                    'label' => __('funding_agreements.quarter_label', ['number' => $month->quarter]),
                    'year' => $month->quarter === 1 || $quarters === [] ? $month->format('Y') : null,
                    'index' => count($months),
                    'current' => $month->year === $today->year && $month->quarter === $today->quarter,
                ];
            }

            $months[] = [
                'key' => $month->format('Y-m'),
                'quarterKey' => $this->quarterKey($month),
                'label' => $month->translatedFormat(__('formats.month_short')),
                'year' => $month->month === 1 || $months === [] ? $month->format('Y') : null,
                'current' => $month->isSameMonth($today),
                'date' => $month,
                'quarter' => $month->month % 3 === 1,
            ];
        }

        $inflow = $tranches
            ->groupBy('amount_transfered_currency')
            ->sortKeys()
            ->map(function (Collection $group) use ($today): array {
                $byMonth = $group
                    ->groupBy(fn (object $tranche): string => CarbonImmutable::parse($tranche->scheduled_date)->format('Y-m'))
                    ->map(function (Collection $monthTranches) use ($today): array {
                        $sum = fn (callable $filter): float => (float) $monthTranches->filter($filter)->sum('payment_amount');

                        $received = $sum(fn (object $tranche): bool => $tranche->ar_status === ArStatus::Received->value);
                        $failed = $sum(fn (object $tranche): bool => $tranche->ar_status === ArStatus::Failed->value);
                        $late = $sum(fn (object $tranche): bool => $tranche->ar_status === ArStatus::Pending->value
                            && $tranche->transfer_date === null
                            && CarbonImmutable::parse($tranche->scheduled_date)->lessThan($today));
                        $total = (float) $monthTranches->sum('payment_amount');

                        return [
                            'received' => $received,
                            'late' => $late,
                            'failed' => $failed,
                            'pending' => max(0.0, $total - $received - $late - $failed),
                            'total' => $total,
                        ];
                    });

                $byQuarter = $byMonth
                    ->groupBy(fn (array $money, string $month): string => $this->quarterKey(CarbonImmutable::createFromFormat('!Y-m', $month)), preserveKeys: true)
                    ->map(fn (Collection $quarterMonths): array => [
                        'received' => (float) $quarterMonths->sum('received'),
                        'pending' => (float) $quarterMonths->sum('pending'),
                        'late' => (float) $quarterMonths->sum('late'),
                        'failed' => (float) $quarterMonths->sum('failed'),
                        'total' => (float) $quarterMonths->sum('total'),
                    ]);

                return [
                    'max' => (float) $byMonth->max('total'),
                    'quarterMax' => (float) $byQuarter->max('total'),
                    'months' => $byMonth->all(),
                    'quarters' => $byQuarter->all(),
                ];
            })
            ->all();

        return [
            'start' => $start,
            'count' => count($months),
            'today' => $this->monthOffset($start, $today),
            'months' => $months,
            'quarters' => $quarters,
            'inflow' => $inflow,
        ];
    }

    /**
     * The key a month's quarter is filed under, e.g. 2026-Q3.
     */
    protected function quarterKey(CarbonInterface $date): string
    {
        return $date->year.'-Q'.$date->quarter;
    }

    /**
     * How many months a date sits after the calendar's first month, with the
     * day as a fraction, so a lane can place it at `offset × month width`.
     */
    protected function monthOffset(CarbonInterface $start, CarbonInterface $date): float
    {
        $wholeMonths = ($date->year - $start->year) * 12 + ($date->month - $start->month);

        return round($wholeMonths + ($date->day - 1) / $date->daysInMonth, 4);
    }

    /**
     * Where one tranche stands, in the shared vocabulary of every funding page.
     *
     * @return 'received'|'failed'|'transit'|'late'|'upcoming'
     */
    protected function trancheState(TransfersTracking $transfer): string
    {
        return $transfer->state();
    }

    /**
     * A whole percentage of $of, floored so nothing is claimed early, but
     * never shown as 0 while there is something to count.
     */
    protected function percentLabel(float $value, float $of): string
    {
        return DonorProposal::percentLabel($value, $of);
    }

    /**
     * Money on one agreement, split the way its lane draws it.
     *
     * Only tranches in the agreement's own currency count, as on the agreement
     * page. Received is net money that landed in that currency; a received
     * tranche with no net recorded counts as nothing landed and is reported
     * beside it, as is anything that landed in another currency.
     *
     * @return array{received: float, pending: float, unscheduled: float, share: int, percentLabel: string, missingNet: int, otherCurrencies: array<string, float>, overdueSince: ?CarbonImmutable, overdueAmount: float, failedAmount: float, nextDue: ?CarbonImmutable, accounts: list<string>}
     */
    protected function agreementMoney(DonorProposal $agreement): array
    {
        $currency = $agreement->currency;
        $budget = (float) $agreement->budget;
        $sameCurrency = $agreement->transfers->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_transfered_currency === $currency);
        $received = $sameCurrency->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === 'received');
        $recorded = $received->reject(fn (TransfersTracking $transfer): bool => $transfer->isMissingNetAmount());
        $late = $agreement->transfers->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === 'late');

        $receivedHere = (float) $recorded
            ->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_received_currency === $currency)
            ->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received);
        $pendingHere = (float) $sameCurrency
            ->filter(fn (TransfersTracking $transfer): bool => in_array($transfer->state(), ['transit', 'late', 'upcoming'], true))
            ->sum(fn (TransfersTracking $transfer): float => (float) $transfer->payment_amount);

        return [
            'received' => $receivedHere,
            'pending' => $pendingHere,
            'unscheduled' => max(0, $budget - (float) $sameCurrency->sum(fn (TransfersTracking $transfer): float => (float) $transfer->payment_amount)),
            'share' => $budget > 0 ? (int) floor(min(1, $receivedHere / $budget) * 100) : 0,
            'percentLabel' => $this->percentLabel($receivedHere, $budget),
            'missingNet' => $received->filter(fn (TransfersTracking $transfer): bool => $transfer->isMissingNetAmount())->count(),
            'otherCurrencies' => $recorded
                ->reject(fn (TransfersTracking $transfer): bool => $transfer->amount_received_currency === $currency)
                ->groupBy(fn (TransfersTracking $transfer): string => $transfer->amount_received_currency->value)
                ->map(fn (Collection $group): float => (float) $group->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received))
                ->all(),
            'overdueSince' => $late->min('scheduled_date'),
            'overdueAmount' => (float) $late->sum(fn (TransfersTracking $transfer): float => (float) $transfer->payment_amount),
            'failedAmount' => (float) $agreement->transfers
                ->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === 'failed')
                ->sum(fn (TransfersTracking $transfer): float => (float) $transfer->payment_amount),
            'nextDue' => $agreement->transfers
                ->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === 'upcoming' && $transfer->scheduled_date !== null)
                ->min('scheduled_date'),
            'accounts' => $agreement->transfers->pluck('account.account_name')->filter()->unique()->values()->all(),
        ];
    }

    /**
     * The filtered agreements joined to the records their rows name and to
     * their money, which is one grouped join rather than a loaded relation:
     * that is what lets a money column be sorted, totalled and exported.
     *
     * @return Builder<DonorProposal>
     */
    protected function registerQuery(): Builder
    {
        $today = CarbonImmutable::today()->toDateString();

        return $this->filteredQuery()
            ->join('donors', 'donors.id', '=', 'donor_proposal.donor_id')
            ->join('proposals', 'proposals.id', '=', 'donor_proposal.proposal_id')
            ->select('donor_proposal.*')
            ->withMoney($this->selectedYear(), $this->yearOnly())
            ->withCount(['reports as overdue_reports_count' => fn (Builder $query) => $query
                ->whereNull('submitted_on')
                ->whereDate('due_on', '<', $today)])
            ->withMin(['reports as next_report_due' => fn (Builder $query) => $query
                ->whereNull('submitted_on')
                ->whereDate('due_on', '>=', $today)], 'due_on');
    }

    /**
     * What each column heading sorts by. Amounts sort inside their currency.
     *
     * @return array<string, array{dir: 'asc'|'desc', order: list<string>}>
     */
    protected function registerSorts(): array
    {
        $lastById = 'donor_proposal.id desc';
        $money = fn (string $expression): array => ['dir' => 'desc', 'order' => ['donor_proposal.currency asc', "{$expression} {dir}", $lastById]];
        $sum = fn (string $column): string => "coalesce(money.{$column}, 0)";

        return [
            'agreement' => ['dir' => 'asc', 'order' => ['donors.name {dir}', 'proposals.name {dir}', $lastById]],
            'budget' => $money('donor_proposal.budget'),
            'scheduled' => $money($sum('m_scheduled')),
            'received' => $money($sum('m_received')),
            'percent' => ['dir' => 'desc', 'order' => ["(case when donor_proposal.budget > 0 then {$sum('m_received')} / donor_proposal.budget else 0 end) {dir}", $lastById]],
            'due_in_year' => $money($sum('m_due_in_year')),
            'received_in_year' => $money($sum('m_received_in_year')),
            'in_transit' => $money($sum('m_in_transit')),
            'overdue' => $money($sum('m_overdue')),
            'failed' => $money($sum('m_failed')),
            'unscheduled' => $money(AgreementMoney::UNSCHEDULED_SQL),
            'outstanding' => $money(AgreementMoney::OUTSTANDING_SQL),
            // The earliest unsent tranche leads, so anything overdue sits at the top.
            'next_due' => ['dir' => 'asc', 'order' => ['money.m_next_due is null', 'money.m_next_due {dir}', $lastById]],
            'tranches' => ['dir' => 'desc', 'order' => ["{$sum('m_tranches')} {dir}", $lastById]],
        ];
    }

    protected function registerDefaultSort(): string
    {
        return 'next_due';
    }

    /**
     * The table's visible page.
     *
     * @return LengthAwarePaginator<int, DonorProposal>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        return $this->applyRegisterSort($this->registerQuery())
            ->with(['donor:id,slug,name', 'proposal:id,slug,name,status'])
            ->paginate($this->registerPerPage());
    }

    /**
     * The table's totals, one line per currency. They respect every filter,
     * status included, so they always add up to the rows shown.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function totals(): Collection
    {
        return $this->filteredQuery()
            ->joinMoney($this->selectedYear(), $this->yearOnly())
            ->toBase()
            ->selectRaw(AgreementMoney::totalsSelect())
            ->groupBy('donor_proposal.currency')
            ->orderBy('donor_proposal.currency')
            ->get()
            ->map(fn (object $row): array => AgreementMoney::line($row));
    }

    /**
     * Whether any agreement exists at all, so an empty page can tell "nothing
     * recorded yet" from "nothing matches".
     */
    #[Computed]
    public function hasAnyAgreement(): bool
    {
        return DonorProposal::query()->exists();
    }

    /**
     * Every agreement the filters match, in the table's order, as a CSV file.
     */
    public function exportCsv(): StreamedResponse
    {
        Gate::authorize('funding-agreements.view');

        $year = $this->selectedYear();

        $agreements = $this->applyRegisterSort($this->registerQuery())
            ->addSelect(['donors.name as donor_name', 'proposals.name as proposal_name', 'proposals.status as proposal_status'])
            ->cursor();

        $headings = [
            __('funding_agreements.donor'),
            __('common.proposal'),
            __('finance_tables.proposal_status'),
            __('finance_tables.reference'),
            __('common.currency'),
            __('finance_tables.budget'),
            __('finance_tables.scheduled'),
            __('finance_tables.received'),
            __('finance_tables.percent_received'),
            __('finance_tables.in_transit'),
            __('finance_tables.overdue'),
            __('finance_tables.failed'),
            __('finance_tables.not_scheduled'),
            __('finance_tables.outstanding'),
            __('finance_tables.next_due'),
            __('finance_tables.tranches'),
            __('finance_tables.reports_overdue'),
            __('finance_tables.next_report_due'),
            ...($year === null ? [] : [
                __('finance_tables.due_in_year', ['year' => $year]),
                __('finance_tables.received_in_year', ['year' => $year]),
            ]),
        ];

        $rows = (function () use ($agreements, $year) {
            foreach ($agreements as $agreement) {
                yield [
                    (string) $agreement->getAttribute('donor_name'),
                    (string) $agreement->getAttribute('proposal_name'),
                    ProposalStatus::from((string) $agreement->getAttribute('proposal_status'))->label(),
                    $agreement->external_project_number,
                    $agreement->currency->value,
                    CsvExport::amount($agreement->budget),
                    CsvExport::amount($agreement->money_scheduled),
                    CsvExport::amount($agreement->money_received),
                    DonorProposal::percentLabel((float) $agreement->money_received, (float) $agreement->budget),
                    CsvExport::amount($agreement->money_in_transit),
                    CsvExport::amount($agreement->money_overdue),
                    CsvExport::amount($agreement->money_failed),
                    CsvExport::amount($agreement->money_unscheduled),
                    CsvExport::amount($agreement->money_outstanding),
                    $agreement->money_next_due,
                    (int) $agreement->money_tranches,
                    (int) $agreement->overdue_reports_count,
                    $agreement->next_report_due,
                    ...($year === null ? [] : [
                        CsvExport::amount($agreement->money_due_in_year),
                        CsvExport::amount($agreement->money_received_in_year),
                    ]),
                ];
            }
        })();

        return CsvExport::download('funding-agreements-'.CarbonImmutable::today()->toDateString().'.csv', $headings, $rows);
    }

    public function openCreateModal(): void
    {
        $this->openAgreementCreate();
    }

    public function openEditModal(int $agreementId): void
    {
        $this->openAgreementEdit($agreementId);
    }

    protected function agreementSaved(DonorProposal $agreement, bool $isCreate): void
    {
        $this->resetPage();
    }

    protected function agreementDeleted(int $agreementId): void
    {
        $this->resetPage();
    }
};
?>


@php
    $showsOverview = $this->view === 'overview';
    $activeFilterCount = collect([$this->search, $this->donor, $this->currency, $this->stage, $this->flag, $this->min, $this->max, $this->year])
        ->filter(fn (string $value): bool => $value !== '')
        ->count();
    $total = $showsOverview ? $this->agreements->total() : $this->rows->total();

    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';

    /* `$chosen` is read instead of the properties so a loop variable can never shadow one. */
    $chosen = [
        'year' => $this->year,
        'figures' => $this->figures,
        'currency' => $this->currency,
        'stage' => $this->stage,
        'flag' => $this->flag,
    ];
    $selectedYear = $this->selectedYear();
    $yearOnly = $this->yearOnly();
    $sortKey = $this->activeSort();
    $sortDirection = $this->activeSortDir();
    $counts = $this->hasAnyAgreement && ! $showsOverview ? $this->stageCounts : [];

    $setPill = fn (string $property, string $value, string $label): array => [
        'label' => $label,
        'model' => $property, 'value' => $value, 'toggle' => true,
        'active' => $chosen[$property] === $value,
    ];
    $stagePill = fn (string $value, string $label, ?string $tone = null): array => [
        'label' => $label,
        'model' => 'stage', 'value' => $value, 'toggle' => true,
        'active' => $chosen['stage'] === $value,
        'count' => $counts[$value] ?? 0,
        'tone' => ($counts[$value] ?? 0) > 0 ? $tone : null,
        'test' => 'stage-'.$value,
    ];
    $flagPill = fn (string $value, string $label): array => [
        'label' => $label,
        'model' => 'flag', 'value' => $value, 'toggle' => true,
        'active' => $chosen['flag'] === $value,
        'test' => 'flag-'.$value,
    ];

    $yearPills = [
        ['default' => true, 'label' => __('finance_tables.all_years'), 'model' => 'year', 'value' => '', 'active' => $chosen['year'] === ''],
        ...array_map(fn (int $option): array => $setPill('year', (string) $option, (string) $option), $this->yearOptions),
    ];
    $figurePills = [
        ['default' => true, 'label' => __('finance_tables.figures_lifetime'), 'model' => 'figures', 'value' => 'lifetime', 'active' => $chosen['figures'] === 'lifetime'],
        ['label' => __('finance_tables.figures_year', ['year' => $chosen['year']]), 'model' => 'figures', 'value' => 'year', 'active' => $chosen['figures'] === 'year'],
    ];
    $statusPills = [
        ['default' => true, 'label' => __('finance_tables.all'), 'model' => 'stage', 'value' => '', 'active' => $chosen['stage'] === '', 'test' => 'stage-all'],
        $stagePill('overdue', __('funding_agreements.stage_overdue'), 'critical'),
        $stagePill('failed', __('funding_agreements.stage_failed'), 'critical'),
        $stagePill('in_transit', __('funding_agreements.stage_in_transit'), 'warn'),
        $stagePill('upcoming', __('funding_agreements.stage_upcoming')),
        $stagePill('unscheduled', __('funding_agreements.stage_unscheduled')),
        $stagePill('received', __('funding_agreements.stage_received')),
    ];
    $currencyPills = [
        ['default' => true, 'label' => __('finance_tables.all'), 'model' => 'currency', 'value' => '', 'active' => $chosen['currency'] === ''],
        ...array_map(fn (Currency $case): array => $setPill('currency', $case->value, $case->value), Currency::cases()),
    ];
    $flagPills = [
        $flagPill('report_overdue', __('finance_tables.flag_report_overdue')),
        $flagPill('net_missing', __('finance_tables.flag_unrecorded')),
        $flagPill('other_currency', __('finance_tables.flag_other_currency')),
        $flagPill('no_tranches', __('finance_tables.flag_no_tranches')),
    ];
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="agreement-directory-heading">
    <x-ui.page-header
        heading-id="agreement-directory-heading"
        :title="__('common.funding_agreements')"
        :count="$total"
    >
        <x-slot:actions>
            <div class="flex flex-wrap items-center justify-end gap-2">
                @if ($this->hasAnyAgreement)
                    <x-funding.filters :active="$activeFilterCount" :results="$total">
                        <div class="directory-filters grid gap-3 sm:grid-cols-2">
                            <x-ui.input type="search" icon="magnifying-glass" :label="__('funding_agreements.search_agreements')" :placeholder="__('common.reference_donor_or_proposal')" wire:model="search" clearable />

                            <x-ui.header-filter-select deferred :label="__('funding_agreements.donor')" wire:model="donor" :value="$this->donor">
                                <flux:select.option value="">{{ __('funding_agreements.all_donors') }}</flux:select.option>
                                @foreach ($this->donorOptions as $donorOption)
                                    <flux:select.option :value="$donorOption->id" :wire:key="'agr-donor-'.$donorOption->id">{{ $donorOption->name }}</flux:select.option>
                                @endforeach
                            </x-ui.header-filter-select>
                        </div>

                        <x-funding.pills :label="__('finance_tables.year')" :pills="$yearPills" />

                        @if ($selectedYear !== null)
                            <x-funding.pills :label="__('finance_tables.figures_label')" :pills="$figurePills" />
                        @endif

                        {{-- The Overview view narrows by stage with its own chips, under its ledger. --}}
                        @unless ($showsOverview)
                            <x-funding.pills :label="__('common.status')" :pills="$statusPills" />
                        @endunless

                        <x-funding.pills :label="__('common.currency')" :pills="$currencyPills" />
                        <x-funding.pills :label="__('finance_tables.flags')" :pills="$flagPills" />

                        <x-funding.filter-row :label="__('finance_tables.budget')" :on="$this->min !== '' || $this->max !== ''" :watch="['min', 'max']">
                            <x-ui.input type="number" inputmode="decimal" min="0" step="0.01" size="sm" class="w-36" wire:model="min" :placeholder="__('finance_tables.minimum')" :aria-label="__('finance_tables.minimum')" />
                            <span class="text-xs font-semibold {{ $mutedText }}">{{ __('finance_tables.to') }}</span>
                            <x-ui.input type="number" inputmode="decimal" min="0" step="0.01" size="sm" class="w-36" wire:model="max" :placeholder="__('finance_tables.maximum')" :aria-label="__('finance_tables.maximum')" />
                        </x-funding.filter-row>
                    </x-funding.filters>
                @endif

                <x-ui.view-toggle
                    :label="__('finance_tables.view_label')"
                    wire-model="view"
                    :active="$this->view"
                    :options="[
                        ['key' => 'table', 'label' => __('finance_tables.view_table'), 'icon' => 'table-cells'],
                        ['key' => 'overview', 'label' => __('finance_tables.view_overview'), 'icon' => 'chart-bar'],
                    ]"
                />

                @if ($this->hasAnyAgreement)
                    <x-ui.button icon="arrow-down-tray" class="page-action" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" data-test="export-csv">
                        {{ __('finance_tables.export_csv') }}
                    </x-ui.button>
                @endif

                @can('funding-agreements.create')
                <x-ui.button tone="primary" icon="plus" class="page-action page-action-primary" wire:click="openCreateModal" data-test="create-agreement-button">
                    {{ __('funding_agreements.record_agreement') }}
                </x-ui.button>
                @endcan
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $this->hasAnyAgreement)
        <x-ui.empty-state icon="banknotes" :heading="__('common.no_agreements_yet')" :body="__('funding_agreements.record_the_first_funding_agreement')" />
    @elseif ($showsOverview)
        @include('pages.funding-agreements._overview')
    @elseif ($this->rows->isEmpty())
        <x-ui.empty-state icon="banknotes" :heading="__('funding_agreements.no_matching_agreements')" :body="__('common.change_the_search_or_filters_and_try_again')" />
    @else
        @php
            $totals = $this->totals;
            // A column nobody has money in is noise: failed money earns its column only when there is some.
            $showFailed = $totals->sum('failed') > 0 || $chosen['stage'] === 'failed';
            $columns = AgreementMoney::columns(year: $selectedYear, yearOnly: $yearOnly, withFailed: $showFailed);

            $totalLines = $totals->map(fn (array $line): array => [
                'currency' => $line['currency'],
                'caption' => trans_choice('common.count_agreement', $line['agreements'], ['count' => $line['agreements']]),
                'figures' => array_values(array_filter([
                    ['key' => 'budget', 'label' => $yearOnly ? __('finance_tables.budget_whole_agreement') : __('finance_tables.budget'), 'value' => $line['budget']],
                    ['key' => 'scheduled', 'label' => __('finance_tables.scheduled'), 'value' => $line['scheduled']],
                    ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $line['received'], 'tone' => 'good', 'note' => DonorProposal::percentLabel($line['received'], $yearOnly ? $line['scheduled'] : $line['budget']).'%'],
                    $selectedYear !== null && ! $yearOnly ? ['key' => 'due_in_year', 'label' => __('finance_tables.due_in_year', ['year' => $selectedYear]), 'value' => $line['due_in_year']] : null,
                    $selectedYear !== null && ! $yearOnly ? ['key' => 'received_in_year', 'label' => __('finance_tables.received_in_year', ['year' => $selectedYear]), 'value' => $line['received_in_year'], 'tone' => 'good'] : null,
                    ['key' => 'in_transit', 'label' => __('finance_tables.in_transit'), 'value' => $line['in_transit'], 'tone' => $line['in_transit'] > 0 ? 'warn' : null],
                    ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $line['overdue'], 'tone' => $line['overdue'] > 0 ? 'critical' : null],
                    $line['failed'] > 0 ? ['key' => 'failed', 'label' => __('finance_tables.failed'), 'value' => $line['failed'], 'tone' => 'critical'] : null,
                    $yearOnly ? null : ['key' => 'unscheduled', 'label' => __('finance_tables.not_scheduled'), 'value' => $line['unscheduled'], 'tone' => 'muted'],
                    $yearOnly ? null : ['key' => 'outstanding', 'label' => __('finance_tables.outstanding'), 'value' => $line['outstanding']],
                ])),
            ])->all();
        @endphp

        <x-funding.totals :lines="$totalLines" />

        <x-funding.register size="2xl" :caption="__('finance_tables.agreements_caption')">
            <x-slot:head>
                <x-funding.register.th sort="agreement" :active="$sortKey" :dir="$sortDirection">{{ __('finance_tables.agreement') }}</x-funding.register.th>
                <x-funding.agreement-head :columns="$columns" sortable :active="$sortKey" :dir="$sortDirection" />
            </x-slot:head>

            @foreach ($this->rows as $agreement)
                @php
                    $agreementName = $agreement->donor->name.' · '.$agreement->proposal->name;
                @endphp
                <x-funding.agreement-row :agreement="$agreement" :columns="$columns" :year-only="$yearOnly">
                    <x-slot:lead>
                        <td data-label="{{ __('finance_tables.agreement') }}" data-wide>
                            @can('donors.view')
                                <a href="{{ route('donors.show', $agreement->donor) }}" wire:navigate class="font-semibold underline-offset-2 hover:underline">{{ $agreement->donor->name }}</a>
                            @else
                                <span class="font-semibold">{{ $agreement->donor->name }}</span>
                            @endcan
                            <span class="sub">
                                @can('proposals.view')
                                    <a href="{{ route('proposals.show', $agreement->proposal) }}" wire:navigate class="underline-offset-2 hover:underline">{{ $agreement->proposal->name }}</a>
                                @else
                                    {{ $agreement->proposal->name }}
                                @endcan
                                · {{ $agreement->proposal->status->label() }}
                            </span>
                            <span class="sub">{{ $agreement->external_project_number ?? __('common.no_external_project_number') }}</span>
                        </td>
                    </x-slot:lead>

                    <x-slot:actions>
                        {{-- No delete here: an agreement is deleted from its edit form, and only while nothing is filed under it. --}}
                        <td>
                            <span class="flex items-center justify-end">
                                <x-ui.button tone="ghost" size="sm" icon="eye" icon:variant="outline" class="size-8!" :href="route('funding-agreements.show', $agreement)" wire:navigate :aria-label="__('common.view_name', ['name' => $agreementName])" />
                                @can('funding-agreements.update')
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" icon:variant="outline" class="size-8!" wire:click="openEditModal({{ $agreement->id }})" :aria-label="__('common.edit_name', ['name' => $agreementName])" data-test="edit-agreement-{{ $agreement->id }}" />
                                @endcan
                            </span>
                        </td>
                    </x-slot:actions>
                </x-funding.agreement-row>
            @endforeach
        </x-funding.register>

        <x-funding.pager :paginator="$this->rows" :per-page="(string) $this->registerPerPage()" :sizes="$this->registerPageSizes()" scroll-to="#agreement-directory-heading" />
    @endif

    <x-funding.agreement-modal
        :open="$showAgreementModal"
        :editing="$editingAgreementId !== null"
        :donor-options="$showAgreementModal ? $this->donorOptions : []"
        :warning="$showAgreementModal ? $this->agreementProposalWarning : null"
        :delete-blocker="$showAgreementModal ? $this->agreementDeleteBlocker : null"
        :locked-currency="$showAgreementModal && $this->agreementCurrencyLocked ? $agreementCurrency : null"
        :confirming-delete="$confirmingAgreementDelete"
        :proposal-options="$showAgreementModal ? $this->proposalOptions : []"
    />
</section>
