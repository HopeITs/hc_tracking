<?php

use App\Support\AgreementMoney;
use App\Support\CsvExport;
use App\Support\Like;
use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Scopes\TaskVisibilityScope;
use App\Models\TransfersTracking;
use App\Notifications\UnreadSubjects;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('titles.proposals')] class extends Component {
    use FiltersFinanceRegister;
    use WithPagination;

    /** @var list<string> */
    public const FLAGS = ['no_agreement', 'overdue', 'report_overdue'];

    /**
     * Every property a filter control writes.
     *
     * @var list<string>
     */
    private const FILTERS = ['search', 'status', 'phase', 'donor', 'currency', 'flag'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $phase = '';

    #[Url(except: '')]
    public string $donor = '';

    #[Url(except: '')]
    public string $currency = '';

    #[Url(except: '')]
    public string $flag = '';

    public bool $showProposalModal = false;
    #[Locked]
    public ?int $editingProposalId = null;

    public bool $showDeleteModal = false;
    #[Locked]
    public ?int $deletingProposalId = null;

    public bool $showStageModal = false;
    #[Locked]
    public ?int $stageProposalId = null;
    public string $newStatus = '';
    public string $statusContext = '';
    public string $statusDate = '';

    public string $name = '';

    public string $internalProjectNumber = '';
    public string $plannedDateBegin = '';
    public string $plannedDateEnd = '';
    public string $actualDateBegin = '';
    public string $actualDateEnd = '';
    public string $proposalStatus = 'concept note';
    public string $notes = '';

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
        if (ProposalStatus::tryFrom($this->status) === null) {
            $this->status = '';
        }

        if (ProposalPhase::tryFrom($this->phase) === null) {
            $this->phase = '';
        }

        // The donor filter narrows by funding agreements, so it exists only for a viewer who may see them.
        if (! ctype_digit($this->donor) || ! $this->canViewAgreements()) {
            $this->donor = '';
        }

        if (Currency::tryFrom($this->currency) === null) {
            $this->currency = '';
        }

        if (! in_array($this->flag, self::FLAGS, true)) {
            $this->flag = '';
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'phase', 'donor', 'currency', 'flag']);
        $this->resetRegisterFilters();
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
     * Whether the viewer may see funding agreements and the budgets on them.
     */
    protected function canViewAgreements(): bool
    {
        return auth()->user()?->can('funding-agreements.view') ?? false;
    }

    /**
     * Whether the viewer may see transfers and the amounts sent and received.
     */
    protected function canViewTransfers(): bool
    {
        return auth()->user()?->can('transfers.view') ?? false;
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

    /**
     * Money committed, received and still pending, per currency, across every
     * proposal the filters match (not only the visible page).
     *
     * Same definitions as the dashboard's funding position: received is net
     * money in its received currency, pending is the scheduled payment in its
     * transfer currency. Currencies are never added together.
     *
     * @return Collection<int, array{currency: string, committed: float, received: float, pending: float, pendingCount: int, unscheduled: float}>
     */
    #[Computed]
    public function fundingPosition(): Collection
    {
        if (! $this->canViewAgreements()) {
            return new Collection;
        }

        $ids = $this->filteredProposalIds;
        $canTransfers = $this->canViewTransfers();

        $committed = DonorProposal::query()
            ->whereIn('proposal_id', $ids)
            ->selectRaw('currency, sum(budget) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $transfers = fn () => TransfersTracking::query()
            ->whereHas('donorProposal', fn (Builder $query) => $query->whereIn('proposal_id', $ids));

        $received = ! $canTransfers ? collect() : $transfers()
            ->where('ar_status', ArStatus::Received)
            ->selectRaw('amount_received_currency as currency, sum(net_amount_received) as total')
            ->groupBy('amount_received_currency')
            ->pluck('total', 'currency');

        $pending = ! $canTransfers ? collect() : $transfers()
            ->where('ar_status', ArStatus::Pending)
            ->selectRaw('amount_transfered_currency as currency, sum(payment_amount) as total, count(*) as transfers')
            ->groupBy('amount_transfered_currency')
            ->get()
            ->keyBy('currency');

        // What the tranches sent in each agreement's own currency already
        // cover of its budget, whatever became of them: fees, a missing net
        // amount or a failed transfer never turn money back into unscheduled.
        $scheduled = ! $canTransfers ? collect() : TransfersTracking::query()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->whereIn('donor_proposal.proposal_id', $ids)
            ->whereColumn('transfers_tracking.amount_transfered_currency', 'donor_proposal.currency')
            ->selectRaw('donor_proposal.currency as currency, sum(transfers_tracking.payment_amount) as total')
            ->groupBy('donor_proposal.currency')
            ->pluck('total', 'currency');

        return $committed->keys()
            ->concat($received->keys())
            ->concat($pending->keys())
            ->map(fn ($currency): string => (string) $currency)
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $currency) use ($committed, $received, $pending, $scheduled): array {
                $committedTotal = (float) ($committed[$currency] ?? 0);
                $receivedTotal = (float) ($received[$currency] ?? 0);
                $pendingTotal = (float) ($pending[$currency]->total ?? 0);

                return [
                    'currency' => $currency,
                    'committed' => $committedTotal,
                    'received' => $receivedTotal,
                    'pending' => $pendingTotal,
                    'pendingCount' => (int) ($pending[$currency]->transfers ?? 0),
                    'unscheduled' => max(0, $committedTotal - (float) ($scheduled[$currency] ?? 0)),
                ];
            });
    }

    /**
     * The five phases with how many proposals sit in each and what they hold.
     *
     * Ignores the phase filter itself so every stop stays selectable.
     *
     * @return list<array{phase: ProposalPhase, count: int, rejected: int, money: array<string, float>}>
     */
    #[Computed]
    public function pipeline(): array
    {
        $rows = ! $this->canViewAgreements() ? collect() : $this->filteredQuery(withPhase: false)
            ->leftJoin('donor_proposal', 'donor_proposal.proposal_id', '=', 'proposals.id')
            ->selectRaw('proposals.status, donor_proposal.currency, count(distinct proposals.id) as proposals, sum(donor_proposal.budget) as budget')
            ->groupBy('proposals.status', 'donor_proposal.currency')
            ->get();

        $counts = $this->filteredQuery(withPhase: false)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return array_map(function (ProposalPhase $phase) use ($rows, $counts): array {
            $statuses = array_map(fn (ProposalStatus $status): string => $status->value, $phase->statuses());

            $money = $rows
                ->filter(fn ($row): bool => in_array($row->status instanceof ProposalStatus ? $row->status->value : $row->status, $statuses, true) && $row->currency !== null)
                ->groupBy(fn ($row): string => (string) $row->currency)
                ->map(fn (Collection $group): float => (float) $group->sum('budget'))
                ->sortKeys()
                ->all();

            return [
                'phase' => $phase,
                'count' => (int) collect($statuses)->sum(fn (string $status): int => (int) ($counts[$status] ?? 0)),
                'rejected' => $phase === ProposalPhase::Implementation ? (int) ($counts[ProposalStatus::Rejected->value] ?? 0) : 0,
                'money' => $money,
            ];
        }, ProposalPhase::cases());
    }

    /**
     * The donor → proposal → project graph behind the funding flow.
     *
     * One graph per currency, because ribbon widths are budgets and budgets in
     * different currencies cannot share a scale. Proposal → project links carry
     * no money (the system does not split a budget across projects), so they
     * are drawn as threads, not ribbons.
     *
     * @return array{currencies: list<array{currency: string, total: float, donors: list<array{key: string, id: int, name: string, total: float, agreements: int}>, proposals: list<array{key: string, id: int, name: string, total: float, status: ProposalStatus}>, projects: list<array{key: string, id: int, name: string}>, graph: array{order: list<string>, money: list<array{from: string, to: string, amount: float}>, links: list<array{from: string, to: string}>}}>, unfunded: list<array{id: int, name: string}>}
     */
    #[Computed]
    public function flow(): array
    {
        if (! $this->canViewAgreements()) {
            return ['currencies' => [], 'unfunded' => []];
        }

        $proposals = Proposal::query()
            ->whereIn('id', $this->filteredProposalIds)
            ->select(['id', 'name', 'status', 'planned_date_begin'])
            ->with([
                'donorProposals:id,proposal_id,donor_id,budget,currency',
                'donorProposals.donor:id,name',
                'projects' => fn ($query) => $query->select(['projects.id', 'projects.name'])->orderBy('projects.name'),
            ])
            ->orderByDesc('planned_date_begin')
            ->orderBy('id')
            ->get();

        $currencies = $proposals
            ->flatMap(fn (Proposal $proposal) => $proposal->donorProposals->map(fn (DonorProposal $agreement): string => $agreement->currency->value))
            ->unique()
            ->sort()
            ->values();

        $graphs = $currencies->map(function (string $currency) use ($proposals): array {
            $agreements = $proposals->flatMap(fn (Proposal $proposal) => $proposal->donorProposals
                ->filter(fn (DonorProposal $agreement): bool => $agreement->currency->value === $currency));

            $donors = $agreements
                ->groupBy('donor_id')
                ->map(fn (Collection $group): array => [
                    'key' => 'd'.$group->first()->donor_id,
                    'id' => (int) $group->first()->donor_id,
                    'name' => $group->first()->donor->name,
                    'total' => (float) $group->sum('budget'),
                    'agreements' => $group->count(),
                ])
                ->sortByDesc('total')
                ->values();

            $funded = $proposals
                ->map(fn (Proposal $proposal): array => [
                    'key' => 'p'.$proposal->id,
                    'id' => $proposal->id,
                    'name' => $proposal->name,
                    'status' => $proposal->status,
                    'total' => (float) $proposal->donorProposals->filter(fn (DonorProposal $agreement): bool => $agreement->currency->value === $currency)->sum('budget'),
                    'projects' => $proposal->projects,
                ])
                ->filter(fn (array $proposal): bool => $proposal['total'] > 0)
                // Order proposals by their largest donor so ribbons cross as little as possible.
                ->sortBy(function (array $proposal) use ($donors, $agreements): int {
                    $mainDonor = $agreements->where('proposal_id', $proposal['id'])->sortByDesc('budget')->first()?->donor_id;

                    return (int) $donors->search(fn (array $donor): bool => $donor['id'] === $mainDonor);
                })
                ->values();

            // Each project sits at the average row of the proposals it is linked to, so threads cross as little as possible.
            $projects = $funded
                ->flatMap(fn (array $proposal, int $row) => $proposal['projects']->map(fn ($project): array => ['project' => $project, 'row' => $row]))
                ->groupBy(fn (array $link): int => $link['project']->id)
                ->map(fn (Collection $links): array => [
                    'key' => 'j'.$links->first()['project']->id,
                    'id' => $links->first()['project']->id,
                    'name' => $links->first()['project']->name,
                    'row' => $links->avg('row'),
                ])
                ->sortBy([['row', 'asc'], ['name', 'asc']])
                ->map(fn (array $project): array => collect($project)->except('row')->all())
                ->values();

            $money = $agreements
                ->filter(fn (DonorProposal $agreement): bool => $funded->contains('id', $agreement->proposal_id))
                ->groupBy(fn (DonorProposal $agreement): string => $agreement->donor_id.'-'.$agreement->proposal_id)
                ->map(fn (Collection $group): array => [
                    'from' => 'd'.$group->first()->donor_id,
                    'to' => 'p'.$group->first()->proposal_id,
                    'amount' => (float) $group->sum('budget'),
                ])
                ->values();

            $links = $funded->flatMap(fn (array $proposal) => $proposal['projects']->map(fn ($project): array => [
                'from' => 'p'.$proposal['id'],
                'to' => 'j'.$project->id,
            ]))->values();

            return [
                'currency' => $currency,
                'total' => (float) $agreements->sum('budget'),
                'donors' => $donors->all(),
                'proposals' => $funded->map(fn (array $proposal): array => collect($proposal)->except('projects')->all())->all(),
                'projects' => $projects->all(),
                'graph' => [
                    'order' => $donors->pluck('key')->concat($funded->pluck('key'))->concat($projects->pluck('key'))->all(),
                    'money' => $money->all(),
                    'links' => $links->all(),
                ],
            ];
        });

        return [
            'currencies' => $graphs->all(),
            'unfunded' => $proposals
                ->filter(fn (Proposal $proposal): bool => $proposal->donorProposals->isEmpty())
                ->map(fn (Proposal $proposal): array => ['id' => $proposal->id, 'name' => $proposal->name])
                ->values()
                ->all(),
        ];
    }

    #[Computed]
    public function donorFilterName(): ?string
    {
        $donorId = $this->canViewAgreements() ? (int) $this->donor : 0;

        return $donorId > 0 ? Donor::query()->whereKey($donorId)->value('name') : null;
    }

    /**
     * Money on one agreement, split the way its bar draws it.
     *
     * Only transfers in the agreement's own currency fill its bar; money that
     * arrived in another currency is reported beside it, never mixed in.
     *
     * @return array{received: float, pending: float, unscheduled: float, share: int, otherCurrencies: array<string, float>, nextDue: ?\Carbon\CarbonImmutable}
     */
    protected function agreementMoney(DonorProposal $agreement): array
    {
        $currency = $agreement->currency->value;
        $received = $agreement->transfers->where('ar_status', ArStatus::Received);
        $pending = $agreement->transfers->where('ar_status', ArStatus::Pending);

        $receivedHere = (float) $received->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_received_currency?->value === $currency)->sum('net_amount_received');
        $pendingHere = (float) $pending->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_transfered_currency?->value === $currency)->sum('payment_amount');
        $budget = (float) $agreement->budget;

        return [
            'received' => $receivedHere,
            'pending' => $pendingHere,
            // Every tranche sent in this currency covers part of the budget,
            // whatever became of it; fees and failures are not "unscheduled".
            'unscheduled' => max(0, $budget - (float) $agreement->transfers
                ->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_transfered_currency?->value === $currency)
                ->sum('payment_amount')),
            'share' => $budget > 0 ? (int) floor($receivedHere / $budget * 100) : 0,
            'otherCurrencies' => $received
                ->reject(fn (TransfersTracking $transfer): bool => $transfer->amount_received_currency?->value === $currency)
                ->groupBy(fn (TransfersTracking $transfer): string => (string) $transfer->amount_received_currency?->value)
                ->map(fn (Collection $group): float => (float) $group->sum('net_amount_received'))
                ->all(),
            // Only money still to be sent has a due date; a sent tranche is in transit.
            'nextDue' => $pending->whereNull('transfer_date')->whereNotNull('scheduled_date')->sortBy('scheduled_date')->first()?->scheduled_date,
        ];
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
     * @return Collection<int, array<string, mixed>>
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

    /**
     * Every line the filters match, in the table's order, as a CSV file. The
     * file holds the columns the viewer sees on screen and no others. The
     * donors of a line are named by one grouped subquery, so the rows can be
     * read lazily.
     */
    public function exportCsv(): StreamedResponse
    {
        Gate::authorize('proposals.view');

        $canAgreements = $this->canViewAgreements();
        $canTransfers = $canAgreements && $this->canViewTransfers();
        $year = $canTransfers ? $this->selectedYear() : null;

        $query = $this->selectLine($this->joinedQuery());

        if ($canAgreements) {
            $query->selectRaw("(select group_concat(distinct donors.name order by donors.name separator ', ')
                from donor_proposal as funding
                inner join donors on donors.id = funding.donor_id
                where funding.proposal_id = proposals.id and funding.currency = money.currency) as donor_names");
        }

        $proposals = $this->applyRegisterSort($query)->cursor();

        $headings = [
            __('common.proposal'),
            __('common.status'),
            __('proposals.phase'),
            __('proposals.planned_start'),
            __('proposals.planned_end'),
            __('common.projects'),
            ...($canAgreements ? [__('common.donors'), __('finance_tables.agreements'), __('common.currency'), __('common.committed')] : []),
            ...($canTransfers ? [
                __('finance_tables.received'),
                __('finance_tables.percent_received'),
                __('finance_tables.outstanding'),
                __('finance_tables.overdue'),
                __('finance_tables.next_due'),
            ] : []),
            ...($year === null ? [] : [
                __('finance_tables.due_in_year', ['year' => $year]),
                __('finance_tables.received_in_year', ['year' => $year]),
            ]),
        ];

        $rows = (function () use ($proposals, $canAgreements, $canTransfers, $year) {
            foreach ($proposals as $proposal) {
                $hasMoney = $canAgreements && $proposal->money_currency !== null;

                yield [
                    $proposal->name,
                    $proposal->status->label(),
                    $proposal->phase->label(),
                    $proposal->planned_date_begin,
                    $proposal->planned_date_end,
                    (int) $proposal->projects_count,
                    ...($canAgreements ? [
                        $proposal->getAttribute('donor_names'),
                        (int) $proposal->money_agreements,
                        $proposal->money_currency,
                        $hasMoney ? CsvExport::amount($proposal->money_committed) : null,
                    ] : []),
                    ...($canTransfers ? [
                        $hasMoney ? CsvExport::amount($proposal->money_received) : null,
                        $hasMoney ? DonorProposal::percentLabel((float) $proposal->money_received, (float) $proposal->money_committed) : null,
                        $hasMoney ? CsvExport::amount($proposal->money_outstanding) : null,
                        $hasMoney ? CsvExport::amount($proposal->money_overdue) : null,
                        $proposal->money_next_due,
                    ] : []),
                    ...($year === null ? [] : [
                        CsvExport::amount($proposal->money_due_in_year),
                        CsvExport::amount($proposal->money_received_in_year),
                    ]),
                ];
            }
        })();

        return CsvExport::download('proposals-'.CarbonImmutable::today()->toDateString().'.csv', $headings, $rows);
    }

    /**
     * Whether this proposal may be deleted.
     *
     * donor_proposal.proposal_id RESTRICTs on delete, deliberately: without it
     * one proposal delete would reach through the agreement into
     * transfers_tracking and erase every record of money received. The UI
     * refuses first so the user gets a reason instead of a query exception.
     */
    protected function canDelete(int $proposalId): bool
    {
        $proposal = Proposal::query()
            ->select(['id'])
            ->withCount('donorProposals')
            ->find($proposalId);

        return $proposal !== null && $proposal->donor_proposals_count === 0;
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', Proposal::class);

        $this->resetProposalForm();
        $this->showProposalModal = true;
    }

    public function openEditModal(int $proposalId): void
    {
        $proposal = Proposal::query()
            ->select(['id', 'name', 'internal_project_number', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'notes'])
            ->findOrFail($proposalId);

        Gate::authorize('update', $proposal);

        $this->resetValidation();
        $this->editingProposalId = $proposal->id;
        $this->name = $proposal->name;
        $this->internalProjectNumber = $proposal->internal_project_number;
        $this->plannedDateBegin = $proposal->planned_date_begin?->toDateString() ?? '';
        $this->plannedDateEnd = $proposal->planned_date_end?->toDateString() ?? '';
        $this->actualDateBegin = $proposal->actual_date_begin?->toDateString() ?? '';
        $this->actualDateEnd = $proposal->actual_date_end?->toDateString() ?? '';
        $this->notes = $proposal->notes ?? '';
        $this->showProposalModal = true;
    }

    public function saveProposal(): void
    {
        $this->name = trim($this->name);
        $this->internalProjectNumber = trim($this->internalProjectNumber);
        $this->notes = trim($this->notes);

        $storedNumber = $this->editingProposalId === null
            ? null
            : Proposal::query()->whereKey($this->editingProposalId)->value('internal_project_number');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'internalProjectNumber' => [
                'required',
                'string',
                'max:50',
                Rule::unique('proposals', 'internal_project_number')->ignore($this->editingProposalId),
                // TEMP- marks the placeholders the migration wrote. A proposal
                // still holding one saves unchanged, but nobody types a new one.
                function (string $attribute, mixed $value, Closure $fail) use ($storedNumber): void {
                    if (Proposal::isPlaceholderNumber((string) $value) && $value !== $storedNumber) {
                        $fail(__('proposals.internal_project_number_prefix_reserved'));
                    }
                },
            ],
            'plannedDateBegin' => ['required', 'date'],
            'plannedDateEnd' => ['required', 'date', 'after_or_equal:plannedDateBegin'],
            'actualDateBegin' => ['nullable', 'date'],
            'actualDateEnd' => ['nullable', 'date', 'after_or_equal:actualDateBegin'],
            'proposalStatus' => [$this->editingProposalId === null ? 'required' : 'nullable', new Enum(ProposalStatus::class)],
            'notes' => ['nullable', 'string'],
        ], [
            'name.required' => __('proposals.enter_a_proposal_name'),
            'plannedDateBegin.required' => __('proposals.set_the_planned_start_date'),
            'plannedDateEnd.required' => __('proposals.set_the_planned_end_date'),
            'plannedDateEnd.after_or_equal' => __('proposals.the_planned_end_cannot_fall_before_the_planned_start'),
            'actualDateEnd.after_or_equal' => __('proposals.the_actual_end_cannot_fall_before_the_actual_start'),
        ]);

        $proposal = $this->editingProposalId === null
            ? new Proposal
            : Proposal::query()->findOrFail($this->editingProposalId);

        Gate::authorize($proposal->exists ? 'update' : 'create', $proposal->exists ? $proposal : Proposal::class);

        // Status is only chosen when the proposal is first recorded. After that
        // it moves through recordStageChange(), which writes the audit note
        // alongside it, so the edit form never touches it.
        try {
            DB::transaction(function () use ($proposal, $validated): void {
                $proposal->fill([
                    'name' => $validated['name'],
                    'internal_project_number' => $validated['internalProjectNumber'],
                    'planned_date_begin' => $validated['plannedDateBegin'],
                    'planned_date_end' => $validated['plannedDateEnd'],
                    'actual_date_begin' => $validated['actualDateBegin'] === '' ? null : $validated['actualDateBegin'],
                    'actual_date_end' => $validated['actualDateEnd'] === '' ? null : $validated['actualDateEnd'],
                    'notes' => $validated['notes'] === '' ? null : $validated['notes'],
                ]);

                if (! $proposal->exists) {
                    $proposal->status = ProposalStatus::from($validated['proposalStatus']);
                }

                $proposal->save();

                if ($proposal->wasRecentlyCreated) {
                    ProposalStatusNote::query()->create([
                        'proposal_id' => $proposal->id,
                        'status' => $proposal->status,
                        'context' => __('proposals.proposal_recorded_note'),
                        'date' => now()->toDateString(),
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Two people saved the same new number at the same moment: both
            // passed validation and the unique index refused the second.
            if (! str_contains($exception->getMessage(), 'proposals_internal_project_number_unique')) {
                throw $exception;
            }

            $this->addError('internalProjectNumber', __('validation.unique', ['attribute' => __('validation.attributes.internalProjectNumber')]));

            return;
        }

        $wasRecentlyCreated = $proposal->wasRecentlyCreated;

        $this->showProposalModal = false;
        $this->resetProposalForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('proposals.proposal_created') : __('proposals.proposal_updated'),
        );
    }

    /**
     * Open the stage move for one proposal, preset to its next lifecycle stage.
     */
    public function openStageModal(int $proposalId): void
    {
        $proposal = Proposal::query()->select(['id', 'status'])->findOrFail($proposalId);

        Gate::authorize('update', $proposal);

        $this->resetValidation();
        $this->stageProposalId = $proposal->id;
        $this->newStatus = ($proposal->status->next() ?? $proposal->status)->value;
        $this->statusContext = '';
        $this->statusDate = now()->toDateString();
        $this->showStageModal = true;
    }

    /**
     * Move the proposal to a new status and record why.
     *
     * Both writes happen in one transaction, exactly as on the proposal page:
     * the live status and its audit trail must never disagree.
     */
    public function recordStageChange(): void
    {
        if ($this->stageProposalId === null) {
            return;
        }

        Gate::authorize('update', Proposal::query()->findOrFail($this->stageProposalId));

        $this->statusContext = trim($this->statusContext);

        $validated = $this->validate([
            'newStatus' => ['required', new Enum(ProposalStatus::class)],
            'statusContext' => ['required', 'string'],
            'statusDate' => ['required', 'date'],
        ], [
            'newStatus.required' => __('proposals.choose_the_new_status'),
            'statusContext.required' => __('proposals.record_why_the_status_changed'),
            'statusDate.required' => __('proposals.set_the_date_this_change_took_effect'),
        ]);

        DB::transaction(function () use ($validated): void {
            $proposal = Proposal::query()->findOrFail($this->stageProposalId);
            $proposal->fill(['status' => $validated['newStatus']])->save();

            ProposalStatusNote::query()->create([
                'proposal_id' => $proposal->id,
                'status' => $validated['newStatus'],
                'context' => $validated['statusContext'],
                'date' => $validated['statusDate'],
            ]);
        });

        $this->closeStageModal();

        Flux::toast(variant: 'success', text: __('proposals.status_changed_and_recorded'));
    }

    public function closeStageModal(): void
    {
        $this->resetValidation();
        $this->showStageModal = false;
        $this->stageProposalId = null;
        $this->statusContext = '';
    }

    public function confirmDelete(int $proposalId): void
    {
        Gate::authorize('delete', Proposal::query()->findOrFail($proposalId));

        $this->deletingProposalId = $proposalId;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->deletingProposalId = null;
        $this->showDeleteModal = false;
    }

    public function deleteProposal(): void
    {
        if ($this->deletingProposalId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingProposalId)) {
            $this->cancelDelete();

            Flux::toast(
                variant: 'danger',
                text: __('proposals.this_proposal_has_a_funding_agreement_remove_the_agreement_f'),
            );

            return;
        }

        $proposal = Proposal::query()->findOrFail($this->deletingProposalId);

        Gate::authorize('delete', $proposal);

        $proposal->delete();

        $this->cancelDelete();
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('proposals.proposal_deleted'));
    }

    public function closeProposalModal(): void
    {
        $this->showProposalModal = false;
        $this->resetProposalForm();
    }

    protected function resetProposalForm(): void
    {
        $this->resetValidation();
        $this->editingProposalId = null;
        $this->name = '';
        $this->internalProjectNumber = '';
        $this->plannedDateBegin = '';
        $this->plannedDateEnd = '';
        $this->actualDateBegin = '';
        $this->actualDateEnd = '';
        $this->proposalStatus = 'concept note';
        $this->notes = '';
    }
};
?>

@php
    $showsOverview = $this->view === 'overview';
    $canAgreements = $this->canViewAgreements();
    $canTransfers = $canAgreements && $this->canViewTransfers();
    $activeFilterCount = collect([$this->search, $this->status, $this->phase, $this->donor, $this->currency, $this->flag, $this->year])
        ->filter(fn (string $value): bool => $value !== '')
        ->count();
    $total = $showsOverview ? $this->proposals->total() : $this->proposalCount;

    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $receivedText = 'text-funding';
    $hairline = 'border-secondary-soft dark:border-line';

    /* The two closures below format the Overview view (_overview.blade.php). The table prints exact amounts itself. */
    $amount = fn (float $value): string => Number::format($value, maxPrecision: 0);
    $shortAmount = fn (float $value): string => Number::abbreviate($value, maxPrecision: 1);

    /* `$chosen` is read instead of the properties so a loop variable can never shadow one. */
    $chosen = [
        'year' => $this->year,
        'figures' => $this->figures,
        'status' => $this->status,
        'phase' => $this->phase,
        'currency' => $this->currency,
        'flag' => $this->flag,
    ];
    $selectedYear = $canTransfers ? $this->selectedYear() : null;
    $yearOnly = $canTransfers && $this->yearOnly();
    $sortKey = $this->activeSort();
    $sortDirection = $this->activeSortDir();

    $setPill = fn (string $property, string $value, string $label): array => [
        'label' => $label,
        'model' => $property, 'value' => $value, 'toggle' => true,
        'active' => $chosen[$property] === $value,
    ];

    $yearPills = [
        ['default' => true, 'label' => __('finance_tables.all_years'), 'model' => 'year', 'value' => '', 'active' => $chosen['year'] === ''],
        ...array_map(fn (int $option): array => $setPill('year', (string) $option, (string) $option), $canTransfers ? $this->yearOptions : []),
    ];
    $figurePills = [
        ['default' => true, 'label' => __('finance_tables.figures_lifetime'), 'model' => 'figures', 'value' => 'lifetime', 'active' => $chosen['figures'] === 'lifetime'],
        ['label' => __('finance_tables.figures_year', ['year' => $chosen['year']]), 'model' => 'figures', 'value' => 'year', 'active' => $chosen['figures'] === 'year'],
    ];
    $statusPills = [
        ['default' => true, 'label' => __('finance_tables.all'), 'model' => 'status', 'value' => '', 'active' => $chosen['status'] === ''],
        ...array_map(fn (ProposalStatus $case): array => $setPill('status', $case->value, $case->label()), ProposalStatus::cases()),
    ];
    /* The pipeline counts ignore the phase filter itself, so every phase stays countable. */
    $phasePills = [
        ['default' => true, 'label' => __('finance_tables.all'), 'model' => 'phase', 'value' => '', 'active' => $chosen['phase'] === ''],
        ...array_map(fn (array $stop): array => [
            'label' => $stop['phase']->label(),
            'model' => 'phase', 'value' => $stop['phase']->value, 'toggle' => true,
            'active' => $chosen['phase'] === $stop['phase']->value,
            'count' => $stop['count'],
            'test' => 'phase-'.$stop['phase']->value,
        ], $this->pipeline),
    ];
    $currencyPills = [
        ['default' => true, 'label' => __('finance_tables.all'), 'model' => 'currency', 'value' => '', 'active' => $chosen['currency'] === ''],
        ...array_map(fn (Currency $case): array => $setPill('currency', $case->value, $case->value), Currency::cases()),
    ];
    $flagPills = array_values(array_filter([
        ['label' => __('finance_tables.flag_no_agreement'), 'model' => 'flag', 'value' => 'no_agreement', 'toggle' => true, 'active' => $chosen['flag'] === 'no_agreement', 'test' => 'flag-no-agreement'],
        $canTransfers ? ['label' => __('finance_tables.flag_has_overdue'), 'model' => 'flag', 'value' => 'overdue', 'toggle' => true, 'active' => $chosen['flag'] === 'overdue', 'tone' => 'critical', 'test' => 'flag-overdue'] : null,
        ['label' => __('finance_tables.flag_report_overdue'), 'model' => 'flag', 'value' => 'report_overdue', 'toggle' => true, 'active' => $chosen['flag'] === 'report_overdue', 'test' => 'flag-report-overdue'],
    ]));
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="proposal-pipeline-heading">
    <x-ui.page-header
        heading-id="proposal-pipeline-heading"
        :title="__('common.proposals')"
        :count="$total"
    >
        <x-slot:actions>
            <div class="flex flex-wrap items-center justify-end gap-2">
                @if ($this->hasAnyProposal)
                    <x-funding.filters :active="$activeFilterCount" :results="$total">
                        <div class="directory-filters grid gap-3 sm:grid-cols-2">
                            <x-ui.input type="search" icon="magnifying-glass" :label="__('proposals.search_proposals')" :placeholder="__('proposals.name_or_notes')" wire:model="search" clearable />

                            @if ($canAgreements)
                                <x-ui.header-filter-select deferred :label="__('funding_agreements.donor')" wire:model="donor" :value="$this->donor">
                                    <flux:select.option value="">{{ __('funding_agreements.all_donors') }}</flux:select.option>
                                    @foreach ($this->filterDonors as $donorOption)
                                        <flux:select.option :value="$donorOption->id" :wire:key="'proposal-donor-'.$donorOption->id">{{ $donorOption->name }}</flux:select.option>
                                    @endforeach
                                </x-ui.header-filter-select>
                            @endif
                        </div>

                        {{-- A year narrows by tranches, so it is offered only to someone who may see them. --}}
                        @if ($canTransfers)
                            <x-funding.pills :label="__('finance_tables.year')" :pills="$yearPills" />

                            @if ($selectedYear !== null)
                                <x-funding.pills :label="__('finance_tables.figures_label')" :pills="$figurePills" />
                            @endif
                        @endif

                        <x-funding.pills :label="__('common.status')" :pills="$statusPills" />

                        {{-- The Overview view chooses the phase on its own pipeline rail. --}}
                        @unless ($showsOverview)
                            <x-funding.pills :label="__('proposals.phase')" :pills="$phasePills" />
                        @endunless

                        @if ($canAgreements)
                            <x-funding.pills :label="__('common.currency')" :pills="$currencyPills" />
                            <x-funding.pills :label="__('finance_tables.flags')" :pills="$flagPills" />
                        @endif

                        @if ($this->donorFilterName !== null)
                            <x-funding.filter-row :label="__('funding_agreements.donor')" on :watch="['donor']" :models="['donor']" x-show="filled(['donor'])">
                                <button type="button" x-on:click="pick('donor', '')" class="inline-flex min-h-8 items-center gap-1.5 rounded-full bg-funding-soft ps-3 pe-2 text-xs font-semibold text-funding transition-colors hover:bg-funding hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-ink dark:hover:text-surface-0" aria-label="{{ __('proposals.remove_donor_filter', ['name' => $this->donorFilterName]) }}" data-donor-chip>
                                    {{ __('proposals.funded_by', ['name' => $this->donorFilterName]) }}
                                    <flux:icon.x-mark variant="micro" class="size-3.5" />
                                </button>
                            </x-funding.filter-row>
                        @endif
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

                @if ($this->hasAnyProposal)
                    <x-ui.button tone="action" icon="arrow-down-tray" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" data-test="export-csv">
                        {{ __('finance_tables.export_csv') }}
                    </x-ui.button>
                @endif

                @can('create', App\Models\Proposal::class)
                <x-ui.button tone="primary" icon="plus" class="page-action page-action-primary" wire:click="openCreateModal" data-test="create-proposal-button">
                    {{ __('proposals.add_proposal') }}
                </x-ui.button>
                @endcan
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $this->hasAnyProposal)
        <x-ui.empty-state icon="document-text" :heading="__('proposals.no_proposals_yet')" :body="__('proposals.add_the_first_proposal_to_open_the_pipeline')" />
    @elseif ($showsOverview)
        @include('pages.proposals._overview')
    @elseif ($this->rows->isEmpty())
        <x-ui.empty-state icon="document-text" :heading="__('proposals.no_matching_proposals')" :body="__('common.change_the_search_or_filters_and_try_again')" />
    @else
        @php
            $totals = $this->totals;
            $money = fn (string|float|int|null $value): string => number_format((float) $value, 2);
            $date = fn (?\DateTimeInterface $value): string => $value?->translatedFormat(__('formats.date')) ?? '—';
            $today = \Carbon\CarbonImmutable::today();
            $showsYearColumns = $selectedYear !== null && ! $yearOnly;
            $unreadIds = $this->unreadIds;

            $totalLines = $totals->map(fn (array $line): array => [
                'currency' => $line['currency'],
                'caption' => trans_choice('finance_tables.count_proposals', $line['proposals'], ['count' => $line['proposals']]),
                'figures' => array_values(array_filter([
                    ['key' => 'committed', 'label' => __('common.committed'), 'value' => $line['committed']],
                    $canTransfers ? ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $line['received'], 'tone' => 'good', 'note' => DonorProposal::percentLabel($line['received'], $line['committed']).'%'] : null,
                    $showsYearColumns ? ['key' => 'due_in_year', 'label' => __('finance_tables.due_in_year', ['year' => $selectedYear]), 'value' => $line['due_in_year']] : null,
                    $showsYearColumns ? ['key' => 'received_in_year', 'label' => __('finance_tables.received_in_year', ['year' => $selectedYear]), 'value' => $line['received_in_year'], 'tone' => 'good'] : null,
                    $canTransfers && ! $yearOnly ? ['key' => 'outstanding', 'label' => __('finance_tables.outstanding'), 'value' => $line['outstanding']] : null,
                    $canTransfers ? ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $line['overdue'], 'tone' => $line['overdue'] > 0 ? 'critical' : null] : null,
                ])),
            ])->all();

            $previousProposalId = null;
        @endphp

        @if ($canAgreements)
            <x-funding.totals :lines="$totalLines" />
        @endif

        <x-funding.register :size="$canAgreements ? '2xl' : 'md'" :caption="__('finance_tables.proposals_caption')">
            <x-slot:head>
                <x-funding.register.th sort="proposal" :active="$sortKey" :dir="$sortDirection">{{ __('common.proposal') }}</x-funding.register.th>
                <x-funding.register.th sort="status" :active="$sortKey" :dir="$sortDirection">{{ __('common.status') }}</x-funding.register.th>
                <x-funding.register.th>{{ __('proposals.phase') }}</x-funding.register.th>
                <x-funding.register.th sort="planned_start" :active="$sortKey" :dir="$sortDirection">{{ __('proposals.planned_start') }}</x-funding.register.th>
                <x-funding.register.th sort="planned_end" :active="$sortKey" :dir="$sortDirection">{{ __('proposals.planned_end') }}</x-funding.register.th>
                <x-funding.register.th sort="projects" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('common.projects') }}</x-funding.register.th>
                @if ($canAgreements)
                    <x-funding.register.th>{{ __('common.donors') }}</x-funding.register.th>
                    <x-funding.register.th sort="agreements" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.agreements') }}</x-funding.register.th>
                    <x-funding.register.th>{{ __('common.currency') }}</x-funding.register.th>
                    <x-funding.register.th sort="committed" :active="$sortKey" :dir="$sortDirection" numeric>{{ $yearOnly ? __('finance_tables.committed_whole') : __('common.committed') }}</x-funding.register.th>
                @endif
                @if ($canTransfers)
                    <x-funding.register.th sort="received" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.received') }}</x-funding.register.th>
                    <x-funding.register.th sort="percent" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.percent_received') }}</x-funding.register.th>
                    @if ($showsYearColumns)
                        <x-funding.register.th sort="due_in_year" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.due_in_year', ['year' => $selectedYear]) }}</x-funding.register.th>
                        <x-funding.register.th sort="received_in_year" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}</x-funding.register.th>
                    @endif
                    @unless ($yearOnly)
                        <x-funding.register.th sort="outstanding" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.outstanding') }}</x-funding.register.th>
                    @endunless
                    <x-funding.register.th sort="overdue" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.overdue') }}</x-funding.register.th>
                    <x-funding.register.th sort="next_due" :active="$sortKey" :dir="$sortDirection">{{ __('finance_tables.next_due') }}</x-funding.register.th>
                @endif
                <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($this->rows as $proposal)
                @php
                    // A proposal funded in two currencies has two lines; its identity is printed on the first only.
                    $isRepeat = $previousProposalId === $proposal->id;
                    $previousProposalId = $proposal->id;
                    $lineCurrency = $canAgreements ? $proposal->money_currency : null;
                    $hasMoney = $lineCurrency !== null;
                    $lineAgreements = $hasMoney ? $proposal->donorProposals->filter(fn ($agreement): bool => $agreement->currency->value === $lineCurrency)->unique('donor_id') : collect();
                    $nextDue = $canTransfers && $hasMoney && $proposal->money_next_due !== null ? \Carbon\CarbonImmutable::parse($proposal->money_next_due) : null;
                    $overdue = $canTransfers && $hasMoney ? (float) $proposal->money_overdue : 0.0;
                @endphp
                <tr wire:key="proposal-{{ $proposal->id }}-{{ $lineCurrency ?? 'none' }}" data-proposal-row data-proposal="{{ $proposal->id }}">
                    <td data-label="{{ __('common.proposal') }}" data-wide>
                        @if (! $isRepeat && in_array($proposal->id, $unreadIds, true))
                            <span class="me-1 inline-block size-2 rounded-full bg-primary align-middle dark:bg-ink" data-unread-dot="proposal-{{ $proposal->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                        @endif
                        <span @class(['font-semibold', $mutedText => $isRepeat])>{{ $proposal->name }}</span>
                    </td>
                    <td data-label="{{ __('common.status') }}">
                        @unless ($isRepeat)
                            <x-ui.enum-badge :case="$proposal->status" />
                        @endunless
                    </td>
                    {{-- phase is a read-only accessor over status->phase(), not a column. --}}
                    <td data-label="{{ __('proposals.phase') }}">{{ $isRepeat ? '' : $proposal->phase->label() }}</td>
                    <td class="date" data-label="{{ __('proposals.planned_start') }}">{{ $isRepeat ? '' : $date($proposal->planned_date_begin) }}</td>
                    <td class="date" data-label="{{ __('proposals.planned_end') }}">{{ $isRepeat ? '' : $date($proposal->planned_date_end) }}</td>
                    <td class="num" data-label="{{ __('common.projects') }}">
                        @unless ($isRepeat)
                            <span class="fig">{{ (int) $proposal->projects_count }}</span>
                        @endunless
                    </td>

                    @if ($canAgreements)
                        <td data-label="{{ __('common.donors') }}">
                            @forelse ($lineAgreements as $lineAgreement)
                                <a href="{{ route('donors.show', $lineAgreement->donor) }}" wire:navigate class="block underline-offset-2 hover:underline">{{ $lineAgreement->donor->name }}</a>
                            @empty
                                <span class="{{ $mutedText }}">{{ __('proposals.no_funding_agreements') }}</span>
                            @endforelse
                        </td>
                        <td class="num" data-label="{{ __('finance_tables.agreements') }}"><span class="fig">{{ (int) $proposal->money_agreements }}</span></td>
                        <td data-label="{{ __('common.currency') }}">{{ $lineCurrency ?? '—' }}</td>
                        <td class="num" data-label="{{ __('common.committed') }}" data-cell="committed">
                            @if ($hasMoney)
                                <span class="fig font-semibold">{{ $money($proposal->money_committed) }}</span>
                            @else
                                —
                            @endif
                        </td>
                    @endif

                    @if ($canTransfers)
                        <td class="num" data-label="{{ __('finance_tables.received') }}" data-cell="received">
                            @if ($hasMoney)
                                <span class="fig font-semibold text-funding">{{ $money($proposal->money_received) }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="num" data-label="{{ __('finance_tables.percent_received') }}" data-cell="percent">
                            @if ($hasMoney)
                                <span class="fig">{{ DonorProposal::percentLabel((float) $proposal->money_received, (float) $proposal->money_committed) }}%</span>
                            @else
                                —
                            @endif
                        </td>
                        @if ($showsYearColumns)
                            <td class="num" data-label="{{ __('finance_tables.due_in_year', ['year' => $selectedYear]) }}" data-cell="due_in_year"><span class="fig">{{ $money($proposal->money_due_in_year) }}</span></td>
                            <td class="num" data-label="{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}" data-cell="received_in_year"><span class="fig font-semibold text-funding">{{ $money($proposal->money_received_in_year) }}</span></td>
                        @endif
                        @unless ($yearOnly)
                            <td class="num" data-label="{{ __('finance_tables.outstanding') }}" data-cell="outstanding">
                                @if ($hasMoney)
                                    <span class="fig">{{ $money($proposal->money_outstanding) }}</span>
                                @else
                                    —
                                @endif
                            </td>
                        @endunless
                        <td class="num" data-label="{{ __('finance_tables.overdue') }}" data-cell="overdue">
                            @if ($hasMoney)
                                <span @class(['fig', 'font-semibold text-status-critical' => $overdue > 0])>{{ $money($overdue) }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="date" data-label="{{ __('finance_tables.next_due') }}">
                            @if ($nextDue !== null)
                                <span @class(['font-semibold text-status-critical' => $nextDue->lt($today)])>{{ $date($nextDue) }}</span>
                            @else
                                —
                            @endif
                        </td>
                    @endif

                    <td>
                        @unless ($isRepeat)
                            <span class="flex items-center justify-end">
                                <x-ui.button tone="ghost" size="sm" icon="eye" icon:variant="outline" class="size-8!" :href="route('proposals.show', $proposal)" wire:navigate :aria-label="__('common.view_name', ['name' => $proposal->name])" />
                                @can('update', $proposal)
                                    {{-- Status only moves with an audit note, so this opens the same form the dossier did. --}}
                                    <x-ui.button tone="ghost" size="sm" icon="arrows-right-left" icon:variant="outline" class="size-8!" wire:click="openStageModal({{ $proposal->id }})" :aria-label="__('proposals.change_status')" data-test="advance-proposal-{{ $proposal->id }}" />
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" icon:variant="outline" class="size-8!" wire:click="openEditModal({{ $proposal->id }})" :aria-label="__('common.edit_name', ['name' => $proposal->name])" />
                                @endcan
                                @can('delete', $proposal)
                                    @if ($proposal->donor_proposals_count === 0)
                                        <x-ui.button tone="ghost" size="sm" icon="trash" icon:variant="outline" class="size-8!" wire:click="confirmDelete({{ $proposal->id }})" :aria-label="__('common.delete_name', ['name' => $proposal->name])" />
                                    @else
                                        <flux:tooltip :content="__('proposals.funded_proposals_cannot_be_deleted_remove_the_funding_agreem')">
                                            <span class="inline-flex size-8 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true" tabindex="0" aria-label="{{ __('proposals.funded_proposals_cannot_be_deleted_remove_the_funding_agreem') }}">
                                                <flux:icon.lock-closed class="size-4" />
                                            </span>
                                        </flux:tooltip>
                                    @endif
                                @endcan
                            </span>
                        @endunless
                    </td>
                </tr>
            @endforeach
        </x-funding.register>

        <x-funding.pager :paginator="$this->rows" :per-page="(string) $this->registerPerPage()" :sizes="$this->registerPageSizes()" scroll-to="#proposal-pipeline-heading" />
    @endif


    <x-ui.modal
        wire:model="showProposalModal"
        size="xl"
        :title="$editingProposalId === null ? __('proposals.add_proposal') : __('proposals.edit_proposal')"
        :description="__('proposals.phase_follows_automatically_from_the_status_you_choose')"
        submit="saveProposal"
        close="closeProposalModal"
        required-note
    >
        <x-ui.form-section
            :title="__('common.section_details')"
            :hint="$editingProposalId === null ? __('proposals.the_starting_status_opens_the_status_history') : __('proposals.status_moves_only_through_change_status')"
            :fields="['name', 'internalProjectNumber', 'proposalStatus', 'notes']"
        >
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-input wire:model="name" :label="__('proposals.proposal_name')" maxlength="255" required autofocus :class="$editingProposalId !== null ? '@2xl/modal:col-span-2' : null" />

                @if ($editingProposalId === null)
                    <x-ui.form-select wire:model="proposalStatus" :label="__('proposals.starting_status')" required>
                        @foreach (ProposalStatus::cases() as $statusCase)
                            <option value="{{ $statusCase->value }}">{{ $statusCase->label() }} — {{ $statusCase->phase()->label() }}</option>
                        @endforeach
                    </x-ui.form-select>
                @endif

                <x-ui.form-input wire:model="internalProjectNumber" :label="__('common.internal_project_number')" maxlength="50" dir="ltr" required class="@2xl/modal:col-span-2" />

                <x-ui.form-textarea wire:model="notes" :label="__('common.notes')" rows="2" class="@2xl/modal:col-span-2" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('common.section_schedule')" :fields="['plannedDateBegin', 'plannedDateEnd', 'actualDateBegin', 'actualDateEnd']">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-4">
                <x-ui.form-input wire:model="plannedDateBegin" :label="__('proposals.planned_start')" type="date" required />
                <x-ui.form-input wire:model="plannedDateEnd" :label="__('proposals.planned_end')" type="date" required />
                <x-ui.form-input wire:model="actualDateBegin" :label="__('proposals.actual_start')" type="date" />
                <x-ui.form-input wire:model="actualDateEnd" :label="__('proposals.actual_end')" type="date" />
            </div>
        </x-ui.form-section>

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-proposal-button">
                {{ $editingProposalId === null ? __('proposals.create_proposal') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.modal
        wire:model="showStageModal"
        size="md"
        :title="__('proposals.change_status')"
        :description="__('proposals.the_new_status_and_your_reason_are_recorded_together_as_one')"
        submit="recordStageChange"
        close="closeStageModal"
        required-note
    >
        <div class="grid grid-cols-2 gap-x-4 gap-y-4">
            <x-ui.form-select wire:model="newStatus" :label="__('proposals.new_status')" required autofocus>
                @foreach (ProposalStatus::cases() as $statusCase)
                    <option value="{{ $statusCase->value }}">{{ $statusCase->label() }} — {{ $statusCase->phase()->label() }}</option>
                @endforeach
            </x-ui.form-select>

            <x-ui.form-input wire:model="statusDate" :label="__('proposals.effective_date')" type="date" required />

            <x-ui.form-textarea wire:model="statusContext" :label="__('proposals.why_did_it_change')" required class="col-span-2" />
        </div>

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-stage-button">{{ __('proposals.record_change') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('proposals.delete_this_proposal')"
        :message="__('proposals.its_tasks_project_links_and_status_history_are_removed_with')"
        confirm="deleteProposal"
        :confirm-label="__('proposals.delete_proposal')"
        confirm-test="confirm-delete-proposal"
    />
</section>
