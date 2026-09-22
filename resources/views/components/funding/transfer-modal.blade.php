{{--
    The payment tranche form, rendered by every page that uses App\Livewire\Concerns\ManagesTransferForm.
    Its wire:model bindings resolve against that page's component.
--}}
@props([
    'editing' => false,
    'agreementOptions' => [],
    'accountOptions' => [],
    'confirmingDelete' => false,
])

<flux:modal wire:model="showTransferModal" wire:close="closeTransferModal" class="w-full max-w-4xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
    <form wire:submit="saveTransfer" class="space-y-5">
        <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
            <flux:heading size="lg">{{ $editing ? __('transfers.edit_transfer') : __('transfers.record_transfer') }}</flux:heading>
            <flux:subheading>{{ __('transfers.sent_fees_and_received_each_carry_their_own_currency_they_ar') }}</flux:subheading>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.select wire:model.live="transferAgreementId" :label="__('transfers.funding_agreement')" required>
                <flux:select.option value="">{{ __('transfers.select_an_agreement') }}</flux:select.option>
                @foreach ($agreementOptions as $agreementOption)
                    <flux:select.option :value="$agreementOption->id" :wire:key="'tr-agr-'.$agreementOption->id">
                        {{ $agreementOption->donor->name }} · {{ $agreementOption->proposal->name }}@if (filled($agreementOption->project_num)) · {{ $agreementOption->project_num }}@endif · {{ number_format((float) $agreementOption->budget, 0) }} {{ $agreementOption->currency->value }}
                    </flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select wire:model="transferAccountId" :label="__('transfers.account')">
                <flux:select.option value="">{{ __('transfers.not_yet_assigned') }}</flux:select.option>
                @foreach ($accountOptions as $accountOption)
                    <flux:select.option :value="$accountOption->id" :wire:key="'tr-account-option-'.$accountOption->id">
                        {{ $accountOption->name }}@unless ($accountOption->is_active) · {{ __('common.closed') }}@endunless
                    </flux:select.option>
                @endforeach
            </x-ui.select>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.select wire:model="transferArStatus" :label="__('transfers.receipt_status')" required>
                @foreach (App\Enums\ArStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'tr-ar-'.$statusCase->value">{{ $statusCase->label() }}</flux:select.option>
                @endforeach
            </x-ui.select>
            <p class="self-end pb-2 text-xs text-secondary-medium dark:text-secondary-soft">{{ __('transfers.receipt_status_hint') }}</p>
        </div>

        <fieldset class="grid gap-4 rounded-lg border border-secondary-soft p-3 sm:grid-cols-2 lg:grid-cols-4 dark:border-secondary-medium">
            <legend class="px-1 text-xs font-semibold text-secondary-medium uppercase dark:text-secondary-soft">{{ __('transfers.sent') }}</legend>
            <x-ui.input wire:model="paymentAmount" :label="__('transfers.payment_amount')" type="number" step="0.01" min="0" required />
            <x-ui.select wire:model="transferredCurrency" :label="__('common.currency')" required>
                @foreach (App\Enums\Currency::cases() as $currencyCase)
                    <flux:select.option :value="$currencyCase->value" :wire:key="'tr-sent-'.$currencyCase->value">{{ $currencyCase->value }}</flux:select.option>
                @endforeach
            </x-ui.select>
            <x-ui.input wire:model="scheduledDate" :label="__('transfers.scheduled')" type="date" />
            <x-ui.input wire:model="transferDate" :label="__('transfers.sent_on')" type="date" />
        </fieldset>

        <fieldset class="grid gap-4 rounded-lg border border-secondary-soft p-3 sm:grid-cols-2 dark:border-secondary-medium">
            <legend class="px-1 text-xs font-semibold text-secondary-medium uppercase dark:text-secondary-soft">{{ __('transfers.fees') }}</legend>
            <x-ui.input wire:model="fees" :label="__('transfers.fees')" type="number" step="0.01" min="0" required />
            <x-ui.select wire:model="feesCurrency" :label="__('transfers.fees_currency')" required>
                @foreach (App\Enums\Currency::cases() as $currencyCase)
                    <flux:select.option :value="$currencyCase->value" :wire:key="'tr-fees-'.$currencyCase->value">{{ $currencyCase->value }}</flux:select.option>
                @endforeach
            </x-ui.select>
        </fieldset>

        <fieldset class="grid gap-4 rounded-lg border border-secondary-soft p-3 sm:grid-cols-2 lg:grid-cols-5 dark:border-secondary-medium">
            <legend class="px-1 text-xs font-semibold text-secondary-medium uppercase dark:text-secondary-soft">{{ __('transfers.received') }}</legend>
            <x-ui.input wire:model="amountReceived" :label="__('transfers.amount_received')" type="number" step="0.01" min="0" />
            <x-ui.input wire:model="netAmountReceived" :label="__('common.net_received')" type="number" step="0.01" min="0" />
            <x-ui.select wire:model="receivedCurrency" :label="__('common.currency')" required>
                @foreach (App\Enums\Currency::cases() as $currencyCase)
                    <flux:select.option :value="$currencyCase->value" :wire:key="'tr-recv-'.$currencyCase->value">{{ $currencyCase->value }}</flux:select.option>
                @endforeach
            </x-ui.select>
            <x-ui.input wire:model="exchangeRate" :label="__('transfers.exchange_rate')" type="number" step="0.000001" min="0" required />
            <x-ui.input wire:model="receiveDate" :label="__('transfers.received_on')" type="date" />
        </fieldset>

        @if ($editing && $confirmingDelete)
            <div class="flex flex-col gap-3 rounded-lg border border-primary/40 bg-primary/5 p-3 text-sm sm:flex-row sm:items-center sm:justify-between" role="alert" data-confirm-transfer-delete>
                <p class="text-secondary-dark dark:text-white">{{ __('transfers.delete_this_tranche_confirm') }}</p>
                <div class="flex shrink-0 gap-2">
                    <x-ui.button type="button" size="sm" wire:click="cancelTransferDelete">{{ __('common.cancel') }}</x-ui.button>
                    <x-ui.button type="button" size="sm" tone="primary" wire:click="deleteTransfer" data-test="confirm-delete-transfer">{{ __('transfers.delete_tranche') }}</x-ui.button>
                </div>
            </div>
        @endif

        <div class="flex flex-col-reverse gap-2 border-t border-secondary-soft pt-4 sm:flex-row sm:items-center dark:border-secondary-medium">
            @if ($editing)
                @can('transfers.delete')
                    <x-ui.button type="button" tone="ghost" icon="trash" class="min-h-11 w-full text-status-critical! sm:me-auto sm:min-h-10 sm:w-auto" wire:click="confirmTransferDelete" data-test="delete-transfer-button">{{ __('transfers.delete_tranche') }}</x-ui.button>
                @endcan
            @endif
            <div class="grid grid-cols-2 gap-2 sm:ms-auto sm:flex">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeTransferModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-transfer-button">
                    {{ $editing ? __('common.save_changes') : __('transfers.record_transfer') }}
                </x-ui.button>
            </div>
        </div>
    </form>
</flux:modal>
