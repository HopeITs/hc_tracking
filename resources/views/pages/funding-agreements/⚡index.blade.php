<?php

use App\Enums\Currency;
use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Livewire\Concerns\FundingAgreements\ChartsAgreementCalendar;
use App\Livewire\Concerns\FundingAgreements\ExportsAgreementRegister;
use App\Livewire\Concerns\FundingAgreements\ReadsAgreementRegister;
use App\Livewire\Concerns\ManagesAgreementForm;
use App\Models\DonorProposal;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::desk'), Title('titles.funding_agreements')] class extends Component {
    use ChartsAgreementCalendar;
    use ExportsAgreementRegister;
    use FiltersFinanceRegister;
    use ManagesAgreementForm;
    use ReadsAgreementRegister;
    use WithPagination;

    /**
     * Where an agreement's money can be stuck. Each one is a filter the page
     * can be narrowed to.
     *
     * @var list<string>
     */
    public const STAGES = ['overdue', 'failed', 'upcoming', 'unscheduled', 'in_transit', 'received'];

    /**
     * Conditions that are not about where the money is.
     *
     * @var list<string>
     */
    public const FLAGS = ['report_overdue', 'net_missing', 'other_currency', 'no_tranches'];

    /**
     * Every property a filter control writes.
     *
     * @var list<string>
     */
    private const FILTERS = ['search', 'donor', 'currency', 'stage', 'flag', 'min', 'max'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $donor = '';

    #[Url(except: '')]
    public string $currency = '';

    #[Url(except: '')]
    public string $stage = '';

    #[Url(except: '')]
    public string $flag = '';

    /** The smallest budget to show. */
    #[Url(except: '')]
    public string $min = '';

    /** The largest budget to show. */
    #[Url(except: '')]
    public string $max = '';

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
        if (! in_array($this->stage, self::STAGES, true)) {
            $this->stage = '';
        }

        if (! in_array($this->flag, self::FLAGS, true)) {
            $this->flag = '';
        }

        if (Currency::tryFrom($this->currency) === null) {
            $this->currency = '';
        }

        if (! ctype_digit($this->donor)) {
            $this->donor = '';
        }

        foreach (['min', 'max'] as $bound) {
            $value = $this->{$bound};
            $this->{$bound} = is_numeric($value) && (float) $value >= 0 && (float) $value <= self::MAX_BUDGET ? $value : '';
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'donor', 'currency', 'stage', 'flag', 'min', 'max']);
        $this->resetRegisterFilters();
        $this->resetPage();
    }

    /**
     * Toggle the stage filter from a ledger figure, a collection-line segment
     * or a stage chip.
     */
    public function toggleStage(string $stage): void
    {
        $this->stage = $this->stage === $stage || ! in_array($stage, self::STAGES, true) ? '' : $stage;
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->openAgreementCreate();
    }

    public function openEditModal(int $agreementId): void
    {
        $this->openAgreementEdit($agreementId);
    }

    protected function agreementSaved(DonorProposal $agreement, bool $isCreate): void
    {
        $this->resetPage();
    }

    protected function agreementDeleted(int $agreementId): void
    {
        $this->resetPage();
    }
};
?>


@php
    $showsOverview = $this->view === 'overview';
    $activeFilterCount = collect([$this->search, $this->donor, $this->currency, $this->stage, $this->flag, $this->min, $this->max, $this->year])
        ->filter(fn (string $value): bool => $value !== '')
        ->count();
    $total = $showsOverview ? $this->agreements->total() : $this->rows->total();

    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';

    /* `$chosen` is read instead of the properties so a loop variable can never shadow one. */
    $chosen = [
        'year' => $this->year,
        'figures' => $this->figures,
        'currency' => $this->currency,
        'stage' => $this->stage,
        'flag' => $this->flag,
    ];
    $selectedYear = $this->selectedYear();
    $yearOnly = $this->yearOnly();
    $sortKey = $this->activeSort();
    $sortDirection = $this->activeSortDir();
    $counts = $this->hasAnyAgreement && ! $showsOverview ? $this->stageCounts : [];

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
        ['default' => true, 'label' => __('finance_tables.figures_lifetime'), 'model' => 'figures', 'value' => 'lifetime', 'active' => $chosen['figures'] === 'lifetime'],
        ['label' => __('finance_tables.figures_year', ['year' => $chosen['year']]), 'model' => 'figures', 'value' => 'year', 'active' => $chosen['figures'] === 'year'],
    ];
    $statusPills = [
        ['default' => true, 'label' => __('finance_tables.all'), 'model' => 'stage', 'value' => '', 'active' => $chosen['stage'] === '', 'test' => 'stage-all'],
        $stagePill('overdue', __('funding_agreements.stage_overdue'), 'critical'),
        $stagePill('failed', __('funding_agreements.stage_failed'), 'critical'),
        $stagePill('in_transit', __('funding_agreements.stage_in_transit'), 'warn'),
        $stagePill('upcoming', __('funding_agreements.stage_upcoming')),
        $stagePill('unscheduled', __('funding_agreements.stage_unscheduled')),
        $stagePill('received', __('funding_agreements.stage_received')),
    ];
    $currencyPills = [
        ['default' => true, 'label' => __('finance_tables.all'), 'model' => 'currency', 'value' => '', 'active' => $chosen['currency'] === ''],
        ...array_map(fn (Currency $case): array => $setPill('currency', $case->value, $case->value), Currency::cases()),
    ];
    $flagPills = [
        $flagPill('report_overdue', __('finance_tables.flag_report_overdue')),
        $flagPill('net_missing', __('finance_tables.flag_unrecorded')),
        $flagPill('other_currency', __('finance_tables.flag_other_currency')),
        $flagPill('no_tranches', __('finance_tables.flag_no_tranches')),
    ];
@endphp

<x-desk.register
    class="@container/directory"
    heading-id="agreement-directory-heading"
    :title="__('common.funding_agreements')"
    :count="$total"
    :view="$view"
    :views="[ 'table' => ['icon' => 'table', 'label' => __('finance_tables.view_table')], 'overview' => ['icon' => 'chart', 'label' => __('finance_tables.view_overview')] ]"
>
    <x-slot:actions>
            <div class="flex flex-wrap items-center justify-end gap-2">
                @if ($this->hasAnyAgreement)
                    <x-funding.filters :active="$activeFilterCount" :results="$total">
                        <div class="directory-filters grid gap-3 sm:grid-cols-2">
                            <x-ui.input type="search" icon="magnifying-glass" :label="__('funding_agreements.search_agreements')" :placeholder="__('common.reference_donor_or_proposal')" wire:model="search" clearable />

                            <x-ui.header-filter-select deferred :label="__('funding_agreements.donor')" wire:model="donor" :value="$this->donor">
                                <flux:select.option value="">{{ __('funding_agreements.all_donors') }}</flux:select.option>
                                @foreach ($this->donorOptions as $donorOption)
                                    <flux:select.option :value="$donorOption->id" :wire:key="'agr-donor-'.$donorOption->id">{{ $donorOption->name }}</flux:select.option>
                                @endforeach
                            </x-ui.header-filter-select>
                        </div>

                        <x-funding.pills :label="__('finance_tables.year')" :pills="$yearPills" />

                        @if ($selectedYear !== null)
                            <x-funding.pills :label="__('finance_tables.figures_label')" :pills="$figurePills" />
                        @endif

                        {{-- The Overview view narrows by stage with its own chips, under its ledger. --}}
                        @unless ($showsOverview)
                            <x-funding.pills :label="__('common.status')" :pills="$statusPills" />
                        @endunless

                        <x-funding.pills :label="__('common.currency')" :pills="$currencyPills" />
                        <x-funding.pills :label="__('finance_tables.flags')" :pills="$flagPills" />

                        <x-funding.filter-row :label="__('finance_tables.budget')" :on="$this->min !== '' || $this->max !== ''" :watch="['min', 'max']">
                            <x-ui.input type="number" inputmode="decimal" min="0" step="0.01" size="sm" class="w-36" wire:model="min" :placeholder="__('finance_tables.minimum')" :aria-label="__('finance_tables.minimum')" />
                            <span class="text-xs font-semibold {{ $mutedText }}">{{ __('finance_tables.to') }}</span>
                            <x-ui.input type="number" inputmode="decimal" min="0" step="0.01" size="sm" class="w-36" wire:model="max" :placeholder="__('finance_tables.maximum')" :aria-label="__('finance_tables.maximum')" />
                        </x-funding.filter-row>
                    </x-funding.filters>
                @endif

                @if ($this->hasAnyAgreement)
                    <x-ui.button tone="action" icon="arrow-down-tray" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" data-test="export-csv">
                        {{ __('finance_tables.export_csv') }}
                    </x-ui.button>
                @endif

                @can('funding-agreements.create')
                <x-ui.button tone="primary" icon="plus" class="page-action page-action-primary" wire:click="openCreateModal" data-test="create-agreement-button">
                    {{ __('funding_agreements.record_agreement') }}
                </x-ui.button>
                @endcan
            </div>
        </x-slot:actions>


    @if (! $this->hasAnyAgreement)
        <x-ui.empty-state icon="banknotes" :heading="__('common.no_agreements_yet')" :body="__('funding_agreements.record_the_first_funding_agreement')" />
    @elseif ($showsOverview)
        @include('pages.funding-agreements._overview')
    @elseif ($this->rows->isEmpty())
        <x-ui.empty-state icon="banknotes" :heading="__('funding_agreements.no_matching_agreements')" :body="__('common.change_the_search_or_filters_and_try_again')" />
    @else
        @include('pages.funding-agreements._index-register')
    @endif

    <x-funding.agreement-modal
        :open="$showAgreementModal"
        :editing="$editingAgreementId !== null"
        :warning="$showAgreementModal ? $this->agreementStatusWarning : null"
        :delete-blocker="$showAgreementModal ? $this->agreementDeleteBlocker : null"
        :locked-currency="$showAgreementModal && $this->agreementCurrencyLocked ? $agreementCurrency : null"
        :confirming-delete="$confirmingAgreementDelete"
        :donor-name="$showAgreementModal ? $this->agreementDonorName : null"
        :installments="$installments"
        :installments-total="$this->installmentsTotal"
        :installments-differ="$this->installmentsDifferFromBudget"
        :currency-code="$agreementCurrency"
        :proposal-options="$showAgreementModal ? $this->proposalOptions : []"
        :locked-proposal-name="$showAgreementModal && $editingAgreementId !== null ? $this->agreementProposalLabel : null"
    />
</x-desk.register>
