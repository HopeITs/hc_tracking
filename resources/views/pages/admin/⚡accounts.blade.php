<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Models\Account;
use App\Models\TransfersTracking;
use App\Support\AccountMoney;
use App\Support\AgreementMoney;
use App\Support\CsvExport;
use App\Support\Like;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('titles.accounts')] class extends Component {
    use FiltersFinanceRegister;
    use WithPagination;

    /** @var list<string> */
    public const FLAGS = ['has_transfers', 'no_transfers', 'in_transit'];

    /**
     * Every property a filter control writes. The account form's own fields
     * (`currency`, `country`, …) are not filters, which is why the filters
     * carry a `Filter` suffix.
     *
     * @var list<string>
     */
    private const FILTERS = ['search', 'state', 'currencyFilter', 'countryFilter', 'flag'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $state = '';

    #[Url(except: '')]
    public string $currencyFilter = '';

    #[Url(as: 'country', except: '')]
    public string $countryFilter = '';

    #[Url(except: '')]
    public string $flag = '';

    public bool $showAccountModal = false;
    #[Locked]
    public ?int $editingAccountId = null;
    public string $accountName = '';
    public string $bankName = '';
    public string $currency = 'USD';
    public string $authorizedPerson = '';
    public string $accountNumber = '';
    public string $iban = '';
    public string $swiftCode = '';
    public string $country = '';
    public string $poBoxStreet = '';
    public string $companyAddress = '';
    public string $openedAt = '';

    /**
     * Read-only context shown in the edit modal alongside the editable
     * fields — is_active/closed_at are deliberately not editable here (they
     * only change through Account::close()), but the user should still see
     * them rather than the form looking incomplete.
     */
    public bool $editingAccountIsActive = true;
    public ?string $editingAccountClosedAt = null;
    public int $editingAccountTransfersCount = 0;

    /**
     * Paired with $closingAccountId — see the flux:modal binding note below.
     */
    public bool $showCloseModal = false;
    #[Locked]
    public ?int $closingAccountId = null;

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
        if (! in_array($this->state, ['active', 'closed'], true)) {
            $this->state = '';
        }

        if (Currency::tryFrom($this->currencyFilter) === null) {
            $this->currencyFilter = '';
        }

        if (! in_array($this->countryFilter, $this->countryOptions, true)) {
            $this->countryFilter = '';
        }

        if (! in_array($this->flag, self::FLAGS, true)) {
            $this->flag = '';
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'state', 'currencyFilter', 'countryFilter', 'flag']);
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
     * The countries accounts are held in, for the country picker.
     *
     * @return list<string>
     */
    #[Computed]
    public function countryOptions(): array
    {
        return Account::query()->distinct()->orderBy('country')->pluck('country')->all();
    }

    /**
     * Only people who may change accounts see full account numbers and can
     * find an account by its number; everyone else sees the last four
     * characters, so a search cannot confirm a guessed number either.
     */
    #[Computed]
    public function showsFullAccountNumbers(): bool
    {
        return auth()->user()->can('accounts.update');
    }

    /**
     * Accounts matching every active filter, with no ordering, so the table,
     * its totals, the Overview cards and the export all build on it. Flags and
     * the year are conditions on the account's tranches rather than a join, so
     * the Overview view needs nothing extra.
     *
     * @return Builder<Account>
     */
    protected function filteredQuery(): Builder
    {
        $search = trim($this->search);

        return Account::query()
            ->when(
                $search !== '',
                fn (Builder $query) => $query->where(fn (Builder $query) => $query
                    ->where('accounts.account_name', 'like', Like::contains($search))
                    ->orWhere('accounts.bank_name', 'like', Like::contains($search))
                    ->orWhere('accounts.account_number_last4', Account::lastFour($search))
                    ->when($this->showsFullAccountNumbers, fn (Builder $query) => $query->orWhereIn('accounts.id', Account::idsMatchingAccountNumber($search)))),
            )
            ->when($this->state === 'active', fn (Builder $query) => $query->where('accounts.is_active', true))
            ->when($this->state === 'closed', fn (Builder $query) => $query->where('accounts.is_active', false))
            ->when($this->currencyFilter !== '', fn (Builder $query) => $query->where('accounts.currency', $this->currencyFilter))
            ->when($this->countryFilter !== '', fn (Builder $query) => $query->where('accounts.country', $this->countryFilter))
            ->when($this->flag === 'has_transfers', fn (Builder $query) => $query->has('transfers'))
            ->when($this->flag === 'no_transfers', fn (Builder $query) => $query->doesntHave('transfers'))
            ->when($this->flag === 'in_transit', fn (Builder $query) => $query->whereHas('transfers', fn (Builder $query) => $query->inState('transit')))
            ->when($this->selectedYear() !== null, fn (Builder $query) => $this->applyYear($query));
    }

    /**
     * Keep only accounts with money in the chosen year: a tranche due in it
     * or, while the lifetime figures are shown, one that landed in it.
     *
     * @param  Builder<Account>  $query
     * @return Builder<Account>
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
     * The Overview view's cards, open accounts first.
     *
     * @return LengthAwarePaginator<int, Account>
     */
    #[Computed]
    public function accounts(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->select(['id', 'account_name', 'bank_name', 'currency', 'account_number', 'country', 'opened_at', 'closed_at', 'is_active', 'updated_at'])
            ->withCount('transfers')
            ->withMax('transfers as last_transfer_at', 'transfer_date')
            ->orderByDesc('is_active')
            ->orderBy('account_name')
            ->orderBy('id')
            ->paginate(15);
    }

    /**
     * The filtered accounts joined to their money, which is one grouped join
     * rather than a loaded relation: that is what lets a money column be
     * sorted, totalled and exported.
     *
     * @return Builder<Account>
     */
    protected function registerQuery(): Builder
    {
        return $this->filteredQuery()
            ->leftJoinSub(AccountMoney::perAccount($this->selectedYear(), $this->yearOnly()), 'money', 'money.account_id', '=', 'accounts.id')
            ->select(['accounts.id', 'accounts.account_name', 'accounts.bank_name', 'accounts.currency', 'accounts.account_number', 'accounts.country', 'accounts.opened_at', 'accounts.closed_at', 'accounts.is_active'])
            ->selectRaw(AccountMoney::select());
    }

    /**
     * What each column heading sorts by. Amounts sort inside their currency.
     *
     * @return array<string, array{dir: 'asc'|'desc', order: list<string>}>
     */
    protected function registerSorts(): array
    {
        $byName = 'accounts.account_name asc';
        $lastById = 'accounts.id asc';
        $text = fn (string $column): array => ['dir' => 'asc', 'order' => ["accounts.{$column} {dir}", $byName, $lastById]];
        $date = fn (string $expression): array => ['dir' => 'desc', 'order' => ["{$expression} is null", "{$expression} {dir}", $byName, $lastById]];
        $money = fn (string $column): array => ['dir' => 'desc', 'order' => ['accounts.currency asc', "coalesce(money.{$column}, 0) {dir}", $byName, $lastById]];

        return [
            'account' => ['dir' => 'asc', 'order' => ['accounts.account_name {dir}', $lastById]],
            'bank' => $text('bank_name'),
            'country' => $text('country'),
            'currency' => $text('currency'),
            // Open accounts first.
            'state' => ['dir' => 'desc', 'order' => ['accounts.is_active {dir}', $byName, $lastById]],
            'opened' => $date('accounts.opened_at'),
            'closed' => $date('accounts.closed_at'),
            'transfers' => ['dir' => 'desc', 'order' => ['coalesce(money.a_transfers, 0) {dir}', $byName, $lastById]],
            'received' => $money('a_received'),
            'received_in_year' => $money('a_received_in_year'),
            'in_transit' => $money('a_in_transit'),
            'upcoming' => $money('a_upcoming'),
            'overdue' => $money('a_overdue'),
            'last_receipt' => $date('money.a_last_receipt'),
        ];
    }

    protected function registerDefaultSort(): string
    {
        return 'account';
    }

    /**
     * The table's visible page.
     *
     * @return LengthAwarePaginator<int, Account>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        return $this->applyRegisterSort($this->registerQuery())->paginate($this->registerPerPage());
    }

    /**
     * The table's totals, one line per account currency, for every account
     * the filters match.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function totals(): Collection
    {
        return $this->filteredQuery()
            ->leftJoinSub(AccountMoney::perAccount($this->selectedYear(), $this->yearOnly()), 'money', 'money.account_id', '=', 'accounts.id')
            ->toBase()
            ->selectRaw(AccountMoney::totalsSelect())
            ->groupBy('accounts.currency')
            ->orderBy('accounts.currency')
            ->get()
            ->map(fn (object $row): array => [
                'currency' => (string) $row->currency,
                'accounts' => (int) $row->accounts,
                'open_accounts' => (int) $row->open_accounts,
                'transfers' => (int) $row->transfers,
                'received' => (float) $row->received,
                'in_transit' => (float) $row->in_transit,
                'upcoming' => (float) $row->upcoming,
                'overdue' => (float) $row->overdue,
                'received_in_year' => (float) $row->received_in_year,
            ]);
    }

    /**
     * Whether any account exists at all, so an empty page can tell "nothing
     * opened yet" from "nothing matches".
     */
    #[Computed]
    public function hasAnyAccount(): bool
    {
        return Account::query()->exists();
    }

    /**
     * Every account the filters match, in the table's order, as a CSV file.
     * The account number follows the same rule as the screen: whole for the
     * people who may change accounts, masked for everyone else.
     */
    public function exportCsv(): StreamedResponse
    {
        Gate::authorize('accounts.view');

        $year = $this->selectedYear();
        $showsFullNumbers = $this->showsFullAccountNumbers;
        $accounts = $this->applyRegisterSort($this->registerQuery())->cursor();

        $headings = [
            __('accounts.account_name'),
            __('accounts.bank_name'),
            __('donors.country'),
            __('common.currency'),
            __('accounts.account_number'),
            __('common.state'),
            __('accounts.opened_on'),
            __('finance_tables.closed_on'),
            __('common.transfers'),
            __('finance_tables.received'),
            __('finance_tables.in_transit'),
            __('finance_tables.upcoming'),
            __('finance_tables.overdue'),
            __('finance_tables.last_receipt'),
            ...($year === null ? [] : [__('finance_tables.received_in_year', ['year' => $year])]),
        ];

        $rows = (function () use ($accounts, $year, $showsFullNumbers) {
            foreach ($accounts as $account) {
                yield [
                    $account->account_name,
                    $account->bank_name,
                    $account->country,
                    $account->currency->value,
                    $showsFullNumbers ? $account->account_number : $account->maskedAccountNumber(),
                    $account->is_active ? __('accounts.open') : __('accounts.closed'),
                    $account->opened_at,
                    $account->closed_at,
                    (int) $account->money_transfers,
                    CsvExport::amount($account->money_received),
                    CsvExport::amount($account->money_in_transit),
                    CsvExport::amount($account->money_upcoming),
                    CsvExport::amount($account->money_overdue),
                    $account->money_last_receipt,
                    ...($year === null ? [] : [CsvExport::amount($account->money_received_in_year)]),
                ];
            }
        })();

        return CsvExport::download('accounts-'.CarbonImmutable::today()->toDateString().'.csv', $headings, $rows);
    }

    /**
     * Net funds actually received into each visible account, split by
     * currency (currencies are never combined — see x-ui.money). Keyed by
     * account id, each value a currency-code => total map sorted largest
     * first. A second query against the page's own ids, same pattern
     * cities/⚡index.blade.php uses for its cross-connection project stats.
     *
     * @return Collection<int, Collection<string, float>>
     */
    #[Computed]
    public function accountMoneyStats(): Collection
    {
        $accountIds = $this->accounts->pluck('id');

        if ($accountIds->isEmpty()) {
            return collect();
        }

        return TransfersTracking::query()
            ->whereIn('account_id', $accountIds)
            ->where('ar_status', ArStatus::Received)
            ->whereNotNull('net_amount_received')
            ->select(['account_id', 'net_amount_received', 'amount_received_currency'])
            ->get()
            ->groupBy('account_id')
            ->map(fn (Collection $transfers): Collection => $transfers
                ->groupBy(fn (TransfersTracking $transfer): string => $transfer->amount_received_currency->value)
                ->map(fn (Collection $group): float => (float) $group->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received))
                ->sortDesc());
    }

    /**
     * Portfolio-wide figures for the summary strip — independent of the
     * current search, filter or page, matching cities/⚡index.blade.php.
     *
     * @return array{total: int, active: int, closed: int, transfers: int}
     */
    #[Computed]
    public function summary(): array
    {
        return [
            'total' => Account::count(),
            'active' => Account::query()->active()->count(),
            'closed' => Account::where('is_active', false)->count(),
            'transfers' => TransfersTracking::count(),
        ];
    }

    /**
     * Net funds received across every account, by currency — never combined
     * into one figure since USD/EUR/SYP are not convertible in this system.
     *
     * @return Collection<string, float>
     */
    #[Computed]
    public function fundsByCurrency(): Collection
    {
        return TransfersTracking::query()
            ->where('ar_status', ArStatus::Received)
            ->whereNotNull('net_amount_received')
            ->select(['net_amount_received', 'amount_received_currency'])
            ->get()
            ->groupBy(fn (TransfersTracking $transfer): string => $transfer->amount_received_currency->value)
            ->map(fn (Collection $group): float => (float) $group->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received))
            ->sortDesc();
    }

    public function openCreateModal(): void
    {
        Gate::authorize('accounts.create');

        $this->resetAccountForm();
        $this->showAccountModal = true;
    }

    public function openEditModal(int $accountId): void
    {
        Gate::authorize('accounts.update');

        $account = Account::query()
            ->select(['id', 'account_name', 'bank_name', 'currency', 'authorized_person', 'account_number', 'iban', 'swift_code', 'country', 'po_box_street', 'company_address', 'opened_at', 'closed_at', 'is_active'])
            ->withCount('transfers')
            ->findOrFail($accountId);

        $this->resetValidation();
        $this->editingAccountId = $account->id;
        $this->accountName = $account->account_name;
        $this->bankName = $account->bank_name;
        $this->currency = $account->currency->value;
        $this->authorizedPerson = $account->authorized_person ?? '';
        $this->accountNumber = $account->account_number;
        $this->iban = $account->iban ?? '';
        $this->swiftCode = $account->swift_code ?? '';
        $this->country = $account->country;
        $this->poBoxStreet = $account->po_box_street ?? '';
        $this->companyAddress = $account->company_address ?? '';
        $this->openedAt = $account->opened_at?->toDateString() ?? '';
        $this->editingAccountIsActive = $account->is_active;
        $this->editingAccountClosedAt = $account->closed_at?->toDateString();
        $this->editingAccountTransfersCount = $account->transfers_count;
        $this->showAccountModal = true;
    }

    public function saveAccount(): void
    {
        Gate::authorize($this->editingAccountId === null ? 'accounts.create' : 'accounts.update');

        $this->accountName = trim($this->accountName);
        $this->bankName = trim($this->bankName);
        $this->authorizedPerson = trim($this->authorizedPerson);
        $this->accountNumber = trim($this->accountNumber);
        $this->iban = trim($this->iban);
        $this->swiftCode = trim($this->swiftCode);
        $this->country = trim($this->country);
        $this->poBoxStreet = trim($this->poBoxStreet);
        $this->companyAddress = trim($this->companyAddress);

        $validated = $this->validate([
            'accountName' => ['required', 'string', 'max:255'],
            'bankName' => ['required', 'string', 'max:255'],
            'currency' => ['required', new Enum(Currency::class)],
            'authorizedPerson' => ['nullable', 'string', 'max:255'],
            'accountNumber' => ['required', 'string', 'max:100'],
            'iban' => ['nullable', 'string', 'max:100'],
            'swiftCode' => ['nullable', 'string', 'max:50'],
            'country' => ['required', 'string', 'max:100'],
            'poBoxStreet' => ['nullable', 'string'],
            'companyAddress' => ['nullable', 'string'],
            'openedAt' => ['nullable', 'date'],
        ], [
            'accountName.required' => __('accounts.enter_an_account_name'),
            'bankName.required' => __('accounts.enter_a_bank_name'),
            'accountNumber.required' => __('accounts.enter_an_account_number'),
            'country.required' => __('accounts.enter_the_country'),
        ]);

        $account = $this->editingAccountId === null
            ? new Account
            : Account::query()->findOrFail($this->editingAccountId);

        // is_active is deliberately not set here: the column default makes a
        // new account usable, and closing goes through Account::close().
        $account->fill([
            'account_name' => $validated['accountName'],
            'bank_name' => $validated['bankName'],
            'currency' => $validated['currency'],
            'authorized_person' => $validated['authorizedPerson'] === '' ? null : $validated['authorizedPerson'],
            'account_number' => $validated['accountNumber'],
            'iban' => $validated['iban'] === '' ? null : $validated['iban'],
            'swift_code' => $validated['swiftCode'] === '' ? null : $validated['swiftCode'],
            'country' => $validated['country'],
            'po_box_street' => $validated['poBoxStreet'] === '' ? null : $validated['poBoxStreet'],
            'company_address' => $validated['companyAddress'] === '' ? null : $validated['companyAddress'],
            'opened_at' => $validated['openedAt'] === '' || $validated['openedAt'] === null ? null : $validated['openedAt'],
        ])->save();

        $wasRecentlyCreated = $account->wasRecentlyCreated;

        $this->showAccountModal = false;
        $this->resetAccountForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('accounts.account_opened') : __('accounts.account_updated'),
        );
    }

    public function confirmClose(int $accountId): void
    {
        Gate::authorize('accounts.delete');

        $this->closingAccountId = $accountId;
        $this->showCloseModal = true;
    }

    public function cancelClose(): void
    {
        $this->showCloseModal = false;
        $this->closingAccountId = null;
    }

    /**
     * Close an account so it can no longer receive transfers.
     *
     * Accounts are never deleted: transfers_tracking.account_id RESTRICTs
     * precisely so an account money has passed through survives. Account::close()
     * writes closed_at and is_active together, through forceFill, because
     * closed_at sits outside #[Fillable].
     */
    public function closeAccount(): void
    {
        if ($this->closingAccountId === null) {
            return;
        }

        // Closing is the account's end of life, so it sits behind accounts.delete.
        Gate::authorize('accounts.delete');

        Account::query()->findOrFail($this->closingAccountId)->close();

        $this->showCloseModal = false;
        $this->closingAccountId = null;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('accounts.account_closed'));
    }

    public function closeAccountModal(): void
    {
        $this->showAccountModal = false;
        $this->resetAccountForm();
    }

    protected function resetAccountForm(): void
    {
        $this->resetValidation();
        $this->editingAccountId = null;
        $this->accountName = '';
        $this->bankName = '';
        $this->currency = 'USD';
        $this->authorizedPerson = '';
        $this->accountNumber = '';
        $this->iban = '';
        $this->swiftCode = '';
        $this->country = '';
        $this->poBoxStreet = '';
        $this->companyAddress = '';
        $this->openedAt = '';
        $this->editingAccountIsActive = true;
        $this->editingAccountClosedAt = null;
        $this->editingAccountTransfersCount = 0;
    }
};
?>


@php
    $showsOverview = $this->view === 'overview';
    $activeFilterCount = collect([$this->search, $this->state, $this->currencyFilter, $this->countryFilter, $this->flag, $this->year])
        ->filter(fn (string $value): bool => $value !== '')
        ->count();
    $total = $showsOverview ? $this->accounts->total() : $this->rows->total();
    $mutedText = 'text-secondary-medium dark:text-ink-muted';

    /* `$chosen` is read instead of the properties so a loop variable can never shadow one. */
    $chosen = [
        'year' => $this->year,
        'figures' => $this->figures,
        'state' => $this->state,
        'currencyFilter' => $this->currencyFilter,
        'flag' => $this->flag,
    ];
    $selectedYear = $this->selectedYear();
    $sortKey = $this->activeSort();
    $sortDirection = $this->activeSortDir();
    $showsFullNumbers = $this->showsFullAccountNumbers;

    $setPill = fn (string $property, string $value, string $label): array => [
        'label' => $label,
        'model' => $property, 'value' => $value, 'toggle' => true,
        'active' => $chosen[$property] === $value,
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
        ['default' => true, 'label' => __('finance_tables.figures_all_time'), 'model' => 'figures', 'value' => 'lifetime', 'active' => $chosen['figures'] === 'lifetime'],
        ['label' => __('finance_tables.figures_year', ['year' => $chosen['year']]), 'model' => 'figures', 'value' => 'year', 'active' => $chosen['figures'] === 'year'],
    ];
    $statePills = [
        ['default' => true, 'label' => __('finance_tables.all'), 'model' => 'state', 'value' => '', 'active' => $chosen['state'] === ''],
        $setPill('state', 'active', __('accounts.open')),
        $setPill('state', 'closed', __('accounts.closed')),
    ];
    $currencyPills = [
        ['default' => true, 'label' => __('finance_tables.all'), 'model' => 'currencyFilter', 'value' => '', 'active' => $chosen['currencyFilter'] === ''],
        ...array_map(fn (Currency $case): array => $setPill('currencyFilter', $case->value, $case->value), Currency::cases()),
    ];
    $flagPills = [
        $flagPill('has_transfers', __('finance_tables.flag_has_transfers')),
        $flagPill('no_transfers', __('finance_tables.flag_no_transfers')),
        $flagPill('in_transit', __('finance_tables.flag_in_transit')),
    ];
@endphp

<section class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="account-directory-heading">
    <x-ui.page-header
        heading-id="account-directory-heading"
        :title="__('common.accounts')"
        :count="$total"
    >
        <x-slot:actions>
            <div class="flex flex-wrap items-center justify-end gap-2">
                @if ($this->hasAnyAccount)
                    <x-funding.filters :active="$activeFilterCount" :results="$total">
                        <div class="directory-filters grid gap-3 sm:grid-cols-2">
                            <x-ui.input type="search" icon="magnifying-glass" :label="__('accounts.search_accounts')" :placeholder="__('accounts.account_name')" wire:model="search" clearable />

                            <x-ui.header-filter-select deferred :label="__('donors.country')" wire:model="countryFilter" :value="$this->countryFilter">
                                <flux:select.option value="">{{ __('finance_tables.all_countries') }}</flux:select.option>
                                @foreach ($this->countryOptions as $countryOption)
                                    <flux:select.option :value="$countryOption" :wire:key="'account-country-'.md5($countryOption)">{{ $countryOption }}</flux:select.option>
                                @endforeach
                            </x-ui.header-filter-select>
                        </div>

                        <x-funding.pills :label="__('finance_tables.year')" :pills="$yearPills" />

                        @if ($selectedYear !== null)
                            <x-funding.pills :label="__('finance_tables.figures_label')" :pills="$figurePills" />
                        @endif

                        <x-funding.pills :label="__('common.state')" :pills="$statePills" />
                        <x-funding.pills :label="__('common.currency')" :pills="$currencyPills" />
                        <x-funding.pills :label="__('finance_tables.flags')" :pills="$flagPills" />
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

                @if ($this->hasAnyAccount)
                    <x-ui.button tone="action" icon="arrow-down-tray" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" data-test="export-csv">
                        {{ __('finance_tables.export_csv') }}
                    </x-ui.button>
                @endif

                @can('accounts.create')
                    <x-ui.button tone="primary" icon="plus" class="page-action page-action-primary" wire:click="openCreateModal" data-test="create-account-button">
                        {{ __('accounts.open_account') }}
                    </x-ui.button>
                @endcan
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $this->hasAnyAccount)
        <x-ui.empty-state
            icon="building-library"
            :heading="__('accounts.no_accounts_yet')"
            :body="__('accounts.open_the_first_account')"
        />
    @elseif ($showsOverview)
        @include('pages.admin._accounts-overview')
    @elseif ($this->rows->isEmpty())
        <x-ui.empty-state
            icon="building-library"
            :heading="__('accounts.no_matching_accounts')"
            :body="__('common.change_the_search_or_filters_and_try_again')"
        />
    @else
        @php
            $totals = $this->totals;
            $money = fn (string|float|int|null $value): string => number_format((float) $value, 2);
            $date = fn (?\DateTimeInterface $value): string => $value?->translatedFormat(__('formats.date')) ?? '—';

            $totalLines = $totals->map(fn (array $line): array => [
                'currency' => $line['currency'],
                'caption' => __('finance_tables.count_accounts_open', ['count' => $line['accounts'], 'open' => $line['open_accounts']]),
                'figures' => array_values(array_filter([
                    ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $line['received'], 'tone' => 'good'],
                    $selectedYear !== null && $chosen['figures'] === 'lifetime' ? ['key' => 'received_in_year', 'label' => __('finance_tables.received_in_year', ['year' => $selectedYear]), 'value' => $line['received_in_year'], 'tone' => 'good'] : null,
                    ['key' => 'in_transit', 'label' => __('finance_tables.in_transit'), 'value' => $line['in_transit'], 'tone' => $line['in_transit'] > 0 ? 'warn' : null],
                    ['key' => 'upcoming', 'label' => __('finance_tables.upcoming'), 'value' => $line['upcoming']],
                    ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $line['overdue'], 'tone' => $line['overdue'] > 0 ? 'critical' : null],
                ])),
            ])->all();

            $showsYearColumn = $selectedYear !== null && $chosen['figures'] === 'lifetime';
        @endphp

        <x-funding.totals :lines="$totalLines" />

        <x-funding.register size="xl" :caption="__('finance_tables.accounts_caption')">
            <x-slot:head>
                <x-funding.register.th sort="account" :active="$sortKey" :dir="$sortDirection">{{ __('accounts.account_name') }}</x-funding.register.th>
                <x-funding.register.th sort="bank" :active="$sortKey" :dir="$sortDirection">{{ __('accounts.bank_name') }}</x-funding.register.th>
                <x-funding.register.th sort="country" :active="$sortKey" :dir="$sortDirection">{{ __('donors.country') }}</x-funding.register.th>
                <x-funding.register.th sort="currency" :active="$sortKey" :dir="$sortDirection">{{ __('common.currency') }}</x-funding.register.th>
                <x-funding.register.th>{{ __('accounts.account_number') }}</x-funding.register.th>
                <x-funding.register.th sort="state" :active="$sortKey" :dir="$sortDirection">{{ __('common.state') }}</x-funding.register.th>
                <x-funding.register.th sort="opened" :active="$sortKey" :dir="$sortDirection">{{ __('accounts.opened_on') }}</x-funding.register.th>
                <x-funding.register.th sort="closed" :active="$sortKey" :dir="$sortDirection">{{ __('finance_tables.closed_on') }}</x-funding.register.th>
                <x-funding.register.th sort="transfers" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('common.transfers') }}</x-funding.register.th>
                <x-funding.register.th sort="received" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.received') }}</x-funding.register.th>
                @if ($showsYearColumn)
                    <x-funding.register.th sort="received_in_year" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}</x-funding.register.th>
                @endif
                <x-funding.register.th sort="in_transit" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.in_transit') }}</x-funding.register.th>
                <x-funding.register.th sort="upcoming" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.upcoming') }}</x-funding.register.th>
                <x-funding.register.th sort="overdue" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.overdue') }}</x-funding.register.th>
                <x-funding.register.th sort="last_receipt" :active="$sortKey" :dir="$sortDirection">{{ __('finance_tables.last_receipt') }}</x-funding.register.th>
                <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($this->rows as $account)
                @php
                    $lastReceipt = $account->money_last_receipt !== null ? \Carbon\CarbonImmutable::parse($account->money_last_receipt) : null;
                    $overdue = (float) $account->money_overdue;
                    $otherCurrency = (int) $account->money_other_currency;
                @endphp
                <tr wire:key="account-{{ $account->id }}" data-account-row="{{ $account->id }}">
                    <td data-label="{{ __('accounts.account_name') }}" data-wide><span class="font-semibold">{{ $account->account_name }}</span></td>
                    <td data-label="{{ __('accounts.bank_name') }}">{{ $account->bank_name }}</td>
                    <td data-label="{{ __('donors.country') }}">{{ $account->country }}</td>
                    <td data-label="{{ __('common.currency') }}">{{ $account->currency->value }}</td>
                    <td data-label="{{ __('accounts.account_number') }}"><span class="fig" data-test="account-number">{{ $showsFullNumbers ? $account->account_number : $account->maskedAccountNumber() }}</span></td>
                    <td data-label="{{ __('common.state') }}">
                        <span @class([
                            'inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap',
                            'text-status-good' => $account->is_active,
                            $mutedText => ! $account->is_active,
                        ])>
                            <span @class(['size-2 shrink-0 rounded-full', 'bg-status-good' => $account->is_active, 'bg-secondary-light dark:bg-ink-faint' => ! $account->is_active]) aria-hidden="true"></span>
                            {{ $account->is_active ? __('accounts.open') : __('accounts.closed') }}
                        </span>
                    </td>
                    <td class="date" data-label="{{ __('accounts.opened_on') }}">{{ $date($account->opened_at) }}</td>
                    <td class="date" data-label="{{ __('finance_tables.closed_on') }}">{{ $date($account->closed_at) }}</td>
                    <td class="num" data-label="{{ __('common.transfers') }}">
                        @can('transfers.view')
                            <a href="{{ route('transfers.index', ['account' => $account->id]) }}" wire:navigate class="fig font-semibold underline underline-offset-2">{{ (int) $account->money_transfers }}</a>
                        @else
                            <span class="fig">{{ (int) $account->money_transfers }}</span>
                        @endcan
                    </td>
                    <td class="num" data-label="{{ __('finance_tables.received') }}" data-cell="received">
                        <span class="fig font-semibold text-funding">{{ $money($account->money_received) }}</span>
                        @if ($otherCurrency > 0)
                            {{-- Counted, never converted: the transfers table shows each one in the currency it landed in. --}}
                            <span class="sub" data-other-currency>{{ trans_choice('finance_tables.count_landed_in_another_currency', $otherCurrency, ['count' => $otherCurrency]) }}</span>
                        @endif
                    </td>
                    @if ($showsYearColumn)
                        <td class="num" data-label="{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}" data-cell="received_in_year"><span class="fig font-semibold text-funding">{{ $money($account->money_received_in_year) }}</span></td>
                    @endif
                    <td class="num" data-label="{{ __('finance_tables.in_transit') }}" data-cell="in_transit"><span class="fig">{{ $money($account->money_in_transit) }}</span></td>
                    <td class="num" data-label="{{ __('finance_tables.upcoming') }}" data-cell="upcoming"><span class="fig">{{ $money($account->money_upcoming) }}</span></td>
                    <td class="num" data-label="{{ __('finance_tables.overdue') }}" data-cell="overdue"><span @class(['fig', 'font-semibold text-status-critical' => $overdue > 0])>{{ $money($overdue) }}</span></td>
                    <td class="date" data-label="{{ __('finance_tables.last_receipt') }}">{{ $date($lastReceipt) }}</td>
                    <td>
                        <span class="flex items-center justify-end">
                            @can('transfers.view')
                                <x-ui.button tone="ghost" size="sm" icon="eye" icon:variant="outline" class="size-8!" :href="route('transfers.index', ['account' => $account->id])" wire:navigate :aria-label="__('accounts.view_transfers_for_name', ['name' => $account->account_name])" />
                            @endcan
                            @can('accounts.update')
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" icon:variant="outline" class="size-8!" wire:click="openEditModal({{ $account->id }})" :aria-label="__('common.edit_name', ['name' => $account->account_name])" />
                            @endcan

                            {{-- No delete anywhere: transfers_tracking.account_id RESTRICTs. Closing needs accounts.delete. --}}
                            @if ($account->is_active && auth()->user()->can('accounts.delete'))
                                <x-ui.button tone="ghost" size="sm" icon="lock-closed" icon:variant="outline" class="size-8!" wire:click="confirmClose({{ $account->id }})" :aria-label="__('accounts.close_name', ['name' => $account->account_name])" />
                            @endif
                        </span>
                    </td>
                </tr>
            @endforeach
        </x-funding.register>

        <x-funding.pager :paginator="$this->rows" :per-page="(string) $this->registerPerPage()" :sizes="$this->registerPageSizes()" scroll-to="#account-directory-heading" />
    @endif

    <x-ui.modal
        wire:model="showAccountModal"
        size="xl"
        :title="$editingAccountId === null ? __('accounts.open_account') : __('accounts.edit_account')"
        :description="__('accounts.a_new_account_is_usable_immediately_to_stop_it_receiving_tra')"
        submit="saveAccount"
        close="closeAccountModal"
        required-note
    >
        <x-ui.form-section :title="__('common.section_details')" :fields="['accountName', 'bankName', 'currency', 'authorizedPerson', 'openedAt']">
            @if ($editingAccountId !== null)
                <x-slot:hint>
                    <span class="flex flex-col gap-0.5">
                        <span @class([
                            'inline-flex w-fit items-center rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap',
                            'bg-status-good-soft text-status-good' => $editingAccountIsActive,
                            'bg-secondary-soft text-secondary-medium dark:bg-surface-2 dark:text-ink-muted' => ! $editingAccountIsActive,
                        ])>
                            {{ $editingAccountIsActive ? __('accounts.open') : __('accounts.closed') }}
                        </span>
                        @if ($editingAccountClosedAt !== null)
                            <span>{{ __('accounts.closed_date', ['date' => \Carbon\CarbonImmutable::parse($editingAccountClosedAt)->translatedFormat(__('formats.date'))]) }}</span>
                        @endif
                        <span>{{ trans_choice('accounts.count_transfer', $editingAccountTransfersCount, ['count' => $editingAccountTransfersCount]) }}</span>
                    </span>
                </x-slot:hint>
            @endif

            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-input wire:model="accountName" :label="__('accounts.account_name')" maxlength="255" required autofocus class="@2xl/modal:col-span-2" />
                <x-ui.form-input wire:model="bankName" :label="__('accounts.bank_name')" maxlength="255" required />

                <x-ui.form-segmented
                    wire:model="currency"
                    :label="__('common.currency')"
                    required
                    :options="collect(Currency::cases())->map(fn (Currency $currencyCase): array => ['value' => $currencyCase->value, 'label' => $currencyCase->value])->all()"
                />

                <x-ui.form-input wire:model="authorizedPerson" :label="__('accounts.authorized_person')" maxlength="255" />
                <x-ui.form-input wire:model="openedAt" :label="__('accounts.opened_on')" type="date" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('accounts.section_bank')" :fields="['accountNumber', 'iban', 'swiftCode', 'country']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-input wire:model="accountNumber" :label="__('accounts.account_number')" maxlength="100" required dir="ltr" />
                <x-ui.form-input wire:model="iban" :label="__('accounts.iban')" maxlength="100" dir="ltr" />
                <x-ui.form-input wire:model="swiftCode" :label="__('accounts.swift_code')" maxlength="50" dir="ltr" />
                <x-ui.form-input wire:model="country" :label="__('donors.country')" maxlength="100" required />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('accounts.section_address')" :fields="['poBoxStreet', 'companyAddress']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-textarea wire:model="poBoxStreet" :label="__('accounts.po_box_street')" rows="2" />
                <x-ui.form-textarea wire:model="companyAddress" :label="__('accounts.company_address')" rows="2" />
            </div>
        </x-ui.form-section>

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-account-button">
                {{ $editingAccountId === null ? __('accounts.open_account') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showCloseModal"
        close="cancelClose"
        tone="neutral"
        :title="__('accounts.close_this_account')"
        :message="__('accounts.it_stops_accepting_new_transfers_existing_transfer_records_a')"
        confirm="closeAccount"
        :confirm-label="__('accounts.close_account')"
        confirm-test="confirm-close-account"
    />
</section>
