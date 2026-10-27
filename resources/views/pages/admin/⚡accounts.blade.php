<?php

use App\Enums\Currency;
use App\Livewire\Concerns\Admin\ExportsAccountRegister;
use App\Livewire\Concerns\Admin\ManagesAccountForm;
use App\Livewire\Concerns\Admin\ReadsAccountRegister;
use App\Livewire\Concerns\FiltersFinanceRegister;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::desk'), Title('titles.accounts')] class extends Component {
    use ExportsAccountRegister;
    use FiltersFinanceRegister;
    use ManagesAccountForm;
    use ReadsAccountRegister;
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
};
?>


@php
    $showsOverview = $this->view === 'overview';
    $activeFilterCount = collect([$this->state, $this->currencyFilter, $this->countryFilter, $this->flag, $this->year])
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

<x-desk.register class="admin-directory @container/directory" heading-id="account-directory-heading" :title="__('common.accounts')" :count="$total">
    @if ($this->hasAnyAccount)
        <x-slot:search>
            <x-desk.register-search :label="__('accounts.search_accounts')" :placeholder="__('accounts.account_name')" wire:model.live.debounce.400ms="search" data-test="account-search" />
        </x-slot:search>
    @endif

        <x-slot:actions>
            <div class="flex flex-wrap items-center justify-end gap-2">
                @if ($this->hasAnyAccount)
                    <x-funding.filters :active="$activeFilterCount" :results="$total">
                        <div class="directory-filters grid gap-3 sm:grid-cols-2">
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
                    <x-ui.button tone="primary" icon="plus" class="page-action page-action-primary" wire:click="openCreateModal" data-test="create-account-button" data-quick-create>
                        {{ __('accounts.open_account') }}
                    </x-ui.button>
                @endcan
            </div>
        </x-slot:actions>
    
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
        @include('pages.admin._accounts-register')
    @endif

    @include('pages.admin._accounts-modal')

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
</x-desk.register>
