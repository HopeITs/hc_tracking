{{--
    The funding agreement form, rendered by every page that uses App\Livewire\Concerns\ManagesAgreementForm.
    Its wire:model bindings resolve against that page's component. A page pinned to one proposal passes
    lockedProposalName instead of proposal options, and the proposal is shown rather than chosen; a page
    pinned to one donor passes lockedDonorName the same way.
--}}
@props([
    'open' => false,
    'editing' => false,
    'donorOptions' => [],
    'proposalOptions' => [],
    'lockedProposalName' => null,
    'lockedDonorName' => null,
    'warning' => null,
    'deleteBlocker' => null,
    'confirmingDelete' => false,
    'lockedCurrency' => null,
])

<flux:modal wire:model="showAgreementModal" wire:close="closeAgreementModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
    <form wire:submit="saveAgreement" class="space-y-5">
        <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
            <flux:heading size="lg">{{ $editing ? __('funding_agreements.edit_agreement') : __('funding_agreements.record_agreement') }}</flux:heading>
            <flux:subheading>{{ __('funding_agreements.the_currency_is_part_of_the_amount_budgets_in_different_curr') }}</flux:subheading>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            @if ($lockedDonorName !== null)
                <div class="min-w-0">
                    <p class="text-sm font-medium text-secondary-dark dark:text-white">{{ __('funding_agreements.donor') }}</p>
                    <p class="mt-2 truncate text-sm text-secondary-medium dark:text-secondary-soft" data-locked-donor>{{ $lockedDonorName }}</p>
                </div>
            @else
            <x-ui.select wire:model="agreementDonorId" :label="__('funding_agreements.donor')" required>
                <flux:select.option value="">{{ __('funding_agreements.select_a_donor') }}</flux:select.option>
                {{-- Options are only passed while the modal is open: the form is not the
                     filtered directory, but its full names would still satisfy an
                     assertDontSee() against the directory list if always rendered. --}}
                @foreach ($donorOptions as $donorOption)
                    <flux:select.option :value="$donorOption->id" :wire:key="'agr-donor-option-'.$donorOption->id">{{ $donorOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>
            @endif

            @if ($lockedProposalName !== null)
                <div class="min-w-0">
                    <p class="text-sm font-medium text-secondary-dark dark:text-white">{{ __('common.proposal') }}</p>
                    <p class="mt-2 truncate text-sm text-secondary-medium dark:text-secondary-soft" data-locked-proposal>{{ $lockedProposalName }}</p>
                </div>
            @else
                <x-ui.select wire:model.live="agreementProposalId" :label="__('common.proposal')" required>
                    <flux:select.option value="">{{ __('common.select_a_proposal') }}</flux:select.option>
                    @foreach ($proposalOptions as $proposalOption)
                        <flux:select.option :value="$proposalOption->id" :wire:key="'agr-proposal-option-'.$proposalOption->id">{{ $proposalOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            @endif
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <x-ui.input wire:model="budget" :label="__('funding_agreements.agreed_budget')" type="number" step="0.01" min="0" required />

            @if ($lockedCurrency !== null)
                <div class="min-w-0" data-locked-currency>
                    <p class="text-sm font-medium text-secondary-dark dark:text-white">{{ __('common.currency') }}</p>
                    <p class="mt-2 flex items-center gap-1.5 text-sm font-semibold text-secondary-dark dark:text-white">
                        <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0 text-secondary-medium dark:text-secondary-soft" />
                        {{ $lockedCurrency }}
                    </p>
                    <p class="mt-1 text-xs text-secondary-medium dark:text-secondary-soft">{{ __('funding_agreements.currency_is_fixed_once_tranches_exist') }}</p>
                </div>
            @else
            <x-ui.select wire:model="agreementCurrency" :label="__('common.currency')" required>
                @foreach (App\Enums\Currency::cases() as $currencyCase)
                    <flux:select.option :value="$currencyCase->value" :wire:key="'agr-currency-option-'.$currencyCase->value">{{ $currencyCase->value }}</flux:select.option>
                @endforeach
            </x-ui.select>
            @endif

            <x-ui.input wire:model="projectNum" :label="__('funding_agreements.donor_reference')" type="text" maxlength="50" />
        </div>

        @if ($warning !== null)
            <div class="flex items-start gap-2.5 rounded-lg border border-status-warn/40 bg-status-warn/10 p-3 text-sm text-secondary-dark dark:text-white" role="status" data-agreement-proposal-warning>
                <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0 text-status-warn" />
                <p>{{ $warning }}</p>
            </div>
        @endif

        @if ($editing && $confirmingDelete)
            <div class="flex flex-col gap-3 rounded-lg border border-primary/40 bg-primary/5 p-3 text-sm sm:flex-row sm:items-center sm:justify-between" role="alert" data-confirm-agreement-delete>
                <p class="text-secondary-dark dark:text-white">{{ __('funding_agreements.delete_this_agreement_confirm') }}</p>
                <div class="flex shrink-0 gap-2">
                    <x-ui.button type="button" size="sm" wire:click="cancelAgreementDelete">{{ __('common.cancel') }}</x-ui.button>
                    <x-ui.button type="button" size="sm" tone="primary" wire:click="deleteAgreement" data-test="confirm-delete-agreement">{{ __('funding_agreements.delete_agreement') }}</x-ui.button>
                </div>
            </div>
        @endif

        <div class="flex flex-col-reverse gap-2 border-t border-secondary-soft pt-4 sm:flex-row sm:items-center dark:border-secondary-medium">
            @if ($editing)
                @can('funding-agreements.delete')
                    @if ($deleteBlocker === null)
                        <x-ui.button type="button" tone="ghost" icon="trash" class="min-h-11 w-full text-status-critical! sm:me-auto sm:min-h-10 sm:w-auto" wire:click="confirmAgreementDelete" data-test="delete-agreement-button">{{ __('funding_agreements.delete_agreement') }}</x-ui.button>
                    @else
                        <p class="flex items-center gap-1.5 text-xs text-secondary-medium sm:me-auto dark:text-secondary-soft" data-agreement-delete-blocked>
                            <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0" />
                            {{ $deleteBlocker }}
                        </p>
                    @endif
                @endcan
            @endif
            <div class="grid grid-cols-2 gap-2 sm:ms-auto sm:flex">
            <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeAgreementModal">{{ __('common.cancel') }}</x-ui.button>
            <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-agreement-button">
                {{ $editing ? __('common.save_changes') : __('funding_agreements.record_agreement') }}
            </x-ui.button>
            </div>
        </div>
    </form>
</flux:modal>
