<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Donors;

use App\Enums\ArStatus;
use App\Enums\DonorType;
use App\Models\Donor;
use App\Models\TransfersTracking;
use App\Support\AgreementMoney;
use App\Support\Like;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * What the donors register reads from the database: the filtered donor query,
 * the Overview list's page, the table's joined money query with its sort
 * orders, the visible page, the header count and the totals.
 *
 * Used by donors/index only. The page provides the filter properties and the
 * UNCLASSIFIED constant, and uses WithPagination (toggleType() calls
 * resetPage()). It reads ChecksFinanceVisibility (canViewAgreements(),
 * canViewTransfers()) and FiltersFinanceRegister (selectedYear(), yearOnly(),
 * applyRegisterSort(), registerPerPage()). FiltersFinanceRegister in turn
 * calls registerSorts() and registerDefaultSort() from here. The portfolio
 * figures built on filteredDonorIds live in ChartsDonorPortfolio; exportCsv()
 * is in ExportsDonorRegister.
 *
 * @property string $search
 * @property string $type
 * @property string $relationship
 * @property string $sort
 * @property string $countryFilter
 * @property string $currency
 * @property string $flag
 */
trait ReadsDonorRegister
{
    /**
     * The countries donors are in, for the country picker.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function countryOptions(): array
    {
        return Donor::query()->whereNotNull('country')->where('country', '!=', '')->distinct()->orderBy('country')->pluck('country')->all();
    }

    /**
     * Select a donor type from the type rail, or clear it when already selected.
     */
    public function toggleType(string $type): void
    {
        $allowed = [...DonorType::values(), self::UNCLASSIFIED];

        $this->type = in_array($type, $allowed, true) && $this->type !== $type ? $type : '';
        $this->resetPage();
    }

    /**
     * The donor query narrowed by search and relationship, and by type unless asked not to.
     *
     * @return Builder<Donor>
     */
    protected function filteredQuery(bool $withType = true): Builder
    {
        $search = trim($this->search);

        return Donor::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('donors.name', 'like', Like::contains($search))
                        ->orWhere('donors.country', 'like', Like::contains($search))
                        ->orWhere('donors.contact_person', 'like', Like::contains($search))
                        ->orWhere('donors.email', 'like', Like::contains($search));
                });
            })
            ->when($this->relationship === 'funding', fn (Builder $query) => $query->has('donorProposals'))
            ->when($this->relationship === 'prospect', fn (Builder $query) => $query->doesntHave('donorProposals'))
            ->when($withType && $this->type === self::UNCLASSIFIED, fn (Builder $query) => $query->whereNull('donors.type'))
            ->when($withType && in_array($this->type, DonorType::values(), true), fn (Builder $query) => $query->where('donors.type', $this->type))
            ->when($this->countryFilter !== '', fn (Builder $query) => $query->where('donors.country', $this->countryFilter))
            ->when($this->flag === 'follow_up', fn (Builder $query) => $query->whereDate('donors.next_step_due_on', '<=', today()))
            // A money filter only applies to a viewer who may see that money: otherwise it would leak it one row at a time.
            ->when($this->currency !== '' && $this->canViewAgreements(), fn (Builder $query) => $query->whereHas('donorProposals', fn (Builder $query) => $query->where('currency', $this->currency)))
            ->when($this->flag === 'overdue' && $this->canViewTransfers(), fn (Builder $query) => $query->whereHas('donorProposals.transfers', fn (Builder $query) => $query->inState('late')))
            ->when($this->selectedYear() !== null && $this->canViewTransfers(), fn (Builder $query) => $this->applyYear($query));
    }

    /**
     * Keep only donors with money in the chosen year: a tranche due in it or,
     * while the lifetime figures are shown, one that landed in it.
     *
     * @param  Builder<Donor>  $query
     * @return Builder<Donor>
     */
    protected function applyYear(Builder $query): Builder
    {
        $bounds = AgreementMoney::yearBounds($this->selectedYear());
        $yearOnly = $this->yearOnly();

        return $query->whereHas('donorProposals.transfers', fn (Builder $query) => $query->where(fn (Builder $query) => $query
            ->whereBetween('scheduled_date', $bounds)
            ->when(! $yearOnly, fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query
                ->where('ar_status', ArStatus::Received)
                ->whereBetween('receive_date', $bounds)))));
    }

    /** @return LengthAwarePaginator<int, Donor> */
    #[Computed]
    public function donors(): LengthAwarePaginator
    {
        $canAgreements = $this->canViewAgreements();
        $canTransfers = $this->canViewTransfers();

        $nextDue = TransfersTracking::query()
            ->selectRaw('min(transfers_tracking.scheduled_date)')
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->whereColumn('donor_proposal.donor_id', 'donors.id')
            ->where('transfers_tracking.ar_status', ArStatus::Pending)
            // Money already sent is in transit; only unsent money falls due.
            ->whereNull('transfers_tracking.transfer_date');

        // Without the agreements permission the proposal names still load, but never the budget behind them.
        $relations = [
            'donorProposals' => fn ($query) => $canAgreements
                ? $query->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'external_project_number'])->orderByDesc('budget')
                : $query->select(['id', 'donor_id', 'proposal_id'])->orderBy('id'),
            'donorProposals.proposal' => fn ($query) => $query->select(['id', 'name', 'status']),
            'donorProposals.proposal.projects' => fn ($query) => $query->select(['projects.id', 'projects.name']),
        ];

        if ($canAgreements) {
            $relations['donorProposals.transfers'] = fn ($query) => $query
                ->select(['id', 'donor_proposal_id', 'ar_status', 'payment_amount', 'amount_transfered_currency', 'net_amount_received', 'amount_received_currency', 'scheduled_date', 'transfer_date', 'receive_date'])
                ->when(! $canTransfers, fn ($query) => $query->whereRaw('1 = 0'));
        }

        return $this->filteredQuery()
            ->select(['donors.id', 'donors.name', 'donors.type', 'donors.contact_person', 'donors.email', 'donors.phone', 'donors.country', 'donors.next_step', 'donors.next_step_due_on', 'donors.last_contacted_on', 'donors.updated_at'])
            ->when($canTransfers, fn (Builder $query) => $query->selectSub($nextDue, 'next_due_date'))
            ->withCount('donorProposals')
            ->with($relations)
            ->when($this->sort === 'agreements', fn (Builder $query) => $query->orderByDesc('donor_proposals_count'))
            ->when($this->sort === 'next_due' && $canTransfers, fn (Builder $query) => $query->orderByRaw('next_due_date is null')->orderBy('next_due_date'))
            ->when($this->sort === 'follow_up', fn (Builder $query) => $query->orderByRaw('donors.next_step_due_on is null')->orderBy('donors.next_step_due_on'))
            ->orderBy('donors.name')
            ->orderBy('donors.id')
            ->paginate(12);
    }

    /**
     * Ids of every donor matching the current filters, across all pages.
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function filteredDonorIds(): Collection
    {
        return $this->filteredQuery()->pluck('donors.id');
    }

    /**
     * The filtered donors joined to their money, one line per donor and
     * currency; a donor holding no agreement keeps a single line with none.
     *
     * A viewer who may not see agreements gets the donors alone: the join is
     * never made, so no budget is ever queried for them. A viewer who may see
     * agreements but not transfers gets the join with every tranche figure
     * zeroed at its source.
     *
     * @return Builder<Donor>
     */
    protected function joinedQuery(): Builder
    {
        $query = $this->filteredQuery();

        if (! $this->canViewAgreements()) {
            return $query;
        }

        $canTransfers = $this->canViewTransfers();
        $year = $canTransfers ? $this->selectedYear() : null;
        $yearOnly = $canTransfers && $this->yearOnly();

        return $query
            ->leftJoinSub(AgreementMoney::perOwner('donor_id', $year, $yearOnly, $canTransfers), 'money', 'money.owner_id', '=', 'donors.id')
            ->when($this->currency !== '', fn (Builder $query) => $query->where('money.currency', $this->currency))
            // With a year chosen, a line with nothing in that year is not shown.
            ->when($year !== null && ! $yearOnly, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('money.o_due_in_year', '>', 0)
                ->orWhere('money.o_received_in_year', '>', 0)))
            ->when($yearOnly, fn (Builder $query) => $query->where('money.o_tranches', '>', 0));
    }

    /**
     * What each column heading sorts by. Money headings exist only for a
     * viewer who may see that money, and sort inside their currency.
     *
     * @return array<string, array{dir: 'asc'|'desc', order: list<string>}>
     */
    protected function registerSorts(): array
    {
        $canAgreements = $this->canViewAgreements();
        $byName = $canAgreements ? ['donors.name asc', 'money.currency asc', 'donors.id asc'] : ['donors.name asc', 'donors.id asc'];
        $date = fn (string $expression): array => ['dir' => 'asc', 'order' => ["{$expression} is null", "{$expression} {dir}", ...$byName]];

        $sorts = [
            'donor' => ['dir' => 'asc', 'order' => $canAgreements ? ['donors.name {dir}', 'money.currency asc', 'donors.id asc'] : ['donors.name {dir}', 'donors.id asc']],
            'country' => ['dir' => 'asc', 'order' => ['donors.country is null', 'donors.country {dir}', ...$byName]],
            'follow_up' => $date('donors.next_step_due_on'),
        ];

        if (! $canAgreements) {
            return $sorts;
        }

        $money = fn (string $expression): array => ['dir' => 'desc', 'order' => ['money.currency is null', 'money.currency asc', "{$expression} {dir}", ...$byName]];
        $sum = fn (string $column): string => "coalesce(money.{$column}, 0)";

        $sorts['agreements'] = ['dir' => 'desc', 'order' => ["{$sum('o_agreements')} {dir}", ...$byName]];
        $sorts['committed'] = $money($sum('o_committed'));

        if (! $this->canViewTransfers()) {
            return $sorts;
        }

        return [
            ...$sorts,
            'received' => $money($sum('o_received')),
            'percent' => ['dir' => 'desc', 'order' => ["(case when {$sum('o_committed')} > 0 then {$sum('o_received')} / money.o_committed else 0 end) {dir}", ...$byName]],
            'due_in_year' => $money($sum('o_due_in_year')),
            'received_in_year' => $money($sum('o_received_in_year')),
            'outstanding' => $money($sum('o_outstanding')),
            'overdue' => $money($sum('o_overdue')),
            'next_transfer' => $date('money.o_next_due'),
            'last_received' => ['dir' => 'desc', 'order' => ['money.o_last_received is null', 'money.o_last_received {dir}', ...$byName]],
        ];
    }

    protected function registerDefaultSort(): string
    {
        return 'donor';
    }

    /**
     * The table's visible page.
     *
     * @return LengthAwarePaginator<int, Donor>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $query = $this->joinedQuery()->select('donors.*')->withCount('donorProposals');

        if ($this->canViewAgreements()) {
            $query->selectRaw(AgreementMoney::ownerSelect());
        }

        return $this->applyRegisterSort($query)->paginate($this->registerPerPage());
    }

    /**
     * How many donors the table holds: the header's count, which a donor with
     * two currencies must not inflate.
     */
    #[Computed]
    public function donorCount(): int
    {
        return $this->joinedQuery()->distinct()->count('donors.id');
    }

    /**
     * The table's totals, one line per currency. Empty for a viewer who may
     * not see agreements.
     *
     * @return Collection<int, array{currency: string, donors: int, agreements: int, committed: float, received: float, outstanding: float, overdue: float, due_in_year: float, received_in_year: float}>
     */
    #[Computed]
    public function totals(): Collection
    {
        if (! $this->canViewAgreements()) {
            return new Collection;
        }

        return $this->joinedQuery()
            ->toBase()
            ->whereNotNull('money.currency')
            ->selectRaw(AgreementMoney::ownerTotalsSelect('donors.id'))
            ->groupBy('money.currency')
            ->orderBy('money.currency')
            ->get()
            ->map(fn (object $row): array => [
                'currency' => (string) $row->currency,
                'donors' => (int) $row->owners,
                'agreements' => (int) $row->agreements,
                'committed' => (float) $row->committed,
                'received' => (float) $row->received,
                'outstanding' => (float) $row->outstanding,
                'overdue' => (float) $row->overdue,
                'due_in_year' => (float) $row->due_in_year,
                'received_in_year' => (float) $row->received_in_year,
            ]);
    }

    /**
     * Whether any donor exists at all, so an empty page can tell "nothing
     * recorded yet" from "nothing matches".
     */
    #[Computed]
    public function hasAnyDonor(): bool
    {
        return Donor::query()->exists();
    }
}
