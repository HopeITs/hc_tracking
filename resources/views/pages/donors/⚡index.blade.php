<?php

use App\Actions\Donors\DeleteDonor;
use App\Support\AgreementMoney;
use App\Support\CsvExport;
use App\Support\Like;
use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\DonorType;
use App\Livewire\Concerns\ChecksFinanceVisibility;
use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Livewire\Concerns\ManagesDonorForm;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('titles.donors')] class extends Component {
    use ChecksFinanceVisibility;
    use FiltersFinanceRegister;
    use ManagesDonorForm;
    use WithPagination;

    /** The type filter value that selects donors carrying no classification. */
    public const UNCLASSIFIED = 'unclassified';

    /** How many donors the share-of-wallet strip names before folding the rest together. */
    public const NAMED_SEGMENTS = 7;

    /** How the Overview list may be ordered. */
    public const SORTS = ['name', 'agreements', 'next_due', 'follow_up'];

    /** @var list<string> */
    public const FLAGS = ['overdue', 'follow_up'];

    /**
     * Every property a filter control writes. The donor form's own fields
     * (`country`, `name`, …) are not filters, which is why the country filter
     * carries a `Filter` suffix.
     *
     * @var list<string>
     */
    private const FILTERS = ['search', 'type', 'relationship', 'sort', 'countryFilter', 'currency', 'flag'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $relationship = '';

    /** The Overview list's order. The table sorts by its column headings instead. */
    #[Url(except: 'name')]
    public string $sort = 'name';

    #[Url(as: 'country', except: '')]
    public string $countryFilter = '';

    #[Url(except: '')]
    public string $currency = '';

    #[Url(except: '')]
    public string $flag = '';

    public bool $showDeleteModal = false;
    #[Locked]
    public ?int $deletingDonorId = null;

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
        if (! in_array($this->type, [...DonorType::values(), self::UNCLASSIFIED], true)) {
            $this->type = '';
        }

        if (! in_array($this->relationship, ['funding', 'prospect'], true)) {
            $this->relationship = '';
        }

        if (! in_array($this->sort, self::SORTS, true)) {
            $this->sort = 'name';
        }

        if (! in_array($this->countryFilter, $this->countryOptions, true)) {
            $this->countryFilter = '';
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
        $this->reset(['search', 'type', 'relationship', 'sort', 'countryFilter', 'currency', 'flag']);
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
     * The countries donors are in, for the country picker.
     *
     * @return list<string>
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
     * The one-line reading of the portfolio: who funds, what, and what is due.
     *
     * @return array{donors: int, funding: int, proposals: int, projects: int, overdue: int, followUps: int}
     */
    #[Computed]
    public function pulse(): array
    {
        $ids = $this->filteredDonorIds;
        $agreements = DonorProposal::query()->whereIn('donor_id', $ids);

        $pendingTransfers = fn () => TransfersTracking::query()
            ->where('ar_status', ArStatus::Pending)
            ->whereHas('donorProposal', fn (Builder $query) => $query->whereIn('donor_id', $ids));

        return [
            'donors' => $ids->count(),
            'funding' => (clone $agreements)->distinct()->count('donor_id'),
            'proposals' => (clone $agreements)->distinct()->count('proposal_id'),
            'projects' => DB::table('project_proposal')
                ->whereIn('proposal_id', (clone $agreements)->select('proposal_id'))
                ->distinct()
                ->count('project_id'),
            // Sent money is in transit, not overdue, however late it is.
            'overdue' => $this->canViewTransfers() ? $pendingTransfers()->whereNull('transfer_date')->whereDate('scheduled_date', '<', today())->count() : 0,
            'followUps' => Donor::query()->whereIn('id', $ids)->whereDate('next_step_due_on', '<=', today())->count(),
        ];
    }

    /**
     * Each currency's committed money split by donor, largest first.
     *
     * Received counts only money that arrived in the agreement's own currency,
     * so a segment never mixes currencies.
     *
     * @return list<array{currency: string, committed: float, received: float, segments: list<array{donorId: ?int, name: string, committed: float, received: float, share: float, count: int}>}>
     */
    #[Computed]
    public function wallet(): array
    {
        if (! $this->canViewAgreements()) {
            return [];
        }

        $ids = $this->filteredDonorIds;

        $committed = DonorProposal::query()
            ->whereIn('donor_id', $ids)
            ->selectRaw('donor_id, currency, sum(budget) as total')
            ->groupBy('donor_id', 'currency')
            ->get();

        $received = ! $this->canViewTransfers() ? collect() : TransfersTracking::query()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->whereIn('donor_proposal.donor_id', $ids)
            ->where('transfers_tracking.ar_status', ArStatus::Received)
            ->whereColumn('transfers_tracking.amount_received_currency', 'donor_proposal.currency')
            ->selectRaw('donor_proposal.donor_id as donor_id, donor_proposal.currency as currency, sum(transfers_tracking.net_amount_received) as total')
            ->groupBy('donor_proposal.donor_id', 'donor_proposal.currency')
            ->get()
            ->mapWithKeys(fn ($row): array => [$row->donor_id.'|'.$row->currency => (float) $row->total]);

        $names = Donor::query()->whereIn('id', $committed->pluck('donor_id')->unique())->pluck('name', 'id');

        return $committed
            ->groupBy(fn ($row): string => $row->currency->value)
            ->sortKeys()
            ->map(function (Collection $rows, string $currency) use ($received, $names): array {
                $total = (float) $rows->sum('total');

                $segments = $rows
                    ->map(fn ($row): array => [
                        'donorId' => (int) $row->donor_id,
                        'name' => (string) ($names[$row->donor_id] ?? ''),
                        'committed' => (float) $row->total,
                        'received' => (float) ($received[$row->donor_id.'|'.$currency] ?? 0),
                        'count' => 1,
                    ])
                    ->sortByDesc('committed')
                    ->values();

                $named = $segments->take(self::NAMED_SEGMENTS);
                $rest = $segments->slice(self::NAMED_SEGMENTS);

                if ($rest->isNotEmpty()) {
                    $named->push([
                        'donorId' => null,
                        'name' => trans_choice('donors.count_other_donors', $rest->count(), ['count' => $rest->count()]),
                        'committed' => (float) $rest->sum('committed'),
                        'received' => (float) $rest->sum('received'),
                        'count' => $rest->count(),
                    ]);
                }

                return [
                    'currency' => $currency,
                    'committed' => $total,
                    'received' => (float) $segments->sum('received'),
                    'segments' => $named
                        ->map(fn (array $segment): array => [...$segment, 'share' => $total > 0 ? round($segment['committed'] / $total * 100, 2) : 0])
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * How many donors of each type match the other filters; the rail stays fully selectable.
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    #[Computed]
    public function typeRail(): array
    {
        $counts = $this->filteredQuery(withType: false)
            ->selectRaw("coalesce(type, '".self::UNCLASSIFIED."') as type_key, count(*) as total")
            ->groupBy('type_key')
            ->pluck('total', 'type_key');

        $stops = array_map(fn (DonorType $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'count' => (int) ($counts[$case->value] ?? 0),
        ], DonorType::cases());

        $stops[] = [
            'value' => self::UNCLASSIFIED,
            'label' => __('donors.not_classified'),
            'count' => (int) ($counts[self::UNCLASSIFIED] ?? 0),
        ];

        return $stops;
    }

    /**
     * How many rows the funders column shows, so the transfers column can match its height.
     *
     * Each currency block costs roughly two rows for its heading.
     */
    #[Computed]
    public function overviewRows(): int
    {
        $rows = collect($this->wallet)->sum(fn (array $line): int => count($line['segments']) + 2);

        return max(3, min(8, $rows));
    }

    /**
     * The pending transfers ahead, overdue ones first, for the transfers column.
     *
     * @return Collection<int, array{donorId: int, donor: string, proposal: string, agreementId: int, amount: float, currency: string, date: CarbonImmutable, overdue: bool}>
     */
    #[Computed]
    public function upcomingTransfers(): Collection
    {
        if (! $this->canViewTransfers()) {
            return new Collection;
        }

        return TransfersTracking::query()
            ->select(['id', 'donor_proposal_id', 'payment_amount', 'amount_transfered_currency', 'scheduled_date', 'transfer_date', 'ar_status'])
            ->where('ar_status', ArStatus::Pending)
            ->whereNull('transfer_date')
            ->whereNotNull('scheduled_date')
            ->whereHas('donorProposal', fn (Builder $query) => $query->whereIn('donor_id', $this->filteredDonorIds))
            ->with(['donorProposal:id,donor_id,proposal_id', 'donorProposal.donor:id,name', 'donorProposal.proposal:id,name'])
            ->orderBy('scheduled_date')
            ->limit($this->overviewRows)
            ->get()
            ->map(fn (TransfersTracking $transfer): array => [
                'donorId' => $transfer->donorProposal->donor_id,
                'donor' => $transfer->donorProposal->donor->name,
                'proposal' => (string) $transfer->donorProposal->proposal?->name,
                'agreementId' => $transfer->donor_proposal_id,
                'amount' => (float) $transfer->payment_amount,
                'currency' => $transfer->amount_transfered_currency->value,
                'date' => $transfer->scheduled_date,
                'overdue' => $transfer->state() === 'late',
            ]);
    }

    /**
     * One donor's money per currency, and the transfer rhythm behind it.
     *
     * @return array{lines: array<string, array{committed: float, received: float, pending: float, share: int}>, next: ?array{amount: float, currency: string, date: CarbonImmutable, overdue: bool}, overdue: int, lastReceived: ?CarbonImmutable, projects: int}
     */
    protected function donorMoney(Donor $donor): array
    {
        $projects = $donor->donorProposals->flatMap(fn (DonorProposal $agreement) => $agreement->proposal?->projects->pluck('id') ?? [])->unique()->count();

        if (! $this->canViewAgreements()) {
            return ['lines' => [], 'next' => null, 'overdue' => 0, 'lastReceived' => null, 'projects' => $projects];
        }

        $lines = [];
        $pendingTransfers = collect();
        $receivedDates = collect();

        foreach ($donor->donorProposals as $agreement) {
            $currency = $agreement->currency->value;
            $lines[$currency] ??= ['committed' => 0.0, 'received' => 0.0, 'pending' => 0.0, 'share' => 0];
            $lines[$currency]['committed'] += (float) $agreement->budget;

            foreach ($agreement->transfers as $transfer) {
                if ($transfer->ar_status === ArStatus::Received && $transfer->amount_received_currency?->value === $currency) {
                    $lines[$currency]['received'] += (float) $transfer->net_amount_received;
                }

                if ($transfer->ar_status === ArStatus::Pending) {
                    $pendingTransfers->push($transfer);

                    if ($transfer->amount_transfered_currency?->value === $currency) {
                        $lines[$currency]['pending'] += (float) $transfer->payment_amount;
                    }
                }

                if ($transfer->ar_status === ArStatus::Received && $transfer->receive_date !== null) {
                    $receivedDates->push($transfer->receive_date);
                }
            }
        }

        ksort($lines);

        foreach ($lines as $currency => $line) {
            $lines[$currency]['share'] = $line['committed'] > 0 ? (int) floor($line['received'] / $line['committed'] * 100) : 0;
        }

        $next = $pendingTransfers->whereNull('transfer_date')->whereNotNull('scheduled_date')->sortBy('scheduled_date')->first();

        return [
            'lines' => $lines,
            'next' => $next === null ? null : [
                'amount' => (float) $next->payment_amount,
                'currency' => (string) $next->amount_transfered_currency?->value,
                'date' => $next->scheduled_date,
                'overdue' => $next->state() === 'late',
            ],
            'overdue' => $pendingTransfers->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === 'late')->count(),
            'lastReceived' => $receivedDates->sortDesc()->first(),
            'projects' => $projects,
        ];
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
     * The earliest unsent tranche of each donor and agreement currency on the
     * visible page, keyed "donorId|currency": the date the table prints as the
     * next transfer, with the amount due on it. Money already sent is in
     * transit, so it is never a next transfer.
     *
     * @return Collection<string, object>
     */
    #[Computed]
    public function nextTransfers(): Collection
    {
        if (! $this->canViewAgreements() || ! $this->canViewTransfers()) {
            return new Collection;
        }

        $donorIds = $this->rows->getCollection()->pluck('id')->unique()->values();

        if ($donorIds->isEmpty()) {
            return new Collection;
        }

        return TransfersTracking::query()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->whereIn('donor_proposal.donor_id', $donorIds)
            ->where('transfers_tracking.ar_status', ArStatus::Pending)
            ->whereNull('transfers_tracking.transfer_date')
            ->whereNotNull('transfers_tracking.scheduled_date')
            ->orderBy('transfers_tracking.scheduled_date')
            ->orderBy('transfers_tracking.id')
            ->toBase()
            ->get([
                'donor_proposal.donor_id',
                'donor_proposal.currency as agreement_currency',
                'transfers_tracking.payment_amount',
                'transfers_tracking.amount_transfered_currency as sent_currency',
                'transfers_tracking.scheduled_date',
            ])
            ->unique(fn (object $row): string => $row->donor_id.'|'.$row->agreement_currency)
            ->keyBy(fn (object $row): string => $row->donor_id.'|'.$row->agreement_currency);
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

    /**
     * Every line the filters match, in the table's order, as a CSV file. The
     * file holds the columns the viewer sees on screen and no others.
     */
    public function exportCsv(): StreamedResponse
    {
        Gate::authorize('donors.view');

        $canAgreements = $this->canViewAgreements();
        $canTransfers = $canAgreements && $this->canViewTransfers();
        $year = $canTransfers ? $this->selectedYear() : null;

        $query = $this->joinedQuery()->select('donors.*');

        if ($canAgreements) {
            $query->selectRaw(AgreementMoney::ownerSelect());
        }

        $donors = $this->applyRegisterSort($query)->cursor();

        $headings = [
            __('donors.donor'),
            __('common.type'),
            __('donors.country'),
            __('donors.contact_person'),
            __('common.email'),
            __('donors.phone'),
            __('donors.next_step'),
            __('donors.next_step_due_on'),
            __('donors.last_contacted_on'),
            ...($canAgreements ? [__('finance_tables.agreements'), __('common.currency'), __('common.committed')] : []),
            ...($canTransfers ? [
                __('finance_tables.received'),
                __('finance_tables.percent_received'),
                __('finance_tables.outstanding'),
                __('finance_tables.overdue'),
                __('finance_tables.overdue_transfers'),
                __('donors.next_transfer'),
                __('finance_tables.last_received'),
            ] : []),
            ...($year === null ? [] : [
                __('finance_tables.due_in_year', ['year' => $year]),
                __('finance_tables.received_in_year', ['year' => $year]),
            ]),
        ];

        $rows = (function () use ($donors, $canAgreements, $canTransfers, $year) {
            foreach ($donors as $donor) {
                yield [
                    $donor->name,
                    $donor->type?->label(),
                    $donor->country,
                    $donor->contact_person,
                    $donor->email,
                    $donor->phone,
                    $donor->next_step,
                    $donor->next_step_due_on,
                    $donor->last_contacted_on,
                    ...($canAgreements ? [
                        (int) $donor->money_agreements,
                        $donor->money_currency,
                        $donor->money_currency === null ? null : CsvExport::amount($donor->money_committed),
                    ] : []),
                    ...($canTransfers ? [
                        $donor->money_currency === null ? null : CsvExport::amount($donor->money_received),
                        $donor->money_currency === null ? null : DonorProposal::percentLabel((float) $donor->money_received, (float) $donor->money_committed),
                        $donor->money_currency === null ? null : CsvExport::amount($donor->money_outstanding),
                        $donor->money_currency === null ? null : CsvExport::amount($donor->money_overdue),
                        (int) $donor->money_overdue_count,
                        $donor->money_next_due,
                        $donor->money_last_received,
                    ] : []),
                    ...($year === null ? [] : [
                        CsvExport::amount($donor->money_due_in_year),
                        CsvExport::amount($donor->money_received_in_year),
                    ]),
                ];
            }
        })();

        return CsvExport::download('donors-'.CarbonImmutable::today()->toDateString().'.csv', $headings, $rows);
    }

    /**
     * Whether this donor may be deleted.
     *
     * donor_proposal.donor_id RESTRICTs: a donor that has signed anything stays.
     */
    protected function canDelete(int $donorId): bool
    {
        $donor = Donor::query()->select(['id'])->withCount('donorProposals')->find($donorId);

        return $donor !== null && $donor->donor_proposals_count === 0;
    }

    protected function donorSaved(Donor $donor, bool $isCreate): void
    {
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $isCreate ? __('donors.donor_created') : __('donors.donor_updated'),
        );
    }

    public function confirmDelete(int $donorId): void
    {
        Gate::authorize('donors.delete');

        $this->deletingDonorId = $donorId;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->deletingDonorId = null;
        $this->showDeleteModal = false;
    }

    public function deleteDonor(): void
    {
        if ($this->deletingDonorId === null) {
            $this->showDeleteModal = false;

            return;
        }

        if (! $this->canDelete($this->deletingDonorId)) {
            $this->cancelDelete();

            Flux::toast(
                variant: 'danger',
                text: __('donors.this_donor_has_signed_a_funding_agreement_and_cannot_be_dele'),
            );

            return;
        }

        app(DeleteDonor::class)->handle(Donor::query()->findOrFail($this->deletingDonorId));

        $this->cancelDelete();
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('donors.donor_deleted'));
    }
};
?>

@php
    $showsOverview = $this->view === 'overview';
    $canAgreements = $this->canViewAgreements();
    $canTransfers = $canAgreements && $this->canViewTransfers();
    $activeFilterCount = collect([$this->search, $this->type, $this->relationship, $this->countryFilter, $this->currency, $this->flag, $this->year])
        ->filter(fn (string $value): bool => $value !== '')
        ->count();
    $total = $showsOverview ? $this->donors->total() : $this->donorCount;

    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';

    /* The three closures below format the Overview view (_overview.blade.php). The table prints exact amounts itself. */
    $amount = fn (float $value): string => Number::format($value, maxPrecision: 0);
    $shortAmount = fn (float $value): string => Number::abbreviate($value, maxPrecision: 1);
    $initials = fn (string $name): string => mb_strtoupper(collect(preg_split('/\s+/u', trim($name)) ?: [])->filter()->take(2)->map(fn (string $word): string => mb_substr($word, 0, 1))->implode('')) ?: '·';

    /* `$chosen` is read instead of the properties so a loop variable can never shadow one. */
    $chosen = [
        'year' => $this->year,
        'figures' => $this->figures,
        'relationship' => $this->relationship,
        'type' => $this->type,
        'currency' => $this->currency,
        'flag' => $this->flag,
        'sort' => $this->sort,
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
    $relationshipPills = [
        ['default' => true, 'label' => __('finance_tables.all'), 'model' => 'relationship', 'value' => '', 'active' => $chosen['relationship'] === ''],
        $setPill('relationship', 'funding', __('donors.funding_now')),
        $setPill('relationship', 'prospect', __('donors.prospects')),
    ];
    $typePills = [
        ['default' => true, 'label' => __('finance_tables.all'), 'model' => 'type', 'value' => '', 'active' => $chosen['type'] === ''],
        ...array_map(fn (array $stop): array => [
            'label' => $stop['label'],
            'model' => 'type', 'value' => $stop['value'], 'toggle' => true,
            'active' => $chosen['type'] === $stop['value'],
            'count' => $stop['count'],
            'test' => 'type-'.$stop['value'],
        ], $this->typeRail),
    ];
    $currencyPills = [
        ['default' => true, 'label' => __('finance_tables.all'), 'model' => 'currency', 'value' => '', 'active' => $chosen['currency'] === ''],
        ...array_map(fn (Currency $case): array => $setPill('currency', $case->value, $case->value), Currency::cases()),
    ];
    $flagPills = array_values(array_filter([
        $canTransfers ? ['label' => __('finance_tables.flag_has_overdue'), 'model' => 'flag', 'value' => 'overdue', 'toggle' => true, 'active' => $chosen['flag'] === 'overdue', 'tone' => 'critical', 'test' => 'flag-overdue'] : null,
        ['label' => __('finance_tables.flag_follow_up_due'), 'model' => 'flag', 'value' => 'follow_up', 'toggle' => true, 'active' => $chosen['flag'] === 'follow_up', 'test' => 'flag-follow-up'],
    ]));
    $overviewOrderPills = array_values(array_filter([
        ['default' => true, 'label' => __('donors.sort_name'), 'model' => 'sort', 'value' => 'name', 'active' => $chosen['sort'] === 'name'],
        ['label' => __('donors.sort_agreements'), 'model' => 'sort', 'value' => 'agreements', 'active' => $chosen['sort'] === 'agreements'],
        $canTransfers ? ['label' => __('donors.sort_next_due'), 'model' => 'sort', 'value' => 'next_due', 'active' => $chosen['sort'] === 'next_due'] : null,
        ['label' => __('donors.sort_follow_up'), 'model' => 'sort', 'value' => 'follow_up', 'active' => $chosen['sort'] === 'follow_up'],
    ]));
@endphp

<section
    class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink"
    aria-labelledby="donor-directory-heading"
    x-data="{ focus: null, lit(id) { return this.focus === null || this.focus === id } }"
>
    <x-ui.page-header
        heading-id="donor-directory-heading"
        :title="__('common.donors')"
        :count="$total"
    >
        <x-slot:actions>
            <div class="flex flex-wrap items-center justify-end gap-2">
                @if ($this->hasAnyDonor)
                    <x-funding.filters :active="$activeFilterCount" :results="$total">
                        <div class="directory-filters grid gap-3 sm:grid-cols-2">
                            <x-ui.input type="search" icon="magnifying-glass" :label="__('donors.search_donors')" :placeholder="__('donors.name_country_or_contact')" wire:model="search" clearable />

                            <x-ui.header-filter-select deferred :label="__('donors.country')" wire:model="countryFilter" :value="$this->countryFilter">
                                <flux:select.option value="">{{ __('finance_tables.all_countries') }}</flux:select.option>
                                @foreach ($this->countryOptions as $countryOption)
                                    <flux:select.option :value="$countryOption" :wire:key="'donor-country-'.md5($countryOption)">{{ $countryOption }}</flux:select.option>
                                @endforeach
                            </x-ui.header-filter-select>
                        </div>

                        {{-- A year narrows by tranches, so it is offered only to someone who may see them. --}}
                        @if ($canTransfers)
                            <x-funding.pills :label="__('finance_tables.year')" :pills="$yearPills" />

                            @if ($selectedYear !== null)
                                <x-funding.pills :label="__('finance_tables.figures_label')" :pills="$figurePills" />
                            @endif
                        @endif

                        <x-funding.pills :label="__('donors.relationship')" :pills="$relationshipPills" />
                        <x-funding.pills :label="__('common.type')" :pills="$typePills" />

                        @if ($canAgreements)
                            <x-funding.pills :label="__('common.currency')" :pills="$currencyPills" />
                        @endif

                        <x-funding.pills :label="__('finance_tables.flags')" :pills="$flagPills" />

                        @if ($showsOverview)
                            <x-funding.pills :label="__('finance_tables.order')" :pills="$overviewOrderPills" keep />
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

                @if ($this->hasAnyDonor)
                    <x-ui.button tone="action" icon="arrow-down-tray" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" data-test="export-csv">
                        {{ __('finance_tables.export_csv') }}
                    </x-ui.button>
                @endif

                @can('donors.create')
                <x-ui.button tone="primary" icon="plus" class="page-action page-action-primary" wire:click="openCreateModal" data-test="create-donor-button">
                    {{ __('donors.add_donor') }}
                </x-ui.button>
                @endcan
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $this->hasAnyDonor)
        <x-ui.empty-state icon="building-library" :heading="__('donors.no_donors_yet')" :body="__('donors.add_the_first_donor')" />
    @elseif ($showsOverview)
        @include('pages.donors._overview')
    @elseif ($this->rows->isEmpty())
        <x-ui.empty-state icon="building-library" :heading="__('donors.no_matching_donors')" :body="__('common.change_the_search_or_filters_and_try_again')" />
    @else
        @php
            $totals = $this->totals;
            $money = fn (string|float|int|null $value): string => number_format((float) $value, 2);
            $date = fn (?\DateTimeInterface $value): string => $value?->translatedFormat(__('formats.date')) ?? '—';
            $today = \Carbon\CarbonImmutable::today();
            $showsYearColumns = $selectedYear !== null && ! $yearOnly;

            $totalLines = $totals->map(fn (array $line): array => [
                'currency' => $line['currency'],
                'caption' => trans_choice('finance_tables.count_donors', $line['donors'], ['count' => $line['donors']]),
                'figures' => array_values(array_filter([
                    ['key' => 'committed', 'label' => __('common.committed'), 'value' => $line['committed']],
                    $canTransfers ? ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $line['received'], 'tone' => 'good', 'note' => DonorProposal::percentLabel($line['received'], $line['committed']).'%'] : null,
                    $showsYearColumns ? ['key' => 'due_in_year', 'label' => __('finance_tables.due_in_year', ['year' => $selectedYear]), 'value' => $line['due_in_year']] : null,
                    $showsYearColumns ? ['key' => 'received_in_year', 'label' => __('finance_tables.received_in_year', ['year' => $selectedYear]), 'value' => $line['received_in_year'], 'tone' => 'good'] : null,
                    $canTransfers && ! $yearOnly ? ['key' => 'outstanding', 'label' => __('finance_tables.outstanding'), 'value' => $line['outstanding']] : null,
                    $canTransfers ? ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $line['overdue'], 'tone' => $line['overdue'] > 0 ? 'critical' : null] : null,
                ])),
            ])->all();

            $previousDonorId = null;
        @endphp

        @if ($canAgreements)
            <x-funding.totals :lines="$totalLines" />
        @endif

        {{-- A slim register: who, when to follow up, and the money at a glance. Contact details, the next step and the rest of the rhythm wait on the donor's own page. --}}
        <x-funding.register :size="! $canTransfers ? 'sm' : ($showsYearColumns ? 'lg' : 'md')" :caption="__('finance_tables.donors_caption')">
            <x-slot:head>
                <x-funding.register.th sort="donor" :active="$sortKey" :dir="$sortDirection">{{ __('donors.donor') }}</x-funding.register.th>
                <x-funding.register.th>{{ __('donors.section_contact') }}</x-funding.register.th>
                <x-funding.register.th sort="follow_up" :active="$sortKey" :dir="$sortDirection">{{ __('donors.follow_up') }}</x-funding.register.th>
                @if ($canAgreements)
                    <x-funding.register.th sort="committed" :active="$sortKey" :dir="$sortDirection" numeric>{{ $yearOnly ? __('finance_tables.committed_whole') : __('common.committed') }}</x-funding.register.th>
                @endif
                @if ($canTransfers)
                    <x-funding.register.th sort="received" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.received') }}</x-funding.register.th>
                    @if ($showsYearColumns)
                        <x-funding.register.th sort="due_in_year" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.due_in_year', ['year' => $selectedYear]) }}</x-funding.register.th>
                        <x-funding.register.th sort="received_in_year" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}</x-funding.register.th>
                    @endif
                    @unless ($yearOnly)
                        <x-funding.register.th sort="outstanding" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.outstanding') }}</x-funding.register.th>
                    @endunless
                    <x-funding.register.th sort="overdue" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.overdue') }}</x-funding.register.th>
                    <x-funding.register.th sort="next_transfer" :active="$sortKey" :dir="$sortDirection">{{ __('donors.next_transfer') }}</x-funding.register.th>
                @endif
                <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($this->rows as $donor)
                @php
                    // A donor funding in two currencies has two lines; its identity is printed on the first only.
                    $isRepeat = $previousDonorId === $donor->id;
                    $previousDonorId = $donor->id;
                    $isProspect = $donor->donor_proposals_count === 0;
                    $lineCurrency = $canAgreements ? $donor->money_currency : null;
                    $hasMoney = $lineCurrency !== null;
                    $next = $canTransfers && $hasMoney ? $this->nextTransfers->get($donor->id.'|'.$lineCurrency) : null;
                    $nextDate = $next === null ? null : \Carbon\CarbonImmutable::parse($next->scheduled_date);
                    $overdue = $canTransfers && $hasMoney ? (float) $donor->money_overdue : 0.0;
                    // Several people share one field, split by slashes: the row names the first and counts the rest.
                    $contacts = collect(preg_split('/\s*\/\s*/u', (string) $donor->contact_person) ?: [])->map(fn (string $name): string => trim($name))->filter()->values();
                    $identity = collect([$donor->type?->label() ?? __('donors.not_classified'), $donor->country])->filter()->implode(' · ');
                @endphp
                <tr wire:key="donor-{{ $donor->id }}-{{ $lineCurrency ?? 'none' }}" data-donor-row data-donor="{{ $donor->id }}">
                    <td data-label="{{ __('donors.donor') }}" data-wide>
                        <span @class(['line-clamp-2 max-w-64 font-semibold', $mutedText => $isRepeat]) title="{{ $donor->name }}">{{ $donor->name }}</span>
                        @unless ($isRepeat)
                            <span class="sub max-w-64 truncate">{{ $identity }}</span>
                        @endunless
                    </td>
                    <td data-label="{{ __('donors.section_contact') }}">
                        @unless ($isRepeat)
                            @if ($contacts->isNotEmpty())
                                <span class="mx-auto block max-w-48 truncate" title="{{ $contacts->first() }}">{{ $contacts->first() }}</span>
                                @if ($contacts->count() > 1)
                                    <span class="sub">{{ __('donors.count_more_contacts', ['count' => $contacts->count() - 1]) }}</span>
                                @endif
                            @elseif (filled($donor->email) || filled($donor->phone))
                                <span class="mx-auto block max-w-48 truncate">{{ $donor->email ?: $donor->phone }}</span>
                            @else
                                <span class="{{ $mutedText }}">—</span>
                            @endif
                        @endunless
                    </td>
                    <td class="date" data-label="{{ __('donors.follow_up') }}">
                        @if (! $isRepeat && $donor->next_step_due_on !== null)
                            <span @class(['font-semibold text-status-critical' => $donor->isFollowUpOverdue()]) @if ($donor->isFollowUpOverdue()) data-follow-up-overdue @endif>{{ $date($donor->next_step_due_on) }}</span>
                        @elseif (! $isRepeat)
                            —
                        @endif
                    </td>

                    @if ($canAgreements)
                        <td class="num" data-label="{{ __('common.committed') }}" data-cell="committed">
                            @if ($hasMoney)
                                <span class="fig font-semibold">{{ $money($donor->money_committed) }}</span>
                                <span class="sub">{{ $lineCurrency }} · {{ trans_choice('finance_tables.count_agreements', (int) $donor->money_agreements, ['count' => (int) $donor->money_agreements]) }}</span>
                            @else
                                —
                            @endif
                        </td>
                    @endif

                    @if ($canTransfers)
                        <td class="num" data-label="{{ __('finance_tables.received') }}" data-cell="received">
                            @if ($hasMoney)
                                <span class="fig font-semibold text-funding">{{ $money($donor->money_received) }}</span>
                                <span class="sub"><span class="fig">{{ DonorProposal::percentLabel((float) $donor->money_received, (float) $donor->money_committed) }}%</span></span>
                            @else
                                —
                            @endif
                        </td>
                        @if ($showsYearColumns)
                            <td class="num" data-label="{{ __('finance_tables.due_in_year', ['year' => $selectedYear]) }}" data-cell="due_in_year"><span class="fig">{{ $money($donor->money_due_in_year) }}</span></td>
                            <td class="num" data-label="{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}" data-cell="received_in_year"><span class="fig font-semibold text-funding">{{ $money($donor->money_received_in_year) }}</span></td>
                        @endif
                        @unless ($yearOnly)
                            <td class="num" data-label="{{ __('finance_tables.outstanding') }}" data-cell="outstanding">
                                @if ($hasMoney)
                                    <span class="fig">{{ $money($donor->money_outstanding) }}</span>
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
                        <td class="date" data-label="{{ __('donors.next_transfer') }}">
                            @if ($nextDate !== null)
                                <span @class(['font-semibold text-status-critical' => $nextDate->lt($today)]) data-next-transfer>{{ $date($nextDate) }}</span>
                                <span class="sub"><span class="fig">{{ $money($next->payment_amount) }} {{ $next->sent_currency }}</span></span>
                            @else
                                —
                            @endif
                        </td>
                    @endif

                    <td>
                        @unless ($isRepeat)
                            <span class="flex items-center justify-end">
                                <x-ui.button tone="ghost" size="sm" icon="eye" icon:variant="outline" class="size-8!" :href="route('donors.show', $donor)" wire:navigate :aria-label="__('common.view_name', ['name' => $donor->name])" />
                                @can('donors.update')
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" icon:variant="outline" class="size-8!" wire:click="openEditModal({{ $donor->id }})" :aria-label="__('common.edit_name', ['name' => $donor->name])" />
                                @endcan
                                @can('donors.delete')
                                    @if ($isProspect)
                                        <x-ui.button tone="ghost" size="sm" icon="trash" icon:variant="outline" class="size-8!" wire:click="confirmDelete({{ $donor->id }})" :aria-label="__('common.delete_name', ['name' => $donor->name])" />
                                    @else
                                        <flux:tooltip :content="__('donors.donors_holding_an_agreement_cannot_be_deleted')">
                                            <span class="inline-flex size-8 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
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

        <x-funding.pager :paginator="$this->rows" :per-page="(string) $this->registerPerPage()" :sizes="$this->registerPageSizes()" scroll-to="#donor-directory-heading" />
    @endif

    <x-donors.form-modal :editing="$editingDonorId !== null" :type-options="$this->donorTypeOptions" />

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('donors.delete_this_donor')"
        :message="__('donors.only_a_donor_holding_no_funding_agreement_can_be_deleted')"
        confirm="deleteDonor"
        :confirm-label="__('donors.delete_donor')"
        confirm-test="confirm-delete-donor"
    />
</section>
