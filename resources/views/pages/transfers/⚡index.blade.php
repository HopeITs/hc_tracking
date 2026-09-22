<?php

use App\Enums\ArStatus;
use App\Livewire\Concerns\ManagesTransferForm;
use App\Models\TransfersTracking;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.transfers')] class extends Component {
    use ManagesTransferForm;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $arStatus = '';

    #[Url(except: '')]
    public string $account = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedArStatus(): void
    {
        $this->resetPage();
    }

    public function updatedAccount(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->arStatus = '';
        $this->account = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, TransfersTracking> */
    #[Computed]
    public function transfers(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return TransfersTracking::query()
            ->select([
                'id', 'donor_proposal_id', 'account_id', 'payment_amount', 'amount_transfered_currency',
                'scheduled_date', 'transfer_date', 'fees', 'fees_currency', 'amount_received',
                'exchange_rate', 'net_amount_received', 'amount_received_currency', 'receive_date', 'ar_status',
            ])
            ->with([
                'donorProposal:id,donor_id,proposal_id,project_num',
                'donorProposal.donor:id,name',
                'donorProposal.proposal:id,name',
                // Nullable: a tranche may be scheduled before an account exists.
                'account:id,name,is_active',
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $query->whereHas('donorProposal', function ($query) use ($search): void {
                    $query
                        ->where('project_num', 'like', '%'.$search.'%')
                        ->orWhereHas('donor', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('proposal', fn ($query) => $query->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($this->arStatus !== '', fn ($query) => $query->where('ar_status', $this->arStatus))
            ->when($this->account !== '', fn ($query) => $query->where('account_id', (int) $this->account))
            ->orderByRaw('scheduled_date is null, scheduled_date desc')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * Scheduled payment totals per transferred currency, for this page.
     *
     * Three currency columns exist on this table and they are independent: a
     * USD payment can incur EUR fees and land as SYP. Nothing is added across
     * currencies anywhere on this page.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function paymentByCurrency(): array
    {
        return $this->transfers->getCollection()
            ->groupBy(fn (TransfersTracking $transfer): string => $transfer->amount_transfered_currency->value)
            ->map(fn (Collection $group): float => (float) $group->sum(fn (TransfersTracking $transfer): float => (float) $transfer->payment_amount))
            ->all();
    }

    public function openCreateModal(): void
    {
        $this->openTransferCreate();
    }

    public function openEditModal(int $transferId): void
    {
        $this->openTransferEdit($transferId);
    }

    protected function transferSaved(TransfersTracking $transfer, bool $isCreate): void
    {
        $this->resetPage();
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $arStatus !== '' || $account !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="transfer-directory-heading">
    <x-ui.page-header
        heading-id="transfer-directory-heading"
        :title="__('common.transfers')"
        :count="$this->transfers->total()"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('transfers.search_transfers')" :placeholder="__('common.reference_donor_or_proposal')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('transfers.receipt_status')" wire:model.live="arStatus" :value="$arStatus">
                <flux:select.option value="">{{ __('common.all_statuses') }}</flux:select.option>
                @foreach (ArStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'ar-'.$statusCase->value">{{ $statusCase->label() }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('transfers.account')" wire:model.live="account" :value="$account">
                <flux:select.option value="">{{ __('common.all_accounts') }}</flux:select.option>
                @foreach ($this->accountOptions as $accountOption)
                    <flux:select.option :value="$accountOption->id" :wire:key="'tr-account-'.$accountOption->id">
                        {{ $accountOption->name }}@unless ($accountOption->is_active) · {{ __('common.closed') }}@endunless
                    </flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            @can('transfers.create')
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-transfer-button">
                {{ __('transfers.record_transfer') }}
            </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @if ($this->paymentByCurrency !== [])
        <div class="grid gap-3 @2xl/directory:grid-cols-3">
            @foreach ($this->paymentByCurrency as $currencyCode => $total)
                <x-ui.stat-tile :label="__('transfers.sent_on_this_page')" :value="number_format($total, 2)" :unit="$currencyCode" :scope="$currencyCode" wire:key="tr-total-{{ $currencyCode }}" />
            @endforeach
        </div>
    @endif

    <x-ui.panel :subtitle="trans_choice('transfers.count_matching_transfer', $this->transfers->total(), ['count' => $this->transfers->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->transfers->isEmpty())
            <x-ui.empty-state
                class="rtl:[&_svg]:-scale-x-100"
                icon="arrow-right-circle"
                :heading="$filtersAreActive ? __('transfers.no_matching_transfers') : __('transfers.no_transfers_recorded')"
                :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('transfers.record_the_first_tranche_against_a_funding_agreement')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->transfers as $transfer)
                    <article class="grid gap-2 p-3.5 @4xl/directory:grid-cols-[1.4fr_auto_auto_auto_auto]" wire:key="transfer-{{ $transfer->id }}">
                        <div class="min-w-0">
                            <a href="{{ route('funding-agreements.show', $transfer->donorProposal) }}" wire:navigate class="text-sm font-semibold hover:underline">
                                {{ $transfer->donorProposal->donor->name }} · {{ $transfer->donorProposal->proposal->name }}
                            </a>
                            <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ $transfer->scheduled_date?->translatedFormat(__('formats.date')) ?? __('common.unscheduled') }}
                                ·
                                @if ($transfer->account === null)
                                    <span class="italic">{{ __('transfers.no_account') }}</span>
                                @else
                                    {{ $transfer->account->name }}
                                @endif
                            </p>
                        </div>

                        <div class="text-xs">
                            <p class="text-secondary-medium dark:text-secondary-soft">{{ __('transfers.sent') }}</p>
                            <x-ui.money :amount="$transfer->payment_amount" :currency="$transfer->amount_transfered_currency" />
                        </div>

                        <div class="text-xs">
                            <p class="text-secondary-medium dark:text-secondary-soft">{{ __('transfers.fees') }}</p>
                            <x-ui.money :amount="$transfer->fees" :currency="$transfer->fees_currency" />
                        </div>

                        <div class="text-xs">
                            <p class="text-secondary-medium dark:text-secondary-soft">{{ __('common.net_received') }}</p>
                            @if ($transfer->net_amount_received === null)
                                <span class="text-secondary-light">—</span>
                            @else
                                <x-ui.money :amount="$transfer->net_amount_received" :currency="$transfer->amount_received_currency" />
                            @endif
                        </div>

                        <div class="flex items-center gap-1.5">
                            <x-ui.enum-badge :case="$transfer->ar_status" />
                            {{-- No delete: a money record never disappears. --}}
                            @can('transfers.update')
                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $transfer->id }})" :aria-label="__('transfers.edit_transfer')" />
                            @endcan
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->transfers" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#transfer-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <x-funding.transfer-modal
        :editing="$editingTransferId !== null"
        :agreement-options="$showTransferModal ? $this->agreementOptions : []"
        :account-options="$showTransferModal ? $this->accountOptions : []"
    />
</section>
