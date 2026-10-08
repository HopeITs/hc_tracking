<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Enums\ArStatus;
use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\Donor;
use App\Models\Proposal;
use App\Models\Scopes\TaskVisibilityScope;
use App\Notifications\UnreadSubjects;
use App\Support\AgreementMoney;
use App\Support\Like;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * What the proposals register reads: the one filtered query every figure
 * shares, the card list the Overview view pages through, and the joined
 * money table with its sorts, totals and header count.
 *
 * Used by proposals/index only. The page keeps the #[Url] filter properties
 * and uses ChecksFinanceVisibility, FiltersFinanceRegister (selectedYear,
 * yearOnly, applyRegisterSort, registerPerPage) and WithPagination
 * (resetPage). registerSorts() and registerDefaultSort() are that trait's
 * abstract hooks, answered here. ChartsProposalPipeline and
 * ExportsProposalRegister read filteredQuery(), filteredProposalIds() and
 * joinedQuery() from here.
 *
 * @property string $search
 * @property string $status
 * @property string $phase
 * @property string $donor
 * @property string $currency
 * @property string $flag
 */
trait ReadsProposalRegister
{
    /**
     * The donors that hold an agreement, for the donor picker. Empty for a
     * viewer who may not see agreements: a donor's name next to a proposal is
     * what that permission protects.
     *
     * @return EloquentCollection<int, Donor>
     */
    #[Computed]
    public function filterDonors(): EloquentCollection
    {
        return $this->canViewAgreements()
            ? Donor::query()->has('donorProposals')->orderBy('name')->orderBy('id')->get(['id', 'name'])
            : new EloquentCollection;
    }

    /**
     * Toggle the phase filter from the pipeline rail.
     */
    public function togglePhase(string $phase): void
    {
        $this->phase = $this->phase === $phase ? '' : (ProposalPhase::tryFrom($phase)?->value ?? '');
        $this->resetPage();
    }

    /**
     * Toggle the donor filter from a donor node in the funding flow.
     */
    public function toggleDonor(int $donorId): void
    {
        if (! $this->canViewAgreements()) {
            return;
        }

        $this->donor = $this->donor === (string) $donorId ? '' : (string) $donorId;
        $this->resetPage();
    }

    /**
     * Proposals matching every active filter.
     *
     * The pipeline rail passes $withPhase false so it can still count the
     * phases the phase filter is hiding; the rail is how that filter is chosen.
     *
     * @return Builder<Proposal>
     */
    protected function filteredQuery(bool $withPhase = true): Builder
    {
        $search = trim($this->search);
        $canAgreements = $this->canViewAgreements();
        $canTransfers = $canAgreements && $this->canViewTransfers();
        // The donor filter narrows by funding agreements, so it only applies to a viewer who may see them.
        $donorId = $canAgreements ? (int) $this->donor : 0;
        $today = now()->toDateString();

        return Proposal::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('proposals.name', 'like', Like::contains($search))
                        ->orWhere('proposals.internal_project_number', 'like', Like::contains($search))
                        ->orWhere('proposals.notes', 'like', Like::contains($search));
                });
            })
            ->when(ProposalStatus::tryFrom($this->status), fn (Builder $query, ProposalStatus $status) => $query->where('proposals.status', $status))
            // There is no phase column: a phase is a set of statuses, derived
            // from the same match that ProposalStatus::phase() uses.
            ->when(
                $withPhase && ProposalPhase::tryFrom($this->phase) !== null,
                fn (Builder $query) => $query->whereIn('proposals.status', ProposalPhase::from($this->phase)->statuses())
            )
            ->when($donorId > 0, fn (Builder $query) => $query->whereHas('donorProposals', fn (Builder $query) => $query->where('donor_id', $donorId)))
            // A money filter only applies to a viewer who may see that money: otherwise it would leak it one row at a time.
            ->when($this->currency !== '' && $canAgreements, fn (Builder $query) => $query->whereHas('donorProposals', fn (Builder $query) => $query->where('currency', $this->currency)))
            ->when($this->flag === 'no_agreement' && $canAgreements, fn (Builder $query) => $query->doesntHave('donorProposals'))
            ->when($this->flag === 'report_overdue' && $canAgreements, fn (Builder $query) => $query->whereHas('donorProposals.reports', fn (Builder $query) => $query->whereNull('submitted_on')->whereDate('due_on', '<', $today)))
            ->when($this->flag === 'overdue' && $canTransfers, fn (Builder $query) => $query->whereHas('donorProposals.transfers', fn (Builder $query) => $query->inState('late')))
            ->when($this->selectedYear() !== null && $canTransfers, fn (Builder $query) => $this->applyYear($query));
    }

    /**
     * Keep only proposals with money in the chosen year: a tranche due in it
     * or, while the lifetime figures are shown, one that landed in it.
     *
     * @param  Builder<Proposal>  $query
     * @return Builder<Proposal>
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

    /**
     * Records with unread notifications for me, for the row dots.
     *
     * @return list<int>
     */
    #[Computed]
    public function unreadIds(): array
    {
        return app(UnreadSubjects::class)->ids(auth()->user(), 'proposal');
    }

    /** @return LengthAwarePaginator<int, Proposal> */
    #[Computed]
    public function proposals(): LengthAwarePaginator
    {
        $today = now()->toDateString();
        $canTransfers = $this->canViewTransfers();

        $relations = [
            'projects' => fn ($query) => $query->select(['projects.id', 'projects.name', 'projects.status'])->orderBy('projects.name'),
            'statusNotes' => fn ($query) => $query->select(['id', 'proposal_id', 'status', 'context', 'date'])->orderByDesc('date')->orderByDesc('id')->limit(1),
        ];

        // Agreements, and the tranches behind them, load only for a viewer who may see them.
        if ($this->canViewAgreements()) {
            $relations['donorProposals'] = fn ($query) => $query->select(['id', 'proposal_id', 'donor_id', 'budget', 'currency', 'external_project_number'])->orderBy('id');
            $relations['donorProposals.donor'] = fn ($query) => $query->select(['id', 'name']);
            $relations['donorProposals.transfers'] = fn ($query) => $query
                ->select(['id', 'donor_proposal_id', 'ar_status', 'payment_amount', 'amount_transfered_currency', 'net_amount_received', 'amount_received_currency', 'scheduled_date', 'transfer_date'])
                ->when(! $canTransfers, fn ($query) => $query->whereRaw('1 = 0'));
        }

        return $this->filteredQuery()
            ->select(['id', 'name', 'internal_project_number', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'status', 'updated_at'])
            ->with($relations)
            // Task progress is organisation-wide: every viewer sees the same figures.
            ->withCount([
                'donorProposals',
                'tasks' => fn ($query) => $query->withoutGlobalScope(TaskVisibilityScope::class),
                'tasks as done_tasks_count' => fn ($query) => $query->withoutGlobalScope(TaskVisibilityScope::class)->where('status', TaskStatus::Done),
                'tasks as overdue_tasks_count' => fn ($query) => $query->withoutGlobalScope(TaskVisibilityScope::class)->where('status', '!=', TaskStatus::Done)->whereDate('due_date', '<', $today),
                'statusNotes',
                'planIndicators',
            ])
            ->orderByDesc('planned_date_begin')
            ->orderBy('id')
            ->paginate(15);
    }

    /** @return Collection<int, int> */
    #[Computed]
    public function filteredProposalIds(): Collection
    {
        return $this->filteredQuery()->pluck('id');
    }

    #[Computed]
    public function donorFilterName(): ?string
    {
        $donorId = $this->canViewAgreements() ? (int) $this->donor : 0;

        return $donorId > 0 ? Donor::query()->whereKey($donorId)->value('name') : null;
    }

    /**
     * The filtered proposals joined to their money, one line per proposal and
     * currency; a proposal with no agreement keeps a single line with none.
     *
     * A viewer who may not see agreements gets the proposals alone: the join
     * is never made. A viewer who may see agreements but not transfers gets
     * the join with every tranche figure zeroed at its source.
     *
     * @return Builder<Proposal>
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
            ->leftJoinSub(AgreementMoney::perOwner('proposal_id', $year, $yearOnly, $canTransfers), 'money', 'money.owner_id', '=', 'proposals.id')
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
        $tail = $canAgreements ? ['proposals.id asc', 'money.currency asc'] : ['proposals.id asc'];
        $date = fn (string $column, string $dir): array => ['dir' => $dir, 'order' => ["proposals.{$column} is null", "proposals.{$column} {dir}", ...$tail]];

        $sorts = [
            'proposal' => ['dir' => 'asc', 'order' => ['proposals.name {dir}', ...$tail]],
            'status' => ['dir' => 'asc', 'order' => ['proposals.status {dir}', 'proposals.name asc', ...$tail]],
            'planned_start' => $date('planned_date_begin', 'desc'),
            'planned_end' => $date('planned_date_end', 'desc'),
            'projects' => ['dir' => 'desc', 'order' => ['projects_count {dir}', 'proposals.name asc', ...$tail]],
        ];

        if (! $canAgreements) {
            return $sorts;
        }

        $byName = ['proposals.name asc', 'proposals.id asc'];
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
            'next_due' => ['dir' => 'asc', 'order' => ['money.o_next_due is null', 'money.o_next_due {dir}', ...$byName]],
        ];
    }

    protected function registerDefaultSort(): string
    {
        return 'planned_start';
    }

    /**
     * The columns every line carries, whoever is looking.
     *
     * @param  Builder<Proposal>  $query
     * @return Builder<Proposal>
     */
    protected function selectLine(Builder $query): Builder
    {
        $query
            ->select(['proposals.id', 'proposals.slug', 'proposals.name', 'proposals.planned_date_begin', 'proposals.planned_date_end', 'proposals.status'])
            ->withCount(['projects', 'donorProposals']);

        if ($this->canViewAgreements()) {
            $query->selectRaw(AgreementMoney::ownerSelect());
        }

        return $query;
    }

    /**
     * The table's visible page. The donors behind each proposal load only for
     * a viewer who may see agreements.
     *
     * @return LengthAwarePaginator<int, Proposal>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $query = $this->selectLine($this->joinedQuery());

        if ($this->canViewAgreements()) {
            $query->with([
                'donorProposals' => fn ($query) => $query->select(['id', 'proposal_id', 'donor_id', 'currency'])->orderBy('id'),
                'donorProposals.donor' => fn ($query) => $query->select(['id', 'slug', 'name']),
            ]);
        }

        return $this->applyRegisterSort($query)->paginate($this->registerPerPage());
    }

    /**
     * How many proposals the table holds: the header's count, which a
     * proposal with two currencies must not inflate.
     */
    #[Computed]
    public function proposalCount(): int
    {
        return $this->joinedQuery()->distinct()->count('proposals.id');
    }

    /**
     * The table's totals, one line per currency. Empty for a viewer who may
     * not see agreements.
     *
     * @return Collection<int, array{currency: string, proposals: int, agreements: int, committed: float, received: float, outstanding: float, overdue: float, due_in_year: float, received_in_year: float}>
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
            ->selectRaw(AgreementMoney::ownerTotalsSelect('proposals.id'))
            ->groupBy('money.currency')
            ->orderBy('money.currency')
            ->get()
            ->map(fn (object $row): array => [
                'currency' => (string) $row->currency,
                'proposals' => (int) $row->owners,
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
     * Whether any proposal exists at all, so an empty page can tell "nothing
     * recorded yet" from "nothing matches".
     */
    #[Computed]
    public function hasAnyProposal(): bool
    {
        return Proposal::query()->exists();
    }
}
