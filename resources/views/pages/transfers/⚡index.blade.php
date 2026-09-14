<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Transfers')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $arStatus = '';

    #[Url(except: '')]
    public string $account = '';

    public bool $showTransferModal = false;
    public ?int $editingTransferId = null;

    public string $transferAgreementId = '';
    public string $transferAccountId = '';
    public string $paymentAmount = '';
    public string $transferredCurrency = 'USD';
    public string $scheduledDate = '';
    public string $transferDate = '';
    public string $fees = '0';
    public string $feesCurrency = 'USD';
    public string $amountReceived = '';
    public string $exchangeRate = '1.000000';
    public string $netAmountReceived = '';
    public string $receivedCurrency = 'USD';
    public string $receiveDate = '';
    public string $transferArStatus = 'pending';

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

    /** @return Collection<int, Account> */
    #[Computed]
    public function accountOptions(): Collection
    {
        return Account::query()->select(['id', 'name', 'is_active'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, DonorProposal> */
    #[Computed]
    public function agreementOptions(): Collection
    {
        return DonorProposal::query()
            ->select(['id', 'donor_id', 'proposal_id', 'project_num'])
            ->with(['donor:id,name', 'proposal:id,name'])
            ->orderBy('id')
            ->get();
    }

    public function openCreateModal(): void
    {
        $this->resetTransferForm();
        $this->showTransferModal = true;
    }

    public function openEditModal(int $transferId): void
    {
        $transfer = TransfersTracking::query()->findOrFail($transferId);

        $this->resetValidation();
        $this->editingTransferId = $transfer->id;
        $this->transferAgreementId = (string) $transfer->donor_proposal_id;
        $this->transferAccountId = (string) ($transfer->account_id ?? '');
        $this->paymentAmount = (string) $transfer->payment_amount;
        $this->transferredCurrency = $transfer->amount_transfered_currency->value;
        $this->scheduledDate = $transfer->scheduled_date?->toDateString() ?? '';
        $this->transferDate = $transfer->transfer_date?->toDateString() ?? '';
        $this->fees = (string) $transfer->fees;
        $this->feesCurrency = $transfer->fees_currency->value;
        $this->amountReceived = (string) ($transfer->amount_received ?? '');
        $this->exchangeRate = (string) $transfer->exchange_rate;
        $this->netAmountReceived = (string) ($transfer->net_amount_received ?? '');
        $this->receivedCurrency = $transfer->amount_received_currency->value;
        $this->receiveDate = $transfer->receive_date?->toDateString() ?? '';
        $this->transferArStatus = $transfer->ar_status->value;
        $this->showTransferModal = true;
    }

    public function saveTransfer(): void
    {
        $validated = $this->validate([
            'transferAgreementId' => ['required', 'integer', Rule::exists(DonorProposal::class, 'id')],
            'transferAccountId' => ['nullable', 'integer', Rule::exists(Account::class, 'id')],
            'paymentAmount' => ['required', 'numeric', 'min:0'],
            'transferredCurrency' => ['required', new Enum(Currency::class)],
            'scheduledDate' => ['nullable', 'date'],
            'transferDate' => ['nullable', 'date'],
            'fees' => ['required', 'numeric', 'min:0'],
            'feesCurrency' => ['required', new Enum(Currency::class)],
            'amountReceived' => ['nullable', 'numeric', 'min:0'],
            'exchangeRate' => ['required', 'numeric', 'min:0'],
            'netAmountReceived' => ['nullable', 'numeric', 'min:0'],
            'receivedCurrency' => ['required', new Enum(Currency::class)],
            'receiveDate' => ['nullable', 'date'],
            'transferArStatus' => ['required', new Enum(ArStatus::class)],
        ], [
            'transferAgreementId.required' => __('Select the funding agreement this tranche belongs to.'),
            'paymentAmount.required' => __('Enter the payment amount.'),
            'paymentAmount.min' => __('A payment amount cannot be negative.'),
            'fees.min' => __('Fees cannot be negative.'),
            'exchangeRate.min' => __('An exchange rate cannot be negative.'),
        ]);

        $transfer = $this->editingTransferId === null
            ? new TransfersTracking
            : TransfersTracking::query()->findOrFail($this->editingTransferId);

        $transfer->fill([
            'donor_proposal_id' => (int) $validated['transferAgreementId'],
            'account_id' => $validated['transferAccountId'] === '' || $validated['transferAccountId'] === null
                ? null
                : (int) $validated['transferAccountId'],
            'payment_amount' => $validated['paymentAmount'],
            'amount_transfered_currency' => $validated['transferredCurrency'],
            'scheduled_date' => $validated['scheduledDate'] === '' ? null : $validated['scheduledDate'],
            'transfer_date' => $validated['transferDate'] === '' ? null : $validated['transferDate'],
            'fees' => $validated['fees'],
            'fees_currency' => $validated['feesCurrency'],
            'amount_received' => $validated['amountReceived'] === '' ? null : $validated['amountReceived'],
            'exchange_rate' => $validated['exchangeRate'],
            'net_amount_received' => $validated['netAmountReceived'] === '' ? null : $validated['netAmountReceived'],
            'amount_received_currency' => $validated['receivedCurrency'],
            'receive_date' => $validated['receiveDate'] === '' ? null : $validated['receiveDate'],
            'ar_status' => $validated['transferArStatus'],
        ])->save();

        $wasRecentlyCreated = $transfer->wasRecentlyCreated;

        $this->showTransferModal = false;
        $this->resetTransferForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('Transfer recorded.') : __('Transfer updated.'),
        );
    }

    public function closeTransferModal(): void
    {
        $this->showTransferModal = false;
        $this->resetTransferForm();
    }

    protected function resetTransferForm(): void
    {
        $this->resetValidation();
        $this->editingTransferId = null;
        $this->transferAgreementId = '';
        $this->transferAccountId = '';
        $this->paymentAmount = '';
        $this->transferredCurrency = 'USD';
        $this->scheduledDate = '';
        $this->transferDate = '';
        $this->fees = '0';
        $this->feesCurrency = 'USD';
        $this->amountReceived = '';
        $this->exchangeRate = '1.000000';
        $this->netAmountReceived = '';
        $this->receivedCurrency = 'USD';
        $this->receiveDate = '';
        $this->transferArStatus = 'pending';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $arStatus !== '' || $account !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="transfer-directory-heading">
    <x-ui.page-header
        heading-id="transfer-directory-heading"
        :title="__('Transfers')"
        :count="$this->transfers->total()"
        :description="__('Every tranche, what was sent, what landed, and in which currency.')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('Search transfers')" :placeholder="__('Reference, donor, or proposal')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.select :label="__('Receipt status')" wire:model.live="arStatus">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                @foreach (ArStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'ar-'.$statusCase->value">{{ Str::title($statusCase->value) }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Account')" wire:model.live="account">
                <flux:select.option value="">{{ __('All accounts') }}</flux:select.option>
                @foreach ($this->accountOptions as $accountOption)
                    <flux:select.option :value="$accountOption->id" :wire:key="'tr-account-'.$accountOption->id">
                        {{ $accountOption->name }}@unless ($accountOption->is_active) · {{ __('closed') }}@endunless
                    </flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-transfer-button">
                {{ __('Record transfer') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($this->paymentByCurrency !== [])
        <div class="grid gap-3 @2xl/directory:grid-cols-3">
            @foreach ($this->paymentByCurrency as $currencyCode => $total)
                <x-ui.stat-tile :label="__('Sent on this page')" :value="number_format($total, 2)" :unit="$currencyCode" :scope="$currencyCode" wire:key="tr-total-{{ $currencyCode }}" />
            @endforeach
        </div>
    @endif

    <x-ui.panel :subtitle="trans_choice('{1} :count matching transfer|[2,*] :count matching transfers', $this->transfers->total(), ['count' => $this->transfers->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->transfers->isEmpty())
            <x-ui.empty-state
                icon="arrow-right-circle"
                :heading="$filtersAreActive ? __('No matching transfers') : __('No transfers recorded')"
                :body="$filtersAreActive ? __('Change the search or filters and try again.') : __('Record the first tranche against a funding agreement.')"
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
                                {{ $transfer->scheduled_date?->format('M j, Y') ?? __('Unscheduled') }}
                                ·
                                @if ($transfer->account === null)
                                    <span class="italic">{{ __('No account') }}</span>
                                @else
                                    {{ $transfer->account->name }}
                                @endif
                            </p>
                        </div>

                        <div class="text-xs">
                            <p class="text-secondary-medium dark:text-secondary-soft">{{ __('Sent') }}</p>
                            <x-ui.money :amount="$transfer->payment_amount" :currency="$transfer->amount_transfered_currency" />
                        </div>

                        <div class="text-xs">
                            <p class="text-secondary-medium dark:text-secondary-soft">{{ __('Fees') }}</p>
                            <x-ui.money :amount="$transfer->fees" :currency="$transfer->fees_currency" />
                        </div>

                        <div class="text-xs">
                            <p class="text-secondary-medium dark:text-secondary-soft">{{ __('Net received') }}</p>
                            @if ($transfer->net_amount_received === null)
                                <span class="text-secondary-light">—</span>
                            @else
                                <x-ui.money :amount="$transfer->net_amount_received" :currency="$transfer->amount_received_currency" />
                            @endif
                        </div>

                        <div class="flex items-center gap-1.5">
                            <x-ui.enum-badge :case="$transfer->ar_status" />
                            {{-- No delete: a money record never disappears. --}}
                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $transfer->id }})" :aria-label="__('Edit transfer')" />
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->transfers" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#transfer-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showTransferModal" class="w-full max-w-4xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveTransfer" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingTransferId === null ? __('Record transfer') : __('Edit transfer') }}</flux:heading>
                <flux:subheading>{{ __('Sent, fees and received each carry their own currency. They are recorded separately and never combined.') }}</flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model="transferAgreementId" :label="__('Funding agreement')" required>
                    <flux:select.option value="">{{ __('Select an agreement') }}</flux:select.option>
                    @foreach ($this->agreementOptions as $agreementOption)
                        <flux:select.option :value="$agreementOption->id" :wire:key="'tr-agr-'.$agreementOption->id">
                            {{ $agreementOption->donor->name }} · {{ $agreementOption->proposal->name }}
                        </flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="transferAccountId" :label="__('Account')">
                    <flux:select.option value="">{{ __('Not yet assigned') }}</flux:select.option>
                    @foreach ($this->accountOptions as $accountOption)
                        <flux:select.option :value="$accountOption->id" :wire:key="'tr-account-option-'.$accountOption->id">
                            {{ $accountOption->name }}@unless ($accountOption->is_active) · {{ __('closed') }}@endunless
                        </flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <fieldset class="grid gap-4 rounded-lg border border-secondary-soft p-3 sm:grid-cols-4 dark:border-secondary-medium">
                <legend class="px-1 text-xs font-semibold text-secondary-medium uppercase dark:text-secondary-soft">{{ __('Sent') }}</legend>
                <x-ui.input wire:model="paymentAmount" :label="__('Payment amount')" type="number" step="0.01" min="0" required />
                <x-ui.select wire:model="transferredCurrency" :label="__('Currency')" required>
                    @foreach (Currency::cases() as $currencyCase)
                        <flux:select.option :value="$currencyCase->value" :wire:key="'tr-sent-'.$currencyCase->value">{{ $currencyCase->value }}</flux:select.option>
                    @endforeach
                </x-ui.select>
                <x-ui.input wire:model="scheduledDate" :label="__('Scheduled')" type="date" />
                <x-ui.input wire:model="transferDate" :label="__('Sent on')" type="date" />
            </fieldset>

            <fieldset class="grid gap-4 rounded-lg border border-secondary-soft p-3 sm:grid-cols-2 dark:border-secondary-medium">
                <legend class="px-1 text-xs font-semibold text-secondary-medium uppercase dark:text-secondary-soft">{{ __('Fees') }}</legend>
                <x-ui.input wire:model="fees" :label="__('Fees')" type="number" step="0.01" min="0" required />
                <x-ui.select wire:model="feesCurrency" :label="__('Fees currency')" required>
                    @foreach (Currency::cases() as $currencyCase)
                        <flux:select.option :value="$currencyCase->value" :wire:key="'tr-fees-'.$currencyCase->value">{{ $currencyCase->value }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </fieldset>

            <fieldset class="grid gap-4 rounded-lg border border-secondary-soft p-3 sm:grid-cols-5 dark:border-secondary-medium">
                <legend class="px-1 text-xs font-semibold text-secondary-medium uppercase dark:text-secondary-soft">{{ __('Received') }}</legend>
                <x-ui.input wire:model="amountReceived" :label="__('Amount received')" type="number" step="0.01" min="0" />
                <x-ui.input wire:model="netAmountReceived" :label="__('Net received')" type="number" step="0.01" min="0" />
                <x-ui.select wire:model="receivedCurrency" :label="__('Currency')" required>
                    @foreach (Currency::cases() as $currencyCase)
                        <flux:select.option :value="$currencyCase->value" :wire:key="'tr-recv-'.$currencyCase->value">{{ $currencyCase->value }}</flux:select.option>
                    @endforeach
                </x-ui.select>
                <x-ui.input wire:model="exchangeRate" :label="__('Exchange rate')" type="number" step="0.000001" min="0" required />
                <x-ui.input wire:model="receiveDate" :label="__('Received on')" type="date" />
            </fieldset>

            <x-ui.select wire:model="transferArStatus" :label="__('Receipt status')" required>
                @foreach (ArStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'tr-ar-'.$statusCase->value">{{ Str::title($statusCase->value) }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeTransferModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-transfer-button">
                    {{ $editingTransferId === null ? __('Record transfer') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>
</section>
