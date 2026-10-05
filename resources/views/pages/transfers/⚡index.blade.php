<?php

use App\Enums\Currency;
use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Livewire\Concerns\ManagesTransferForm;
use App\Models\Account;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use App\Support\CsvExport;
use App\Support\Like;
use App\Support\Tranches;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('titles.transfers')] class extends Component {
    use FiltersFinanceRegister;
    use ManagesTransferForm;
    use WithPagination;

    /**
     * How the Overview ladder may be ranked. `attention` leads with money that
     * has misbehaved.
     *
     * @var list<string>
     */
    public const SORTS = ['attention', 'shortfall', 'date', 'amount'];

    /**
     * Where a tranche's money can be: the five states every funding page
     * shares (overdue is the model's `late`), plus the two conditions only a
     * received tranche can be in — landed short of what was sent, and received
     * without anyone saying what arrived.
     *
     * @var list<string>
     */
    public const STAGES = ['overdue', 'transit', 'upcoming', 'received', 'failed', 'short', 'unrecorded'];

    /** @var list<string> */
    public const FLAGS = ['converted', 'no_account'];

    /** @var list<string> */
    public const GROUPS = ['agreement', 'donor', 'account', 'month'];

    /** @var list<string> */
    public const DATE_BASES = ['scheduled', 'sent', 'received'];

    /**
     * Every property a filter control writes. A change to any of them is
     * normalised and sends the table back to its first page.
     *
     * @var list<string>
     */
    private const FILTERS = ['search', 'donor', 'account', 'currency', 'stage', 'flag', 'dateBasis', 'from', 'to', 'min', 'max', 'group', 'agreement', 'year'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $donor = '';

    #[Url(except: '')]
    public string $account = '';

    #[Url(except: '')]
    public string $currency = '';

    #[Url(except: '')]
    public string $stage = '';

    #[Url(except: '')]
    public string $flag = '';

    /** Which of a tranche's three dates the date range and the month grouping read. */
    #[Url(except: 'scheduled')]
    public string $dateBasis = 'scheduled';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $min = '';

    #[Url(except: '')]
    public string $max = '';

    #[Url(except: '')]
    public string $group = '';

    /** The Overview ladder's order. The table sorts by its column headings instead. */
    #[Url(except: 'attention')]
    public string $sort = 'attention';

    /**
     * One funding agreement to narrow the page to, set by the "see in the
     * transfers ledger" link on an agreement page.
     */
    #[Url(except: '')]
    public string $agreement = '';

    /**
     * Deep link from a notification's "Mark received": opens that tranche's
     * edit form once, then clears itself.
     */
    #[Url(as: 'edit', except: '')]
    public string $editTransfer = '';

    /**
     * Everything here arrives from the URL, so a hand-typed value would
     * otherwise leave a filter applied with no control showing it. Normalise
     * on the way in.
     */
    public function mount(): void
    {
        // A bookmark from before the status pills: ?arStatus=received|failed.
        $legacyStatus = (string) request()->query('arStatus', '');

        if ($this->stage === '' && in_array($legacyStatus, ['received', 'failed'], true)) {
            $this->stage = $legacyStatus;
        }

        $this->normaliseFilters();
        $this->syncRangeWithYear();

        if (ctype_digit($this->editTransfer)) {
            $this->openTransferEdit((int) $this->editTransfer);
        }

        $this->editTransfer = '';
    }

    public function updated(string $property): void
    {
        if (! in_array($property, self::FILTERS, true)) {
            return;
        }

        if ($property === 'year') {
            [$this->from, $this->to] = $this->yearBounds($this->year) ?? ['', ''];
        }

        $this->normaliseFilters();
        $this->syncRangeWithYear();
        $this->resetPage();
    }

    protected function normaliseFilters(): void
    {
        if (! in_array($this->sort, self::SORTS, true)) {
            $this->sort = 'attention';
        }

        if (! in_array($this->stage, self::STAGES, true)) {
            $this->stage = '';
        }

        if (! in_array($this->flag, self::FLAGS, true)) {
            $this->flag = '';
        }

        if (! in_array($this->group, self::GROUPS, true)) {
            $this->group = '';
        }

        if (! in_array($this->dateBasis, self::DATE_BASES, true)) {
            $this->dateBasis = 'scheduled';
        }

        if (Currency::tryFrom($this->currency) === null) {
            $this->currency = '';
        }

        foreach (['donor', 'account', 'agreement'] as $idFilter) {
            if (! ctype_digit($this->{$idFilter})) {
                $this->{$idFilter} = '';
            }
        }

        $this->from = $this->cleanDate($this->from);
        $this->to = $this->cleanDate($this->to);
        $this->min = $this->cleanAmount($this->min);
        $this->max = $this->cleanAmount($this->max);
    }

    /**
     * On this page a year is a shortcut for a date range, not a filter of its
     * own: choosing one fills the range, and a range that is no longer that
     * whole year lets go of it.
     */
    protected function syncRangeWithYear(): void
    {
        $bounds = $this->yearBounds($this->year);

        if ($bounds === null) {
            $this->year = '';

            return;
        }

        if ($this->from === '' && $this->to === '') {
            [$this->from, $this->to] = $bounds;

            return;
        }

        if ([$this->from, $this->to] !== $bounds) {
            $this->year = '';
        }
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    protected function yearBounds(string $year): ?array
    {
        return preg_match('/^(19|20|21)\d{2}$/', $year) === 1 ? ["{$year}-01-01", "{$year}-12-31"] : null;
    }

    /**
     * A real calendar date in Y-m-d, or nothing.
     */
    protected function cleanDate(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }

    /**
     * An amount the payment column can hold, or nothing.
     */
    protected function cleanAmount(string $value): string
    {
        return is_numeric($value) && (float) $value >= 0 && (float) $value <= self::MAX_AMOUNT ? $value : '';
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'donor', 'account', 'currency', 'stage', 'flag', 'dateBasis', 'from', 'to', 'min', 'max', 'agreement']);
        $this->resetRegisterFilters();
        $this->resetPage();
    }

    /**
     * The agreement the page is narrowed to, for the filter chip.
     */
    #[Computed]
    public function agreementFilter(): ?DonorProposal
    {
        $agreementId = (int) $this->agreement;

        return $agreementId > 0
            ? DonorProposal::query()->with(['donor:id,name', 'proposal:id,name'])->find($agreementId, ['id', 'donor_id', 'proposal_id', 'external_project_number'])
            : null;
    }

    /**
     * The donors that hold an agreement, for the donor picker.
     *
     * @return EloquentCollection<int, Donor>
     */
    #[Computed]
    public function filterDonors(): EloquentCollection
    {
        return Donor::query()->has('donorProposals')->orderBy('name')->orderBy('id')->get(['id', 'name']);
    }

    /**
     * Every account, open or closed, for the account picker. The rows already
     * name the account each tranche lands in, so the list shows nothing new.
     *
     * @return EloquentCollection<int, Account>
     */
    #[Computed]
    public function filterAccounts(): EloquentCollection
    {
        return Account::query()->orderBy('account_name')->orderBy('id')->get(['id', 'account_name', 'is_active']);
    }

    /**
     * Narrow to one stage, or clear it by choosing it again.
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
     * The Overview ladder's order.
     */
    public function setSort(string $sort): void
    {
        $this->sort = in_array($sort, self::SORTS, true) ? $sort : 'attention';
        $this->resetPage();
    }

    /**
     * Rank the table by what needs chasing, or go back to its default order.
     */
    public function toggleAttentionOrder(): void
    {
        [$this->sortBy, $this->sortDir] = $this->activeSort() === 'attention' ? ['', ''] : ['attention', 'asc'];
        $this->resetPage();
    }

    /**
     * A tranche whose journey has finished and whose two ends can honestly be
     * compared. One definition, on the model, for every page.
     */
    protected function settledSql(): string
    {
        return TransfersTracking::settledSql();
    }

    /**
     * Stage conditions as SQL, keyed by stage. Each one is the model's own
     * definition, so a filter, a count and a total read a tranche the same way
     * its row does.
     *
     * @return array<string, string>
     */
    protected function stageSql(): array
    {
        return [
            'overdue' => TransfersTracking::stateSql('late'),
            'transit' => TransfersTracking::stateSql('transit'),
            'upcoming' => TransfersTracking::stateSql('upcoming'),
            'received' => TransfersTracking::stateSql('received'),
            'failed' => TransfersTracking::stateSql('failed'),
            'short' => TransfersTracking::landedShortSql(),
            'unrecorded' => TransfersTracking::missingNetSql(),
        ];
    }

    /**
     * Tranches matching every active filter, with no ordering and no eager
     * loads, so the table, its totals, the Overview ledger and the export all
     * build on it.
     *
     * The Overview ledger and the status counts pass $withStage false so they
     * keep describing the whole filtered set while a stage narrows the rows.
     *
     * @return Builder<TransfersTracking>
     */
    protected function filteredQuery(bool $withStage = true): Builder
    {
        $search = trim($this->search);
        $stages = $this->stageSql();

        return TransfersTracking::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->whereHas('donorProposal', function (Builder $query) use ($search): void {
                    $query
                        ->where('external_project_number', 'like', Like::contains($search))
                        ->orWhereHas('donor', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))
                        ->orWhereHas('proposal', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                            ->where('name', 'like', Like::contains($search))
                            ->orWhere('internal_project_number', 'like', Like::contains($search))));
                });
            })
            ->when($this->donor !== '', fn (Builder $query) => $query->whereHas('donorProposal', fn (Builder $query) => $query->where('donor_id', (int) $this->donor)))
            ->when($this->account !== '', fn (Builder $query) => $query->where('transfers_tracking.account_id', (int) $this->account))
            ->when((int) $this->agreement > 0, fn (Builder $query) => $query->where('transfers_tracking.donor_proposal_id', (int) $this->agreement))
            ->when($this->currency !== '', fn (Builder $query) => $query->where('transfers_tracking.amount_transfered_currency', $this->currency))
            ->when($this->from !== '' || $this->to !== '', fn (Builder $query) => $query->datedWithin($this->dateBasis, $this->from ?: null, $this->to ?: null))
            ->when($this->min !== '', fn (Builder $query) => $query->where('transfers_tracking.payment_amount', '>=', (float) $this->min))
            ->when($this->max !== '', fn (Builder $query) => $query->where('transfers_tracking.payment_amount', '<=', (float) $this->max))
            ->when($this->flag === 'converted', fn (Builder $query) => $query->converted())
            ->when($this->flag === 'no_account', fn (Builder $query) => $query->whereNull('transfers_tracking.account_id'))
            ->when(
                $withStage && array_key_exists($this->stage, $stages),
                fn (Builder $query) => $query->whereRaw($stages[$this->stage]),
            );
    }

    /**
     * The filtered tranches joined to the records their rows name, so the
     * table can be sorted and grouped by donor, proposal and account.
     *
     * @return Builder<TransfersTracking>
     */
    protected function registerQuery(): Builder
    {
        return $this->filteredQuery()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->join('donors', 'donors.id', '=', 'donor_proposal.donor_id')
            ->join('proposals', 'proposals.id', '=', 'donor_proposal.proposal_id')
            ->leftJoin('accounts', 'accounts.id', '=', 'transfers_tracking.account_id');
    }

    /**
     * What each column heading sorts by. Dates always put a missing date last;
     * amounts sort inside their currency, so figures in different currencies
     * are never ranked against each other.
     *
     * @return array<string, array{dir: 'asc'|'desc', order: list<string>}>
     */
    protected function registerSorts(): array
    {
        $table = 'transfers_tracking';
        $stages = $this->stageSql();
        $settled = $this->settledSql();
        $lastById = "{$table}.id desc";

        $byDate = fn (string $column): array => ["{$table}.{$column} is null", "{$table}.{$column} {dir}", $lastById];
        $byAmount = fn (string $currency, string $amount): array => ["{$table}.{$amount} is null", "{$table}.{$currency} asc", "{$table}.{$amount} {dir}", $lastById];

        return [
            'scheduled' => ['dir' => 'desc', 'order' => $byDate('scheduled_date')],
            'sent' => ['dir' => 'desc', 'order' => $byDate('transfer_date')],
            'received' => ['dir' => 'desc', 'order' => $byDate('receive_date')],
            'donor' => ['dir' => 'asc', 'order' => ['donors.name {dir}', 'proposals.name {dir}', $lastById]],
            'account' => ['dir' => 'asc', 'order' => ['accounts.account_name is null', 'accounts.account_name {dir}', $lastById]],
            'status' => ['dir' => 'asc', 'order' => [
                "case when {$stages['overdue']} then 0 when {$stages['failed']} then 1 when {$stages['transit']} then 2 when {$stages['upcoming']} then 3 else 4 end {dir}",
                "{$table}.scheduled_date is null",
                "{$table}.scheduled_date asc",
                $lastById,
            ]],
            'amount' => ['dir' => 'desc', 'order' => $byAmount('amount_transfered_currency', 'payment_amount')],
            'fees' => ['dir' => 'desc', 'order' => $byAmount('fees_currency', 'fees')],
            'rate' => ['dir' => 'desc', 'order' => ["{$table}.exchange_rate {dir}", $lastById]],
            'gross' => ['dir' => 'desc', 'order' => $byAmount('amount_received_currency', 'amount_received')],
            'net' => ['dir' => 'desc', 'order' => $byAmount('amount_received_currency', 'net_amount_received')],
            // Largest loss first. A tranche with no comparable ends sorts last
            // rather than passing for one that lost nothing.
            'difference' => ['dir' => 'asc', 'order' => [
                "case when {$settled} then 0 else 1 end",
                "{$table}.amount_transfered_currency asc",
                "({$table}.net_amount_received - {$table}.payment_amount) {dir}",
                $lastById,
            ]],
            // Overdue first and oldest-overdue first inside it, then outright
            // failures, then money that landed short, then money still moving.
            'attention' => ['dir' => 'asc', 'order' => [
                "case when {$stages['overdue']} then 0 when {$stages['failed']} then 1 when {$stages['short']} then 2 when {$stages['unrecorded']} then 3 when {$stages['transit']} then 4 else 5 end {dir}",
                "{$table}.scheduled_date is null",
                "{$table}.scheduled_date asc",
                $lastById,
            ]],
        ];
    }

    protected function registerDefaultSort(): string
    {
        return 'scheduled';
    }

    /**
     * The date column the date range and the month grouping read.
     */
    protected function dateColumn(): string
    {
        return 'transfers_tracking.'.TransfersTracking::DATE_COLUMNS[$this->dateBasis];
    }

    /**
     * The SQL expression whose value a group shares, or null when the table is
     * not grouped.
     */
    protected function groupKeySql(): ?string
    {
        return match ($this->group) {
            'agreement' => 'transfers_tracking.donor_proposal_id',
            'donor' => 'donor_proposal.donor_id',
            'account' => 'coalesce(transfers_tracking.account_id, 0)',
            'month' => "coalesce(date_format({$this->dateColumn()}, '%Y-%m'), '')",
            default => null,
        };
    }

    /**
     * Keep each group's rows together, ahead of the chosen sort.
     *
     * @param  Builder<TransfersTracking>  $query
     * @return Builder<TransfersTracking>
     */
    protected function groupOrder(Builder $query): Builder
    {
        return match ($this->group) {
            'agreement' => $query->orderBy('donors.name')->orderBy('proposals.name')->orderBy('transfers_tracking.donor_proposal_id'),
            'donor' => $query->orderBy('donors.name')->orderBy('donor_proposal.donor_id'),
            'account' => $query->orderByRaw('accounts.account_name is null')->orderBy('accounts.account_name')->orderBy('transfers_tracking.account_id'),
            'month' => $query->orderByRaw("{$this->dateColumn()} is null")->orderByRaw("date_format({$this->dateColumn()}, '%Y-%m') desc"),
            default => $query,
        };
    }

    /**
     * The group a row belongs to: the same value groupKeySql() gives it.
     */
    protected function groupKeyOf(TransfersTracking $transfer): string
    {
        return match ($this->group) {
            'agreement' => (string) $transfer->donor_proposal_id,
            'donor' => (string) $transfer->donorProposal->donor_id,
            'account' => (string) ($transfer->account_id ?? 0),
            'month' => $transfer->{TransfersTracking::DATE_COLUMNS[$this->dateBasis]}?->format('Y-m') ?? '',
            default => '',
        };
    }

    /**
     * What a group's header row calls it.
     */
    protected function groupLabelOf(TransfersTracking $transfer): string
    {
        $agreement = $transfer->donorProposal;

        return match ($this->group) {
            'agreement' => collect([$agreement->donor->name, $agreement->proposal->name, $agreement->external_project_number])->filter()->implode(' · '),
            'donor' => $agreement->donor->name,
            'account' => $transfer->account?->account_name ?? __('transfers.no_account'),
            'month' => $transfer->{TransfersTracking::DATE_COLUMNS[$this->dateBasis]}?->translatedFormat(__('formats.month_year')) ?? __('finance_tables.no_date'),
            default => '',
        };
    }

    /**
     * The table's visible page.
     *
     * @return LengthAwarePaginator<int, TransfersTracking>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $query = $this->registerQuery()
            ->select('transfers_tracking.*')
            ->with([
                'donorProposal:id,slug,donor_id,proposal_id,external_project_number',
                'donorProposal.donor:id,slug,name',
                'donorProposal.proposal:id,slug,name',
                // Nullable: a tranche may be scheduled before an account exists.
                'account:id,account_name',
            ]);

        return $this->applyRegisterSort($this->groupOrder($query))->paginate($this->registerPerPage());
    }

    /**
     * The Overview ladder's order.
     *
     * @param  Builder<TransfersTracking>  $query
     * @return Builder<TransfersTracking>
     */
    protected function applySort(Builder $query): Builder
    {
        $settled = $this->settledSql();
        $stages = $this->stageSql();

        $sorted = match ($this->sort) {
            // Proportional loss, so a small tranche bled dry outranks a large
            // one that lost a rounding error. Tranches with no comparable end
            // sort last rather than masquerading as losing nothing.
            'shortfall' => $query
                ->orderByRaw("case when {$settled} and payment_amount > 0 then (payment_amount - net_amount_received) / payment_amount else -1 end desc")
                ->orderByDesc('payment_amount'),
            'amount' => $query->orderByDesc('payment_amount'),
            'date' => $query->orderByRaw('scheduled_date is null, scheduled_date desc'),
            // Overdue first and oldest-overdue first inside it, then outright
            // failures, then money that landed short, then money still moving.
            default => $query
                ->orderByRaw("case
                    when {$stages['overdue']} then 0
                    when {$stages['failed']} then 1
                    when {$stages['short']} then 2
                    when {$stages['unrecorded']} then 3
                    when {$stages['transit']} then 4
                    else 5 end")
                ->orderByRaw('scheduled_date is null, scheduled_date asc'),
        };

        return $sorted->orderByDesc('id');
    }

    /**
     * The Overview ladder's visible page.
     *
     * @return LengthAwarePaginator<int, TransfersTracking>
     */
    #[Computed]
    public function transfers(): LengthAwarePaginator
    {
        $query = $this->filteredQuery()
            ->select([
                'id', 'donor_proposal_id', 'account_id', 'payment_amount', 'amount_transfered_currency',
                'scheduled_date', 'transfer_date', 'fees', 'fees_currency', 'amount_received',
                'exchange_rate', 'net_amount_received', 'amount_received_currency', 'receive_date', 'ar_status',
            ])
            ->with([
                'donorProposal:id,donor_id,proposal_id,external_project_number',
                'donorProposal.donor:id,name',
                'donorProposal.proposal:id,name,internal_project_number',
                // Nullable: a tranche may be scheduled before an account exists.
                'account:id,account_name,is_active',
            ]);

        return $this->applySort($query)->paginate(20);
    }

    /**
     * One line per currency money was sent in: what left the donor, what
     * actually landed, and what the journey took out of it.
     *
     * Every figure is a grouped aggregate so the line costs one query however
     * many tranches exist. Currencies are grouped, never added: a USD line and
     * a EUR line are two separate stories on the same page.
     *
     * Only settled tranches feed the shortfall. Money still travelling has not
     * lost anything yet, and saying otherwise would invent leakage.
     *
     * The shortfall is the sum of each tranche's own loss, never the
     * difference between two totals. A tranche that arrived over — a
     * favourable rate, a donor rounding up — is real, but it did not put the
     * money back into the tranche that lost it, so it is reported beside the
     * shortfall and never allowed to cancel it. Netting the two is how a page
     * ends up announcing "landed in full" over money that never arrived.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function ledgerLines(bool $withStage): Collection
    {
        $settled = $this->settledSql();
        $stages = $this->stageSql();

        // Per tranche: what it lost, and how much of that loss the recorded
        // fee explains. Written as CASE rather than LEAST so the expression
        // does not depend on one database's scalar function set.
        $rowShortfall = "(case when {$settled} and net_amount_received < payment_amount then payment_amount - net_amount_received else 0 end)";
        $rowFees = "(case
            when not ({$settled}) then 0
            when net_amount_received >= payment_amount then 0
            when fees_currency <> amount_transfered_currency then 0
            when fees < payment_amount - net_amount_received then fees
            else payment_amount - net_amount_received end)";

        return $this->filteredQuery($withStage)
            ->selectRaw("
                amount_transfered_currency as currency,
                count(*) as tranches,
                coalesce(sum(payment_amount), 0) as expected,
                coalesce(sum(case when {$settled} then payment_amount else 0 end), 0) as settled_sent,
                coalesce(sum(case when {$settled} then net_amount_received else 0 end), 0) as landed,
                coalesce(sum({$rowShortfall}), 0) as shortfall,
                coalesce(sum({$rowFees}), 0) as fees,
                coalesce(sum(case when {$settled} and net_amount_received > payment_amount then net_amount_received - payment_amount else 0 end), 0) as overage,
                coalesce(sum(case when {$stages['overdue']} then payment_amount else 0 end), 0) as overdue,
                sum(case when {$stages['overdue']} then 1 else 0 end) as overdue_count,
                coalesce(sum(case when {$stages['transit']} then payment_amount else 0 end), 0) as transit,
                sum(case when {$stages['transit']} then 1 else 0 end) as transit_count,
                coalesce(sum(case when {$stages['upcoming']} then payment_amount else 0 end), 0) as upcoming,
                sum(case when {$stages['upcoming']} then 1 else 0 end) as upcoming_count,
                coalesce(sum(case when {$stages['failed']} then payment_amount else 0 end), 0) as failed,
                sum(case when {$stages['failed']} then 1 else 0 end) as failed_count,
                sum(case when {$stages['short']} then 1 else 0 end) as short_count,
                sum(case when {$stages['unrecorded']} then 1 else 0 end) as unrecorded_count,
                coalesce(sum(case when {$stages['unrecorded']} then payment_amount else 0 end), 0) as unrecorded_amount,
                sum(case when ar_status = 'received' and amount_received_currency <> amount_transfered_currency then 1 else 0 end) as converted_count,
                coalesce(sum(case when ar_status = 'received' and amount_received_currency <> amount_transfered_currency then payment_amount else 0 end), 0) as converted_amount,
                coalesce(sum(case when transfer_date is not null then payment_amount else 0 end), 0) as actually_sent,
                coalesce(sum(case when ar_status = 'received' and amount_received_currency = amount_transfered_currency then coalesce(amount_received, 0) else 0 end), 0) as gross,
                coalesce(sum(case when fees_currency = amount_transfered_currency then fees else 0 end), 0) as recorded_fees,
                sum(case when fees > 0 and fees_currency <> amount_transfered_currency then 1 else 0 end) as fees_elsewhere_count
            ")
            ->groupBy('amount_transfered_currency')
            ->orderByRaw('sum(payment_amount) desc')
            ->get()
            ->map(function (TransfersTracking $row): array {
                $settledSent = (float) $row->getAttribute('settled_sent');
                $landed = (float) $row->getAttribute('landed');

                // Already summed per tranche in SQL, so a tranche that arrived
                // over cannot hide one that arrived short. Fees are named
                // first because they are the part someone recorded; whatever
                // is left is the exchange and the unexplained, and it is not
                // padded to look tidy.
                $shrink = (float) $row->getAttribute('shortfall');
                $feesTaken = (float) $row->getAttribute('fees');

                return [
                    'currency' => (string) $row->getAttribute('currency'),
                    'tranches' => (int) $row->getAttribute('tranches'),
                    // Everything this currency's tranches add up to, sent or not.
                    // Never labelled "sent": most of it may not have left yet.
                    'expected' => (float) $row->getAttribute('expected'),
                    // Only what actually left the donor, i.e. has a transfer date.
                    'sent' => (float) $row->getAttribute('actually_sent'),
                    'settledSent' => $settledSent,
                    'landed' => $landed,
                    'shrink' => $shrink,
                    'fees' => $feesTaken,
                    'exchangeGap' => max(0.0, $shrink - $feesTaken),
                    'overage' => (float) $row->getAttribute('overage'),
                    // Everything whose journey has not finished: still to be sent,
                    // moving, failed, converted, or received without a net amount.
                    'inFlight' => max(0.0, (float) $row->getAttribute('expected') - $settledSent),
                    'overdue' => (float) $row->getAttribute('overdue'),
                    'overdueCount' => (int) $row->getAttribute('overdue_count'),
                    'transit' => (float) $row->getAttribute('transit'),
                    'transitCount' => (int) $row->getAttribute('transit_count'),
                    'upcoming' => (float) $row->getAttribute('upcoming'),
                    'upcomingCount' => (int) $row->getAttribute('upcoming_count'),
                    'failed' => (float) $row->getAttribute('failed'),
                    'failedCount' => (int) $row->getAttribute('failed_count'),
                    'shortCount' => (int) $row->getAttribute('short_count'),
                    'unrecordedCount' => (int) $row->getAttribute('unrecorded_count'),
                    'unrecordedAmount' => (float) $row->getAttribute('unrecorded_amount'),
                    'convertedCount' => (int) $row->getAttribute('converted_count'),
                    'convertedAmount' => (float) $row->getAttribute('converted_amount'),
                    // The table's own columns: what the bank credited before its
                    // charges, and every fee recorded in the currency sent.
                    'gross' => (float) $row->getAttribute('gross'),
                    'recordedFees' => (float) $row->getAttribute('recorded_fees'),
                    'feesElsewhereCount' => (int) $row->getAttribute('fees_elsewhere_count'),
                ];
            });
    }

    /**
     * The Overview ledger. It deliberately ignores the stage filter, so its
     * figures keep describing the whole filtered set while a stage narrows
     * only the ladder beneath it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function ledger(): Collection
    {
        return $this->ledgerLines(withStage: false);
    }

    /**
     * The table's totals. They respect every filter, status included, so the
     * totals line and the footer always add up to the rows shown.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function totals(): Collection
    {
        return $this->ledgerLines(withStage: true);
    }

    /**
     * How many tranches sit at each stage under every other filter, for the
     * status pills.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function stageCounts(): array
    {
        $stages = $this->stageSql();

        $counts = $this->filteredQuery(withStage: false)
            ->toBase()
            ->selectRaw(
                'count(*) as n_all, '.collect($stages)
                    ->map(fn (string $condition, string $stage): string => "coalesce(sum(case when {$condition} then 1 else 0 end), 0) as n_{$stage}")
                    ->implode(', ')
            )
            ->first();

        return collect(['all', ...array_keys($stages)])
            ->mapWithKeys(fn (string $stage): array => [$stage => (int) ($counts->{'n_'.$stage} ?? 0)])
            ->all();
    }

    /**
     * Each group's subtotals per currency, over every filtered tranche and not
     * only the visible page, so a group split across two pages shows its true
     * subtotal on both. Keyed by the group's key.
     *
     * @return Collection<string, Collection<int, object>>
     */
    #[Computed]
    public function groupTotals(): Collection
    {
        $key = $this->groupKeySql();

        if ($key === null) {
            return new Collection;
        }

        $settled = $this->settledSql();
        $table = 'transfers_tracking';

        return $this->filteredQuery()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->toBase()
            ->selectRaw("
                {$key} as group_key,
                {$table}.amount_transfered_currency as currency,
                count(*) as tranches,
                coalesce(sum({$table}.payment_amount), 0) as sent,
                coalesce(sum(case when {$table}.fees_currency = {$table}.amount_transfered_currency then {$table}.fees else 0 end), 0) as fees,
                coalesce(sum(case when {$table}.ar_status = 'received' and {$table}.amount_received_currency = {$table}.amount_transfered_currency then coalesce({$table}.amount_received, 0) else 0 end), 0) as gross,
                coalesce(sum(case when {$settled} then {$table}.net_amount_received else 0 end), 0) as net,
                coalesce(sum(case when {$settled} and {$table}.net_amount_received < {$table}.payment_amount then {$table}.payment_amount - {$table}.net_amount_received else 0 end), 0) as shortfall,
                coalesce(sum(case when {$settled} and {$table}.net_amount_received > {$table}.payment_amount then {$table}.net_amount_received - {$table}.payment_amount else 0 end), 0) as overage
            ")
            ->groupByRaw("{$key}, {$table}.amount_transfered_currency")
            ->orderBy('currency')
            ->get()
            ->groupBy(fn (object $row): string => (string) $row->group_key);
    }

    /**
     * Every tranche the filters match, in the table's order, as a CSV file.
     *
     * The rows are read lazily and name their donor, proposal and account from
     * the joined columns, so a large export holds one tranche in memory at a
     * time.
     */
    public function exportCsv(): StreamedResponse
    {
        Gate::authorize('transfers.view');

        $transfers = $this->applyRegisterSort($this->groupOrder($this->registerQuery()))
            ->select([
                'transfers_tracking.*',
                'donors.name as donor_name',
                'proposals.name as proposal_name',
                'donor_proposal.external_project_number as agreement_reference',
                'accounts.account_name as landing_account',
            ])
            ->cursor();

        $rows = (function () use ($transfers) {
            foreach ($transfers as $transfer) {
                yield Tranches::csvRow(
                    $transfer,
                    (string) $transfer->getAttribute('donor_name'),
                    (string) $transfer->getAttribute('proposal_name'),
                    $transfer->getAttribute('agreement_reference'),
                    $transfer->getAttribute('landing_account'),
                );
            }
        })();

        return CsvExport::download('transfers-'.CarbonImmutable::today()->toDateString().'.csv', Tranches::csvHeadings(), $rows);
    }

    /**
     * Whether any tranche exists at all, so an empty page can tell the
     * difference between "nothing recorded yet" and "nothing matches".
     */
    #[Computed]
    public function hasAnyTransfer(): bool
    {
        return TransfersTracking::query()->exists();
    }

    /**
     * A page narrowed to one agreement opens the new tranche on it, with its
     * currency, as recording one from the agreement page would.
     */
    public function openCreateModal(): void
    {
        $agreementId = (int) $this->agreement;

        $this->openTransferCreate($agreementId > 0 && DonorProposal::query()->whereKey($agreementId)->exists() ? $agreementId : null);
    }

    public function openEditModal(int $transferId): void
    {
        $this->openTransferEdit($transferId);
    }

    protected function transferSaved(TransfersTracking $transfer, bool $isCreate): void
    {
        $this->resetPage();
    }

    protected function transferDeleted(int $transferId): void
    {
        $this->resetPage();
    }
};
?>

@php
    $showsOverview = $this->view === 'overview';
    $activeFilterCount = collect([$this->search, $this->donor, $this->account, $this->currency, $this->stage, $this->flag, $this->from, $this->to, $this->min, $this->max, $this->agreement])
        ->filter(fn (string $value): bool => $value !== '')
        ->count();
    $total = $showsOverview ? $this->transfers->total() : $this->rows->total();

    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';

    /* The closures below format the Overview view (_overview.blade.php). The table prints exact amounts itself. */
    $amount = fn (float $value): string => Number::format($value, maxPrecision: 0);
    $exact = fn (float $value): string => number_format($value, 2);
    /**
     * A percentage that never rounds a real loss down to zero. A 30 USD fee on
     * a 100,000 USD tranche is 0.03% — the common case in this domain — and
     * printing it as a red "−0%" reads as a broken page, not as a small loss.
     */
    $percent = function (float $part, float $whole): string {
        if ($whole <= 0) {
            return '0';
        }

        $value = $part / $whole * 100;
        $formatted = Number::format($value, maxPrecision: 1);

        return $value > 0 && (float) str_replace(',', '', $formatted) === 0.0
            ? __('transfers.under_a_tenth_of_a_percent')
            : $formatted;
    };

    /**
     * Whole units, except for an amount that would otherwise round to zero:
     * a shortfall of 0.40 is still a shortfall and must not print as "0".
     */
    $amountAtLeast = fn (float $value): string => $value > 0 && $value < 1
        ? number_format($value, 2)
        : Number::format($value, maxPrecision: 0);

    /**
     * An exchange rate with its trailing zeros dropped. Trimming the raw
     * decimal:6 string would turn a rate of 0 into an empty cell.
     */
    $rate = function (string $value): string {
        $trimmed = rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    };

    $sortOptions = [
        'attention' => __('transfers.sort_attention'),
        'shortfall' => __('transfers.sort_shortfall'),
        'date' => __('transfers.sort_date'),
        'amount' => __('transfers.sort_amount'),
    ];

    /* Filter pills. `$chosen` is read instead of the properties so a loop variable can never shadow one. */
    $chosen = [
        'year' => $this->year,
        'dateBasis' => $this->dateBasis,
        'currency' => $this->currency,
        'stage' => $this->stage,
        'flag' => $this->flag,
        'group' => $this->group,
    ];
    $sortKey = $this->activeSort();
    $sortDirection = $this->activeSortDir();
    $counts = $this->hasAnyTransfer ? $this->stageCounts : [];

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

    $yearPills = [
        ['default' => true, 'label' => __('finance_tables.all_years'), 'model' => 'year', 'value' => '', 'active' => $chosen['year'] === ''],
        ...array_map(fn (int $option): array => $setPill('year', (string) $option, (string) $option), $this->yearOptions),
    ];
    $basisPills = [
        $setPill('dateBasis', 'scheduled', __('finance_tables.basis_scheduled')),
        $setPill('dateBasis', 'sent', __('finance_tables.basis_sent')),
        $setPill('dateBasis', 'received', __('finance_tables.basis_received')),
    ];
    $statusPills = [
        ['default' => true, 'label' => __('finance_tables.all'), 'model' => 'stage', 'value' => '', 'active' => $chosen['stage'] === '', 'count' => $counts['all'] ?? 0, 'test' => 'stage-all'],
        $stagePill('upcoming', __('finance_tables.state_upcoming')),
        $stagePill('overdue', __('finance_tables.state_late'), 'critical'),
        $stagePill('transit', __('finance_tables.state_transit'), 'warn'),
        $stagePill('received', __('finance_tables.state_received')),
        $stagePill('failed', __('finance_tables.state_failed'), 'critical'),
    ];
    $currencyPills = [
        ['default' => true, 'label' => __('finance_tables.all'), 'model' => 'currency', 'value' => '', 'active' => $chosen['currency'] === ''],
        ...array_map(fn (Currency $case): array => $setPill('currency', $case->value, $case->value), Currency::cases()),
    ];
    /* Landed short and net-not-recorded are stages of a received tranche, so they share the status property. */
    $flagPills = [
        $stagePill('short', __('finance_tables.flag_short'), 'critical'),
        $stagePill('unrecorded', __('finance_tables.flag_unrecorded'), 'warn'),
        ['label' => __('finance_tables.flag_converted'), 'model' => 'flag', 'value' => 'converted', 'toggle' => true, 'active' => $chosen['flag'] === 'converted', 'test' => 'flag-converted'],
        ['label' => __('finance_tables.flag_no_account'), 'model' => 'flag', 'value' => 'no_account', 'toggle' => true, 'active' => $chosen['flag'] === 'no_account', 'test' => 'flag-no-account'],
    ];
    $groupPills = [
        ['default' => true, 'label' => __('finance_tables.group_none'), 'model' => 'group', 'value' => '', 'active' => $chosen['group'] === ''],
        $setPill('group', 'agreement', __('finance_tables.group_agreement')),
        $setPill('group', 'donor', __('finance_tables.group_donor')),
        $setPill('group', 'account', __('finance_tables.group_account')),
        $setPill('group', 'month', __('finance_tables.group_month')),
    ];
    $orderPills = [
        ['label' => __('finance_tables.needs_attention_first'), 'model' => 'sortBy', 'value' => 'attention', 'toggle' => true, 'also' => ['sortDir' => 'asc'], 'active' => $sortKey === 'attention', 'test' => 'order-attention'],
    ];
@endphp

<section
    class="@container/ladder flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink"
    aria-labelledby="transfer-ladder-heading"
>
    <x-ui.page-header
        heading-id="transfer-ladder-heading"
        :title="__('common.transfers')"
        :count="$total"
    >
        <x-slot:actions>
            <div class="flex flex-wrap items-center justify-end gap-2">
                @if ($this->hasAnyTransfer)
                    <x-funding.filters :active="$activeFilterCount" :results="$total">
                        <div class="directory-filters grid gap-3 sm:grid-cols-2">
                            <x-ui.input type="search" icon="magnifying-glass" :label="__('transfers.search_transfers')" :placeholder="__('common.reference_donor_or_proposal')" wire:model="search" clearable />

                            <x-ui.header-filter-select deferred :label="__('funding_agreements.donor')" wire:model="donor" :value="$this->donor">
                                <flux:select.option value="">{{ __('funding_agreements.all_donors') }}</flux:select.option>
                                @foreach ($this->filterDonors as $donorOption)
                                    <flux:select.option :value="$donorOption->id" :wire:key="'tr-donor-'.$donorOption->id">{{ $donorOption->name }}</flux:select.option>
                                @endforeach
                            </x-ui.header-filter-select>

                            <x-ui.header-filter-select deferred :label="__('transfers.account')" wire:model="account" :value="$this->account">
                                <flux:select.option value="">{{ __('common.all_accounts') }}</flux:select.option>
                                @foreach ($this->filterAccounts as $accountOption)
                                    <flux:select.option :value="$accountOption->id" :wire:key="'tr-account-'.$accountOption->id">
                                        {{ $accountOption->account_name }}@unless ($accountOption->is_active) · {{ __('common.closed') }}@endunless
                                    </flux:select.option>
                                @endforeach
                            </x-ui.header-filter-select>
                        </div>

                        <x-funding.pills :label="__('finance_tables.year')" :pills="$yearPills" />

                        <x-funding.pills :label="__('finance_tables.date_is')" :pills="$basisPills" :on="$this->from !== '' || $this->to !== ''" :watch="['from', 'to']">
                            <span class="ms-1 text-xs font-semibold {{ $mutedText }}">{{ __('finance_tables.from') }}</span>
                            <x-ui.input type="date" size="sm" class="w-40" wire:model="from" :aria-label="__('finance_tables.from')" />
                            <span class="text-xs font-semibold {{ $mutedText }}">{{ __('finance_tables.to') }}</span>
                            <x-ui.input type="date" size="sm" class="w-40" wire:model="to" :aria-label="__('finance_tables.to')" />
                        </x-funding.pills>

                        <x-funding.pills :label="__('common.status')" :pills="$statusPills" />
                        <x-funding.pills :label="__('common.currency')" :pills="$currencyPills" />
                        <x-funding.pills :label="__('finance_tables.flags')" :pills="$flagPills" />

                        <x-funding.filter-row :label="__('finance_tables.amount')" :on="$this->min !== '' || $this->max !== ''" :watch="['min', 'max']">
                            <x-ui.input type="number" inputmode="decimal" min="0" step="0.01" size="sm" class="w-36" wire:model="min" :placeholder="__('finance_tables.minimum')" :aria-label="__('finance_tables.minimum')" />
                            <span class="text-xs font-semibold {{ $mutedText }}">{{ __('finance_tables.to') }}</span>
                            <x-ui.input type="number" inputmode="decimal" min="0" step="0.01" size="sm" class="w-36" wire:model="max" :placeholder="__('finance_tables.maximum')" :aria-label="__('finance_tables.maximum')" />
                        </x-funding.filter-row>

                        @unless ($showsOverview)
                            <x-funding.pills :label="__('finance_tables.group_by')" :pills="$groupPills" keep />
                            <x-funding.pills :label="__('finance_tables.order')" :pills="$orderPills" keep />
                        @endunless

                        @if ($this->agreementFilter !== null)
                            <x-funding.filter-row :label="__('finance_tables.group_agreement')" on :watch="['agreement']" :models="['agreement']" x-show="filled(['agreement'])">
                                <button type="button" x-on:click="pick('agreement', '')" class="inline-flex min-h-8 items-center gap-1.5 rounded-full bg-funding-soft ps-3 pe-2 text-xs font-semibold text-funding transition-colors hover:bg-funding hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-ink dark:hover:text-surface-0" data-agreement-chip>
                                    {{ __('transfers.agreement_filter_chip', ['donor' => $this->agreementFilter->donor->name, 'proposal' => $this->agreementFilter->proposal->name]) }}
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

                @if ($this->hasAnyTransfer)
                    <x-ui.button tone="action" icon="arrow-down-tray" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" data-test="export-csv">
                        {{ __('finance_tables.export_csv') }}
                    </x-ui.button>
                @endif

                @can('transfers.create')
                <x-ui.button tone="primary" icon="plus" class="page-action page-action-primary" wire:click="openCreateModal" data-test="create-transfer-button">
                    {{ __('transfers.record_transfer') }}
                </x-ui.button>
                @endcan
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $this->hasAnyTransfer)
        <x-ui.empty-state
            class="rtl:[&_svg]:-scale-x-100"
            icon="arrow-right-circle"
            :heading="__('transfers.no_transfers_recorded')"
            :body="__('transfers.record_the_first_tranche_against_a_funding_agreement')"
        />
    @elseif ($showsOverview)
        @include('pages.transfers._overview')
    @elseif ($this->rows->isEmpty())
        <x-ui.empty-state
            class="rtl:[&_svg]:-scale-x-100"
            icon="arrow-right-circle"
            :heading="__('transfers.no_matching_transfers')"
            :body="__('common.change_the_search_or_filters_and_try_again')"
        />
    @else
        @php
            $totalLines = $this->totals->map(fn (array $line): array => [
                'currency' => $line['currency'],
                'caption' => trans_choice('transfers.count_tranches', $line['tranches'], ['count' => $line['tranches']]),
                'figures' => array_values(array_filter([
                    ['key' => 'expected', 'label' => __('transfers.expected'), 'value' => $line['expected']],
                    ['key' => 'sent', 'label' => __('transfers.sent'), 'value' => $line['sent']],
                    ['key' => 'received', 'label' => __('finance_tables.received_net'), 'value' => $line['landed'], 'tone' => 'good'],
                    ['key' => 'fees', 'label' => __('transfers.fees'), 'value' => $line['recordedFees']],
                    ['key' => 'shortfall', 'label' => __('transfers.shortfall'), 'value' => $line['shrink'], 'prefix' => $line['shrink'] > 0 ? '−' : '', 'tone' => $line['shrink'] > 0 ? 'critical' : null],
                    $line['overage'] > 0 ? ['key' => 'overage', 'label' => __('transfers.arrived_over'), 'value' => $line['overage'], 'prefix' => '+', 'tone' => 'good'] : null,
                    ['key' => 'outstanding', 'label' => __('finance_tables.outstanding'), 'value' => $line['inFlight']],
                ])),
                'notes' => array_values(array_filter([
                    $line['convertedCount'] > 0 ? trans_choice('transfers.count_tranches_landed_in_another_currency', $line['convertedCount'], ['count' => $line['convertedCount'], 'amount' => number_format($line['convertedAmount'], 2).' '.$line['currency']]) : null,
                    $line['unrecordedCount'] > 0 ? trans_choice('transfers.count_tranches_missing_net', $line['unrecordedCount'], ['count' => $line['unrecordedCount'], 'amount' => number_format($line['unrecordedAmount'], 2).' '.$line['currency']]) : null,
                    $line['feesElsewhereCount'] > 0 ? trans_choice('finance_tables.count_fees_in_another_currency', $line['feesElsewhereCount'], ['count' => $line['feesElsewhereCount']]) : null,
                ])),
            ])->all();


            $isGrouped = $this->group !== '';
            $previousGroup = null;
        @endphp

        <x-funding.totals :lines="$totalLines" />

        <x-funding.register size="xl" :caption="__('finance_tables.transfers_caption')">
            <x-slot:head>
                <x-funding.register.th sort="donor" :active="$sortKey" :dir="$sortDirection">{{ __('finance_tables.donor_agreement') }}</x-funding.register.th>
                <x-funding.tranche-head sortable :active="$sortKey" :dir="$sortDirection" />
            </x-slot:head>

            @foreach ($this->rows as $transfer)
                @php
                    $rowAgreement = $transfer->donorProposal;
                    $groupKey = $isGrouped ? $this->groupKeyOf($transfer) : null;
                @endphp

                @if ($isGrouped && $groupKey !== $previousGroup)
                    <x-funding.tranche-group
                        :label="$this->groupLabelOf($transfer)"
                        :group="$groupKey"
                        :lines="$this->groupTotals->get($groupKey, [])"
                        :lead="1"
                    />
                    @php
                        $previousGroup = $groupKey;
                    @endphp
                @endif

                <x-funding.tranche-row :transfer="$transfer">
                    <x-slot:lead>
                        <td data-label="{{ __('finance_tables.donor_agreement') }}" data-wide>
                            @can('donors.view')
                                <a href="{{ route('donors.show', $rowAgreement->donor) }}" wire:navigate class="font-semibold underline-offset-2 hover:underline">{{ $rowAgreement->donor->name }}</a>
                            @else
                                <span class="font-semibold">{{ $rowAgreement->donor->name }}</span>
                            @endcan
                            <span class="sub">
                                @can('proposals.view')
                                    <a href="{{ route('proposals.show', $rowAgreement->proposal) }}" wire:navigate class="underline-offset-2 hover:underline">{{ $rowAgreement->proposal->name }}</a>
                                @else
                                    {{ $rowAgreement->proposal->name }}
                                @endcan
                                ·
                                @can('funding-agreements.view')
                                    <a href="{{ route('funding-agreements.show', $rowAgreement) }}" wire:navigate class="underline-offset-2 hover:underline" data-agreement-link>{{ filled($rowAgreement->external_project_number) ? $rowAgreement->external_project_number : __('proposals.view_agreement') }}</a>
                                @else
                                    {{ $rowAgreement->external_project_number ?? __('common.no_external_project_number') }}
                                @endcan
                            </span>
                        </td>
                    </x-slot:lead>

                    <x-slot:actions>
                        <td>
                            @can('transfers.update')
                                {{-- Deleting a tranche happens inside its edit form. --}}
                                <span class="flex items-center justify-end">
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" icon:variant="outline" class="size-8!" wire:click="openEditModal({{ $transfer->id }})" :aria-label="__('transfers.edit_transfer')" data-test="edit-transfer-{{ $transfer->id }}" />
                                </span>
                            @endcan
                        </td>
                    </x-slot:actions>
                </x-funding.tranche-row>
            @endforeach
        </x-funding.register>

        <x-funding.pager :paginator="$this->rows" :per-page="(string) $this->registerPerPage()" :sizes="$this->registerPageSizes()" scroll-to="#transfer-ladder-heading" />
    @endif

    <x-funding.transfer-modal
        :editing="$editingTransferId !== null"
        :agreement-options="$showTransferModal ? $this->agreementOptions : []"
        :account-options="$showTransferModal ? $this->accountOptions : []"
        :confirming-delete="$confirmingTransferDelete"
    />
</section>
