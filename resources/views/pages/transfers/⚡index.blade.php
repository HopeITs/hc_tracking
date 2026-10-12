<?php

use App\Enums\Currency;
use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Livewire\Concerns\ManagesTransferForm;
use App\Livewire\Concerns\Transfers\ExportsTransferRegister;
use App\Livewire\Concerns\Transfers\FiltersTransferRegister;
use App\Livewire\Concerns\Transfers\PresentsTransferRegister;
use App\Livewire\Concerns\Transfers\ReadsTransferLedger;
use App\Livewire\Concerns\Transfers\ReadsTransferRegister;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.transfers')] class extends Component {
    use ExportsTransferRegister;
    use FiltersFinanceRegister;
    use FiltersTransferRegister;
    use ManagesTransferForm;
    use PresentsTransferRegister;
    use ReadsTransferLedger;
    use ReadsTransferRegister;
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
        @include('pages.transfers._index-register')
    @endif

    <x-funding.transfer-modal
        :open="$showTransferModal"
        :editing="$editingTransferId !== null"
        :agreement-options="$showTransferModal ? $this->agreementOptions : []"
        :account-options="$showTransferModal ? $this->accountOptions : []"
        :confirming-delete="$confirmingTransferDelete"
    />
</section>
