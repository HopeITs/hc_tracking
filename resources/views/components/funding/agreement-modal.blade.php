{{--
    The funding agreement form, rendered by every page that uses App\Livewire\Concerns\ManagesAgreementForm.
    Its wire:model bindings resolve against that page's component. A page pinned to one proposal passes
    lockedProposalName instead of proposal options, and the proposal is shown rather than chosen.
--}}
@props([
    'open' => false,
    'editing' => false,
    'donorOptions' => [],
    'proposalOptions' => [],
    'lockedProposalName' => null,
])

<flux:modal wire:model="showAgreementModal" wire:close="closeAgreementModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
    <form wire:submit="saveAgreement" class="space-y-5">
        <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
            <flux:heading size="lg">{{ $editing ? __('funding_agreements.edit_agreement') : __('funding_agreements.record_agreement') }}</flux:heading>
            <flux:subheading>{{ __('funding_agreements.the_currency_is_part_of_the_amount_budgets_in_different_curr') }}</flux:subheading>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.select wire:model="agreementDonorId" :label="__('funding_agreements.donor')" required>
                <flux:select.option value="">{{ __('funding_agreements.select_a_donor') }}</flux:select.option>
                {{-- Options are only passed while the modal is open: the form is not the
                     filtered directory, but its full names would still satisfy an
                     assertDontSee() against the directory list if always rendered. --}}
                @foreach ($donorOptions as $donorOption)
                    <flux:select.option :value="$donorOption->id" :wire:key="'agr-donor-option-'.$donorOption->id">{{ $donorOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>

            @if ($lockedProposalName !== null)
                <div class="min-w-0">
                    <p class="text-sm font-medium text-secondary-dark dark:text-white">{{ __('common.proposal') }}</p>
                    <p class="mt-2 truncate text-sm text-secondary-medium dark:text-secondary-soft" data-locked-proposal>{{ $lockedProposalName }}</p>
                </div>
            @else
                <x-ui.select wire:model="agreementProposalId" :label="__('common.proposal')" required>
                    <flux:select.option value="">{{ __('common.select_a_proposal') }}</flux:select.option>
                    @foreach ($proposalOptions as $proposalOption)
                        <flux:select.option :value="$proposalOption->id" :wire:key="'agr-proposal-option-'.$proposalOption->id">{{ $proposalOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            @endif
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <x-ui.input wire:model="budget" :label="__('funding_agreements.agreed_budget')" type="number" step="0.01" min="0" required />

            <x-ui.select wire:model="agreementCurrency" :label="__('common.currency')" required>
                @foreach (App\Enums\Currency::cases() as $currencyCase)
                    <flux:select.option :value="$currencyCase->value" :wire:key="'agr-currency-option-'.$currencyCase->value">{{ $currencyCase->value }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.input wire:model="projectNum" :label="__('funding_agreements.donor_reference')" type="text" maxlength="50" />
        </div>

        <div class="rounded-lg border border-secondary-soft bg-secondary-soft/50 p-3 text-sm text-secondary-medium dark:border-secondary-medium dark:bg-secondary-medium/50 dark:text-secondary-soft">
            <div class="flex items-start gap-2.5">
                <flux:icon.information-circle class="mt-0.5 size-4 shrink-0" />
                <p>{{ __('funding_agreements.agreements_cannot_be_deleted_from_this_system_every_transfer') }}</p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
            <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeAgreementModal">{{ __('common.cancel') }}</x-ui.button>
            <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-agreement-button">
                {{ $editing ? __('common.save_changes') : __('funding_agreements.record_agreement') }}
            </x-ui.button>
        </div>
    </form>
</flux:modal>
