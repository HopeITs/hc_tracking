{{--
    The payment tranche form, rendered by every page that uses App\Livewire\Concerns\ManagesTransferForm.
    Its wire:model bindings resolve against that page's component.
--}}
@props([
    'open' => true,
    'editing' => false,
    'agreementOptions' => [],
    'accountOptions' => [],
    'confirmingDelete' => false,
])

@php
    $currencyOptions = collect(App\Enums\Currency::cases())
        ->map(fn (App\Enums\Currency $currencyCase): array => ['value' => $currencyCase->value, 'label' => $currencyCase->value])
        ->all();
@endphp

<x-ui.modal
    wire:model="showTransferModal"
    size="xl"
    :title="$editing ? __('transfers.edit_transfer') : __('transfers.record_transfer')"
    :description="__('transfers.sent_fees_and_received_each_carry_their_own_currency_they_ar')"
    submit="saveTransfer"
    close="closeTransferModal"
    :required-note="! $editing"
>
    @if ($open)
        <x-ui.form-section :title="__('common.section_details')" :fields="['transferAgreementId', 'transferAccountId', 'transferArStatus']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-select wire:model.live="transferAgreementId" :label="__('transfers.funding_agreement')" :placeholder="__('transfers.select_an_agreement')" required autofocus class="@2xl/modal:col-span-2">
                    @foreach ($agreementOptions as $agreementOption)
                        <option value="{{ $agreementOption->id }}">{{ $agreementOption->donor->name }} · {{ $agreementOption->proposal->name }} · {{ $agreementOption->proposal->internal_project_number }}@if (filled($agreementOption->external_project_number)) · {{ $agreementOption->external_project_number }}@endif · {{ number_format((float) $agreementOption->budget, 0) }} {{ $agreementOption->currency->value }}</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-select wire:model="transferAccountId" :label="__('transfers.account')">
                    <option value="">{{ __('transfers.not_yet_assigned') }}</option>
                    @foreach ($accountOptions as $accountOption)
                        <option value="{{ $accountOption->id }}">{{ $accountOption->account_name }}@unless ($accountOption->is_active) · {{ __('common.closed') }}@endunless</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-segmented
                    wire:model="transferArStatus"
                    :label="__('transfers.receipt_status')"
                    :help="__('transfers.receipt_status_hint')"
                    required
                    :options="collect(App\Enums\ArStatus::cases())->map(fn (App\Enums\ArStatus $statusCase): array => ['value' => $statusCase->value, 'label' => $statusCase->label()])->all()"
                />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('transfers.sent')" :fields="['paymentAmount', 'transferredCurrency', 'scheduledDate', 'transferDate']">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-4">
                <x-ui.form-input wire:model="paymentAmount" :label="__('transfers.payment_amount')" type="number" step="0.01" min="0" required />
                <x-ui.form-segmented wire:model="transferredCurrency" :label="__('common.currency')" required :options="$currencyOptions" />
                <x-ui.form-input wire:model="scheduledDate" :label="__('transfers.scheduled')" type="date" />
                <x-ui.form-input wire:model="transferDate" :label="__('transfers.sent_on')" type="date" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('transfers.fees')" :fields="['fees', 'feesCurrency']">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-4">
                <x-ui.form-input wire:model="fees" :label="__('transfers.fees')" type="number" step="0.01" min="0" required />
                <x-ui.form-segmented wire:model="feesCurrency" :label="__('transfers.fees_currency')" required :options="$currencyOptions" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('transfers.received')" :fields="['amountReceived', 'netAmountReceived', 'receivedCurrency', 'exchangeRate', 'receiveDate']">
            <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-4">
                <x-ui.form-input wire:model="amountReceived" :label="__('transfers.amount_received')" type="number" step="0.01" min="0" />
                <x-ui.form-input wire:model="netAmountReceived" :label="__('common.net_received')" type="number" step="0.01" min="0" />
                <x-ui.form-segmented wire:model="receivedCurrency" :label="__('common.currency')" required :options="$currencyOptions" />
                <x-ui.form-input wire:model="exchangeRate" :label="__('transfers.exchange_rate')" type="number" step="0.000001" min="0" required />
                <x-ui.form-input wire:model="receiveDate" :label="__('transfers.received_on')" type="date" />
            </div>
        </x-ui.form-section>

        @if ($editing && $confirmingDelete)
            <div class="mb-3 flex flex-col gap-3 rounded-lg border border-primary/40 bg-primary/5 p-3 text-sm sm:flex-row sm:items-center sm:justify-between" role="alert" data-confirm-transfer-delete>
                <p class="text-secondary-dark dark:text-ink">{{ __('transfers.delete_this_tranche_confirm') }}</p>
                <div class="flex shrink-0 gap-2">
                    <x-ui.button type="button" size="sm" wire:click="cancelTransferDelete">{{ __('common.cancel') }}</x-ui.button>
                    <x-ui.button type="button" size="sm" tone="primary" wire:click="deleteTransfer" data-test="confirm-delete-transfer">{{ __('transfers.delete_tranche') }}</x-ui.button>
                </div>
            </div>
        @endif
    @endif

    @if ($editing)
        <x-slot:footerMeta>
            @can('transfers.delete')
                <x-ui.button type="button" tone="ghost" icon="trash" class="text-status-critical!" wire:click="confirmTransferDelete" data-test="delete-transfer-button">{{ __('transfers.delete_tranche') }}</x-ui.button>
            @endcan
        </x-slot:footerMeta>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="save-transfer-button">
            {{ $editing ? __('common.save_changes') : __('transfers.record_transfer') }}
        </x-ui.button>
    </x-slot:actions>
</x-ui.modal>
