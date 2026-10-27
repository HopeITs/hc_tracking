<?php

use App\Enums\Currency;
use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Livewire\Concerns\ChecksFinanceVisibility;
use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Livewire\Concerns\Proposals\ChartsProposalPipeline;
use App\Livewire\Concerns\Proposals\DeletesProposals;
use App\Livewire\Concerns\Proposals\ExportsProposalRegister;
use App\Livewire\Concerns\Proposals\ManagesProposalForm;
use App\Livewire\Concerns\Proposals\ManagesProposalStatus;
use App\Livewire\Concerns\Proposals\ReadsProposalBoard;
use App\Livewire\Concerns\Proposals\ReadsProposalRegister;
use App\Models\Proposal;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::desk'), Title('titles.proposals')] class extends Component {
    use ChartsProposalPipeline;
    use ChecksFinanceVisibility;
    use DeletesProposals;
    use ExportsProposalRegister;
    use FiltersFinanceRegister;
    use ManagesProposalForm;
    use ManagesProposalStatus;
    use ReadsProposalBoard;
    use ReadsProposalRegister;
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

    /**
     * A project id from New Concept Note on the project page: the create form
     * opens with it chosen. Read once on mount, then cleared from the URL.
     */
    #[Url(as: 'for_project', except: '')]
    public string $forProject = '';

    /**
     * Everything here arrives from the URL. Normalise on the way in, so a
     * hand-typed value never leaves a filter applied with no control showing it.
     */
    public function mount(): void
    {
        $this->normaliseFilters();

        if ($this->forProject !== '') {
            $projectId = ctype_digit($this->forProject) ? (int) $this->forProject : null;
            $this->forProject = '';

            if (Gate::allows('create', Proposal::class)) {
                $this->openCreateModal($projectId);
            }
        }
    }

    /**
     * Table, Board, Calendar and Overview (SwitchesFundingView).
     *
     * @return list<string>
     */
    protected function fundingViews(): array
    {
        return ['table', 'board', 'calendar', 'overview'];
    }

    protected function proposalSaved(Proposal $proposal, bool $wasCreated): void
    {
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->normaliseFilters();
            $this->resetPage();
        }
    }

    /**
     * Nothing on this page is memoised across requests, so a write has
     * nothing to forget (ManagesProposalStatus calls it after a move).
     */
    private function forgetReadings(): void {}

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
        ...array_map(fn (ProposalStatus $case): array => $setPill('status', $case->value, $case->label()), ProposalStatus::live()),
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

<x-desk.register
    class="@container/directory"
    heading-id="proposal-pipeline-heading"
    :title="__('common.proposals')"
    :count="$total"
    :view="$view"
    :views="[
        'table' => ['icon' => 'table', 'label' => __('desk.register.table')],
        'board' => ['icon' => 'board', 'label' => __('desk.register.board')],
        'calendar' => ['icon' => 'cal', 'label' => __('desk.register.calendar')],
        'overview' => ['icon' => 'chart', 'label' => __('finance_tables.view_overview')],
    ]"
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
                                <button type="button" x-on:click="pick('donor', '')" class="inline-flex min-h-8 items-center gap-1.5 rounded-full bg-funding-soft ps-3 pe-2 text-xs font-semibold text-funding transition-colors hover:bg-funding hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium dark:text-ink dark:hover:text-surface-0" aria-label="{{ __('proposals.remove_donor_filter', ['name' => $this->donorFilterName]) }}" data-donor-chip>
                                    {{ __('proposals.funded_by', ['name' => $this->donorFilterName]) }}
                                    <flux:icon.x-mark variant="micro" class="size-3.5" />
                                </button>
                            </x-funding.filter-row>
                        @endif
                    </x-funding.filters>
                @endif

                @if ($this->hasAnyProposal)
                    <x-ui.button tone="action" icon="arrow-down-tray" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv" data-test="export-csv">
                        {{ __('finance_tables.export_csv') }}
                    </x-ui.button>
                @endif

                @can('create', App\Models\Proposal::class)
                <x-ui.button tone="primary" icon="plus" class="page-action page-action-primary" wire:click="openCreateModal" data-test="create-proposal-button" data-quick-create>
                    {{ __('proposals.add_proposal') }}
                </x-ui.button>
                @endcan
            </div>
        </x-slot:actions>


    @if (! $this->hasAnyProposal)
        <x-ui.empty-state icon="document-text" :heading="__('proposals.no_proposals_yet')" :body="__('proposals.add_the_first_proposal_to_open_the_pipeline')" />
    @elseif ($showsOverview)
        @include('pages.proposals._overview')
    @elseif ($view === 'board')
        @include('pages.proposals._index-board')
    @elseif ($view === 'calendar')
        <x-desk.month-calendar :month="$this->calendarMonthStart()->toDateString()" :items="$this->calendarItems" />
    @elseif ($this->rows->isEmpty())
        <x-ui.empty-state icon="document-text" :heading="__('proposals.no_matching_proposals')" :body="__('common.change_the_search_or_filters_and_try_again')" />
    @else
        @include('pages.proposals._index-register')
    @endif


    @include('pages.proposals._proposal-form')

    @include('pages.proposals._status-modals')

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('proposals.delete_this_proposal')"
        :message="__('proposals.its_tasks_project_links_and_status_history_are_removed_with')"
        confirm="deleteProposal"
        :confirm-label="__('proposals.delete_proposal')"
        confirm-test="confirm-delete-proposal"
    />
</x-desk.register>
