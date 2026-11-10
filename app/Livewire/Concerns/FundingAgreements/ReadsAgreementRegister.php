<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\FundingAgreements;

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\DonorProposal;
use App\Models\Strategy;
use App\Models\TransfersTracking;
use App\Support\AgreementHealth;
use App\Support\AgreementMoney;
use App\Support\Like;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * What the funding agreements register reads from the database: the filter
 * and stage SQL, the Overview's agreement lanes, the table's joined-money
 * query with its sorts, rows and totals, and the per-stage counts.
 *
 * Used by funding-agreements/index only. The page provides the filter
 * properties and the STAGES constant. It reads FiltersFinanceRegister
 * (selectedYear(), yearOnly(), applyRegisterSort(), registerPerPage()), whose
 * abstract registerSorts() and registerDefaultSort() this trait implements.
 * The ledger and calendar built on top of filteredQuery() live in
 * ChartsAgreementCalendar; exportCsv() is in ExportsAgreementRegister.
 *
 * @property string $search
 * @property string $donor
 * @property string $currency
 * @property string $stage
 * @property string $health
 * @property string $flag
 * @property string $min
 * @property string $max
 * @property-read Strategy|null $strategyFilter  #[Computed] FiltersByStrategy::strategyFilter()
 * @property-read LengthAwarePaginator<int, DonorProposal> $agreements  #[Computed] agreements()
 * @property-read LengthAwarePaginator<int, DonorProposal> $rows  #[Computed] rows()
 * @property-read Collection<int, array{currency: string, agreements: int, tranches: int, budget: float, scheduled: float, received: float, in_transit: float, overdue: float, failed: float, unscheduled: float, outstanding: float, due_in_year: float, received_in_year: float}> $totals  #[Computed] totals()
 * @property-read bool $hasAnyAgreement  #[Computed] hasAnyAgreement()
 * @property-read array<string, int> $stageCounts  #[Computed] stageCounts()
 * @property-read array<string, int> $healthCounts  #[Computed] healthCounts()
 */
trait ReadsAgreementRegister
{
    /**
     * The three states as a sortable rank, reading the counts registerQuery()
     * selects: 0 wants a person, 1 is running, 2 has not started.
     */
    private const URGENCY_RANK_SQL = '(case when late_transfers_count > 0 or failed_transfers_count > 0 or overdue_reports_count > 0 then 0 when coalesce(money.m_tranches, 0) > 0 then 1 else 2 end)';

    /**
     * Agreements matching every active filter.
     *
     * The ledger and the stage chips pass $withStage false so they keep
     * showing the whole picture while the stage filter narrows the lanes.
     *
     * @return Builder<DonorProposal>
     */
    protected function filteredQuery(bool $withStage = true, bool $withHealth = true, bool $withFlag = true): Builder
    {
        $search = trim($this->search);

        return $this->whereProposalInStrategy(DonorProposal::query(), 'donor_proposal.proposal_id')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(Like::everyWord($search, fn (Builder $query, string $word) => $query
                    ->where(Like::word('external_project_number', $word))
                    ->orWhereHas('donor', Like::word('name', $word))
                    ->orWhereHas('proposal', fn (Builder $query) => $query
                        ->where(Like::word('name', $word))
                        ->orWhere(Like::word('internal_project_number', $word)))));
            })
            ->when($this->donor !== '', fn (Builder $query) => $query->where('donor_proposal.donor_id', (int) $this->donor))
            ->when(Currency::tryFrom($this->currency), fn (Builder $query, Currency $currency) => $query->where('currency', $currency))
            ->when($this->min !== '', fn (Builder $query) => $query->where('donor_proposal.budget', '>=', (float) $this->min))
            ->when($this->max !== '', fn (Builder $query) => $query->where('donor_proposal.budget', '<=', (float) $this->max))
            ->when($withFlag && $this->flag !== '', fn (Builder $query) => $this->applyFlag($query, $this->flag))
            ->when($this->selectedYear() !== null, fn (Builder $query) => $this->applyYear($query))
            ->when($withHealth && in_array($this->health, AgreementHealth::CASES, true), fn (Builder $query) => $this->applyHealth($query, $this->health))
            ->when($withStage && in_array($this->stage, self::STAGES, true), fn (Builder $query) => $this->applyStage($query, $this->stage));
    }

    /**
     * Narrow a query to one of the three states a person reads an agreement
     * in. The three are disjoint and cover every agreement.
     *
     * @param  Builder<DonorProposal>  $query
     * @return Builder<DonorProposal>
     */
    protected function applyHealth(Builder $query, string $health): Builder
    {
        return match ($health) {
            AgreementHealth::ATTENTION => $query->where(fn (Builder $query) => $this->whereNeedsAttention($query)),
            AgreementHealth::MOVING => $query
                ->has('transfers')
                ->whereNot(fn (Builder $query) => $this->whereNeedsAttention($query)),
            AgreementHealth::NOT_STARTED => $query->doesntHave('transfers'),
            default => $query,
        };
    }

    /**
     * The one definition of "wants a person today": a tranche past its date
     * and unsent, a tranche that failed, or an unsubmitted donor report past
     * its due date. AgreementHealth::needsAttention() reads the same three
     * facts off the selected counts.
     *
     * @param  Builder<DonorProposal>  $query
     * @return Builder<DonorProposal>
     */
    protected function whereNeedsAttention(Builder $query): Builder
    {
        $today = CarbonImmutable::today()->toDateString();

        return $query
            ->whereHas('transfers', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('ar_status', ArStatus::Failed)
                ->orWhere(fn (Builder $query) => $query
                    ->where('ar_status', ArStatus::Pending)
                    ->whereNull('transfer_date')
                    ->whereDate('scheduled_date', '<', $today))))
            ->orWhereHas('reports', fn (Builder $query) => $query
                ->whereNull('submitted_on')
                ->whereDate('due_on', '<', $today));
    }

    /**
     * The counts behind the three headline figures, each ignoring the health
     * filter itself so pressing one never empties the other two.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function healthCounts(): array
    {
        return collect(AgreementHealth::CASES)
            ->mapWithKeys(fn (string $health): array => [
                $health => $this->applyHealth($this->filteredQuery(withHealth: false), $health)->count(),
            ])
            ->all();
    }

    /**
     * The two counts a row's status word is read from, alongside the
     * overdue_reports_count every register query already selects.
     *
     * @param  Builder<DonorProposal>  $query
     * @return Builder<DonorProposal>
     */
    protected function withHealthCounts(Builder $query): Builder
    {
        $today = CarbonImmutable::today()->toDateString();

        return $query->withCount([
            'transfers as late_transfers_count' => fn (Builder $query) => $query
                ->where('ar_status', ArStatus::Pending)
                ->whereNull('transfer_date')
                ->whereDate('scheduled_date', '<', $today),
            'transfers as failed_transfers_count' => fn (Builder $query) => $query
                ->where('ar_status', ArStatus::Failed),
        ]);
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

        $query = $this->filteredQuery()
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
                ->whereDate('due_on', '>=', $today)], 'due_on');

        return $this->withHealthCounts($query)
            ->orderByRaw('next_due is null')
            ->orderBy('next_due')
            ->orderByDesc('id')
            ->paginate(15);
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
     * How many matching agreements carry each flag, ignoring the flag filter
     * itself, so a flag that would return nothing can say so before it is
     * pressed.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function flagCounts(): array
    {
        return collect(self::FLAGS)
            ->mapWithKeys(fn (string $flag): array => [
                $flag => $this->applyFlag($this->filteredQuery(withFlag: false), $flag)->count(),
            ])
            ->all();
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

        $query = $this->filteredQuery()
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

        return $this->withHealthCounts($query);
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
            // Not a column heading: the opening order, what needs a person first.
            'urgency' => ['dir' => 'asc', 'order' => [
                self::URGENCY_RANK_SQL.' {dir}',
                'money.m_next_due is null',
                'money.m_next_due asc',
                'donors.name asc',
                $lastById,
            ]],
            'agreement' => ['dir' => 'asc', 'order' => ['donors.name {dir}', 'proposals.name {dir}', $lastById]],
            'budget' => $money('donor_proposal.budget'),
            // The register's own two columns carry the budget and the received
            // share, so each sorts by the figure it prints.
            'amount' => $money('donor_proposal.budget'),
            'received_share' => $money($sum('m_received')),
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
        return 'urgency';
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
     * @return Collection<int, array{currency: string, agreements: int, tranches: int, budget: float, scheduled: float, received: float, in_transit: float, overdue: float, failed: float, unscheduled: float, outstanding: float, due_in_year: float, received_in_year: float}>
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
}
