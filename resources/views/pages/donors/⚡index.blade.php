<?php

use App\Actions\Donors\DeleteDonor;
use App\Enums\Currency;
use App\Enums\DonorType;
use App\Livewire\Concerns\ChecksFinanceVisibility;
use App\Livewire\Concerns\Donors\ChartsDonorPortfolio;
use App\Livewire\Concerns\Donors\ExportsDonorRegister;
use App\Livewire\Concerns\Donors\PresentsDonorRegister;
use App\Livewire\Concerns\Donors\ReadsDonorRegister;
use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Livewire\Concerns\ManagesDonorForm;
use App\Models\Donor;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::desk'), Title('titles.donors')] class extends Component {
    use ChartsDonorPortfolio;
    use ChecksFinanceVisibility;
    use ExportsDonorRegister;
    use FiltersFinanceRegister;
    use ManagesDonorForm;
    use PresentsDonorRegister;
    use ReadsDonorRegister;
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

<x-desk.register
    class="@container/directory"
    heading-id="donor-directory-heading"
    :title="__('common.donors')"
    :count="$total"
    x-data="{ focus: null, lit(id) { return this.focus === null || this.focus === id } }"
    :view="$view"
    :views="[ 'table' => ['icon' => 'table', 'label' => __('finance_tables.view_table')], 'overview' => ['icon' => 'chart', 'label' => __('finance_tables.view_overview')] ]"
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

                @if ($this->hasAnyDonor)
                    <x-ui.button tone="action" icon="arrow-down-tray" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" data-test="export-csv">
                        {{ __('finance_tables.export_csv') }}
                    </x-ui.button>
                @endif

                @can('donors.create')
                <x-ui.button tone="primary" icon="plus" class="page-action page-action-primary" wire:click="openCreateModal" data-test="create-donor-button" data-quick-create>
                    {{ __('donors.add_donor') }}
                </x-ui.button>
                @endcan
            </div>
        </x-slot:actions>


    @if (! $this->hasAnyDonor)
        <x-ui.empty-state icon="building-library" :heading="__('donors.no_donors_yet')" :body="__('donors.add_the_first_donor')" />
    @elseif ($showsOverview)
        @include('pages.donors._overview')
    @elseif ($this->rows->isEmpty())
        <x-ui.empty-state icon="building-library" :heading="__('donors.no_matching_donors')" :body="__('common.change_the_search_or_filters_and_try_again')" />
    @else
        @include('pages.donors._index-register')
    @endif

    <x-donors.form-modal :open="$showDonorModal" :editing="$editingDonorId !== null" :type-options="$this->donorTypeOptions" />

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('donors.delete_this_donor')"
        :message="__('donors.only_a_donor_holding_no_funding_agreement_can_be_deleted')"
        confirm="deleteDonor"
        :confirm-label="__('donors.delete_donor')"
        confirm-test="confirm-delete-donor"
    />
</x-desk.register>
