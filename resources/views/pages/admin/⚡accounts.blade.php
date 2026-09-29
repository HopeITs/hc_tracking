<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\TransfersTracking;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.accounts')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $state = '';

    #[Url(except: '')]
    public string $currencyFilter = '';

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

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function updatedCurrencyFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->state = '';
        $this->currencyFilter = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Account> */
    #[Computed]
    public function accounts(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Account::query()
            ->select(['id', 'account_name', 'bank_name', 'currency', 'account_number', 'country', 'opened_at', 'closed_at', 'is_active', 'updated_at'])
            ->withCount('transfers')
            ->withMax('transfers as last_transfer_at', 'transfer_date')
            ->when(
                $search !== '',
                fn ($query) => $query->where(fn ($query) => $query
                    ->where('account_name', 'like', '%'.$search.'%')
                    ->orWhere('bank_name', 'like', '%'.$search.'%')
                    ->orWhereIn('id', Account::idsMatchingAccountNumber($search))),
            )
            ->when($this->state === 'active', fn ($query) => $query->where('is_active', true))
            ->when($this->state === 'closed', fn ($query) => $query->where('is_active', false))
            ->when($this->currencyFilter !== '', fn ($query) => $query->where('currency', $this->currencyFilter))
            ->orderByDesc('is_active')
            ->orderBy('account_name')
            ->orderBy('id')
            ->paginate(15);
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
    $summary = $this->summary;
    $fundsByCurrency = $this->fundsByCurrency;
    $moneyStats = $this->accountMoneyStats;

    // Same rotation cities/⚡index.blade.php uses so the grid reads as lively
    // rather than uniform tiles — never the primary red, reserved for
    // late/critical signal elsewhere in the app.
    $avatarTones = [
        'bg-funding-soft text-funding',
        'bg-status-active-soft text-status-active',
        'bg-status-good-soft text-status-good',
        'bg-status-warn-soft text-status-warn',
    ];

    $stateChart = [
        'series' => [$summary['active'], $summary['closed']],
        'categories' => [__('accounts.open'), __('accounts.closed')],
        'tones' => ['good', 'muted'],
    ];
@endphp

<section class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="account-directory-heading">
    <x-ui.page-header
        heading-id="account-directory-heading"
        :title="__('common.accounts')"
        :count="$this->accounts->total()"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('accounts.search_accounts')" :placeholder="__('accounts.account_name')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('common.state')" wire:model.live="state" :value="$state">
                <flux:select.option value="">{{ __('common.all_accounts') }}</flux:select.option>
                <flux:select.option value="active">{{ __('accounts.open') }}</flux:select.option>
                <flux:select.option value="closed">{{ __('accounts.closed') }}</flux:select.option>
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('common.currency')" wire:model.live="currencyFilter" :value="$currencyFilter">
                <flux:select.option value="">{{ __('accounts.all_currencies') }}</flux:select.option>
                @foreach (Currency::cases() as $currencyCase)
                    <flux:select.option :value="$currencyCase->value" :wire:key="'account-currency-filter-'.$currencyCase->value">{{ $currencyCase->value }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        @can('accounts.create')
            <x-slot:actions>
                <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-account-button">
                    {{ __('accounts.open_account') }}
                </x-ui.button>
            </x-slot:actions>
        @endcan

        <x-slot:summary>
            <div class="grid grid-cols-1 gap-4 @2xl/directory:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
                <div class="grid grid-cols-2 gap-3 @sm/directory:grid-cols-4 @2xl/directory:grid-cols-2">
                    <x-ui.stat-tile :label="__('common.accounts')" :value="$summary['total']" icon="building-library" />
                    <x-ui.stat-tile :label="__('accounts.open_accounts')" :value="$summary['active']" tone="good" icon="lock-open" />
                    <x-ui.stat-tile :label="__('accounts.closed_accounts')" :value="$summary['closed']" icon="lock-closed" />
                    <x-ui.stat-tile :label="__('accounts.total_transfers')" :value="$summary['transfers']" tone="funding" icon="arrows-right-left" />
                </div>

                <x-ui.panel :title="__('accounts.funds_received')" :subtitle="__('accounts.funds_received_hint')" class="min-w-0">
                    <div class="flex flex-col gap-4 p-4 @xl/directory:flex-row @xl/directory:items-center">
                        <x-ui.chart
                            type="donut"
                            :label="__('accounts.accounts_by_state')"
                            :series="$stateChart['series']"
                            :categories="$stateChart['categories']"
                            :tones="$stateChart['tones']"
                            :total-label="__('common.accounts')"
                            :height="140"
                            class="mx-auto shrink-0 @xl/directory:mx-0"
                            style="width: 140px"
                        />

                        <div class="min-w-0 flex-1 space-y-2">
                            @if ($fundsByCurrency->isEmpty())
                                <p class="type-meta text-secondary-medium dark:text-secondary-soft">{{ __('accounts.no_funds_received_yet') }}</p>
                            @else
                                @foreach ($fundsByCurrency as $currency => $total)
                                    <div class="flex items-center justify-between gap-3 rounded-lg bg-funding-soft px-3 py-2" wire:key="currency-total-{{ $currency }}">
                                        <span class="type-meta font-semibold text-funding">{{ __('ui.received') }}</span>
                                        <x-ui.money :amount="$total" :currency="$currency" class="text-funding [&>span:first-child]:text-base" />
                                    </div>
                                @endforeach
                            @endif

                            <div class="flex items-center gap-4 pt-1">
                                <span class="flex items-center gap-1.5 type-meta text-secondary-medium dark:text-secondary-soft">
                                    <span class="size-2 shrink-0 rounded-full bg-status-good" aria-hidden="true"></span>
                                    {{ __('accounts.open') }} ({{ $summary['active'] }})
                                </span>
                                <span class="flex items-center gap-1.5 type-meta text-secondary-medium dark:text-secondary-soft">
                                    <span class="size-2 shrink-0 rounded-full bg-secondary-light dark:bg-secondary-medium" aria-hidden="true"></span>
                                    {{ __('accounts.closed') }} ({{ $summary['closed'] }})
                                </span>
                            </div>
                        </div>
                    </div>
                </x-ui.panel>
            </div>
        </x-slot:summary>
    </x-ui.page-header>

    @if ($this->accounts->isEmpty())
        <x-ui.empty-state
            icon="building-library"
            :heading="$search !== '' || $state !== '' || $currencyFilter !== '' ? __('accounts.no_matching_accounts') : __('accounts.no_accounts_yet')"
            :body="$search !== '' || $state !== '' || $currencyFilter !== '' ? __('common.change_the_search_or_filters_and_try_again') : __('accounts.open_the_first_account')"
            class="rounded-xl border border-secondary-soft dark:border-secondary-medium"
        />
    @else
        <div class="grid grid-cols-1 gap-4 @sm/directory:grid-cols-2 @4xl/directory:grid-cols-3 @7xl/directory:grid-cols-4">
            @foreach ($this->accounts as $index => $account)
                @php
                    $avatarTone = $avatarTones[$account->id % count($avatarTones)];
                    $currencyTotals = $moneyStats->get($account->id, collect());
                    $lastTransferAt = $account->last_transfer_at !== null ? \Carbon\CarbonImmutable::parse($account->last_transfer_at) : null;
                @endphp
                <article
                    wire:key="account-{{ $account->id }}"
                    class="logframe-reveal group relative flex flex-col gap-4 overflow-hidden rounded-xl border border-secondary-soft bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] transition-all hover:-translate-y-0.5 hover:border-secondary-light hover:shadow-[0_2px_4px_rgba(16,24,40,0.06),0_4px_8px_rgba(16,24,40,0.08)] dark:border-secondary-medium dark:bg-secondary-dark dark:shadow-none dark:hover:shadow-none"
                    style="--logframe-order: {{ min($index, 11) }}"
                >
                    <span
                        aria-hidden="true"
                        class="{{ $account->is_active ? 'bg-status-good' : 'bg-secondary-light dark:bg-secondary-medium' }} absolute inset-x-0 top-0 h-1"
                    ></span>

                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="{{ $avatarTone }} flex size-11 shrink-0 items-center justify-center rounded-xl">
                                <flux:icon.building-library class="size-5" />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-base font-bold text-secondary-dark dark:text-white">{{ $account->account_name }}</p>
                                <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $account->bank_name }} · {{ $account->currency->value }} · {{ $account->account_number }}</p>
                                <p class="truncate text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                                    @if ($account->opened_at !== null)
                                        {{ __('accounts.opened_date', ['date' => $account->opened_at->translatedFormat(__('formats.date'))]) }}
                                    @else
                                        {{ __('accounts.opening_date_unknown') }}
                                    @endif
                                    @if ($account->closed_at !== null)
                                        · {{ __('accounts.closed_date', ['date' => $account->closed_at->translatedFormat(__('formats.date'))]) }}
                                    @endif
                                </p>
                            </div>
                        </div>

                        <span @class([
                            'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap',
                            'bg-status-good-soft text-status-good' => $account->is_active,
                            'bg-secondary-soft text-secondary-medium dark:bg-secondary-medium dark:text-secondary-soft' => ! $account->is_active,
                        ])>
                            {{ $account->is_active ? __('accounts.open') : __('accounts.closed') }}
                        </span>
                    </div>

                    <div class="min-w-0 space-y-1.5">
                        @if ($currencyTotals->isEmpty())
                            <p class="type-meta text-secondary-light italic dark:text-secondary-medium">{{ __('accounts.no_transfers_yet') }}</p>
                        @else
                            @foreach ($currencyTotals as $currency => $total)
                                <div class="flex items-center justify-between gap-2 rounded-lg bg-secondary-soft px-2.5 py-1.5 dark:bg-secondary-medium/40" wire:key="account-{{ $account->id }}-currency-{{ $currency }}">
                                    <span class="type-meta text-secondary-medium dark:text-secondary-soft">{{ __('ui.received') }}</span>
                                    <x-ui.money :amount="$total" :currency="$currency" />
                                </div>
                            @endforeach
                        @endif
                    </div>

                    <div class="mt-auto flex items-center justify-between gap-3 border-t border-secondary-soft pt-3 dark:border-secondary-medium">
                        <div class="min-w-0">
                            <a href="{{ route('transfers.index', ['account' => $account->id]) }}" wire:navigate class="type-meta font-semibold text-secondary-medium underline-offset-4 hover:text-primary hover:underline dark:text-secondary-soft">
                                {{ trans_choice('accounts.count_transfer', $account->transfers_count, ['count' => $account->transfers_count]) }}
                            </a>
                            @if ($lastTransferAt !== null)
                                <p class="type-meta text-secondary-light dark:text-secondary-medium">{{ __('accounts.last_transfer_date', ['date' => $lastTransferAt->translatedFormat(__('formats.date'))]) }}</p>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <x-ui.button tone="ghost" size="sm" icon="eye" class="size-9!" :href="route('transfers.index', ['account' => $account->id])" wire:navigate :aria-label="__('accounts.view_transfers_for_name', ['name' => $account->account_name])" />
                            @can('accounts.update')
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $account->id }})" :aria-label="__('common.edit_name', ['name' => $account->account_name])" />
                            @endcan

                            {{-- No delete anywhere: transfers_tracking.account_id RESTRICTs. Closing needs accounts.delete. --}}
                            @if ($account->is_active && auth()->user()->can('accounts.delete'))
                                <x-ui.button tone="ghost" size="sm" icon="lock-closed" class="size-9!" wire:click="confirmClose({{ $account->id }})" :aria-label="__('accounts.close_name', ['name' => $account->account_name])" />
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        <flux:pagination
            :paginator="$this->accounts"
            class="text-secondary-medium! [&_button]:text-secondary-medium! [&_button:hover]:bg-secondary-soft! dark:text-secondary-soft! dark:[&_button]:text-secondary-soft! dark:[&_button:hover]:bg-secondary-medium!"
            scroll-to="#account-directory-heading"
        />
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
                            'bg-secondary-soft text-secondary-medium dark:bg-secondary-medium dark:text-secondary-soft' => ! $editingAccountIsActive,
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
