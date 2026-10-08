{{--
    The funding agreement form, rendered by every page that uses App\Livewire\Concerns\ManagesAgreementForm.
    Its wire:model bindings resolve against that page's component. A page pinned to one proposal passes
    lockedProposalName instead of proposal options, and the proposal is shown rather than chosen; a page
    pinned to one donor passes lockedDonorName the same way.
--}}
@props([
    'open' => true,
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

<x-ui.modal
    wire:model="showAgreementModal"
    size="xl"
    :title="$editing ? __('funding_agreements.edit_agreement') : __('funding_agreements.record_agreement')"
    :description="__('funding_agreements.the_currency_is_part_of_the_amount_budgets_in_different_curr')"
    submit="saveAgreement"
    close="closeAgreementModal"
    :required-note="! $editing"
>
    @if ($open)
        <x-ui.form-section :title="__('funding_agreements.section_parties')" :fields="['agreementDonorId', 'agreementProposalId']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                @if ($lockedDonorName !== null)
                    <x-ui.form-field :label="__('funding_agreements.donor')" for="agreement-locked-donor">
                        <div id="agreement-locked-donor" class="form-box" data-locked data-locked-donor>
                            <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0 text-secondary-light" />
                            <span class="truncate">{{ $lockedDonorName }}</span>
                        </div>
                    </x-ui.form-field>
                @else
                    {{-- Options are only passed while the modal is open: the form is not the
                         filtered directory, but its full names would still satisfy an
                         assertDontSee() against the directory list if always rendered. --}}
                    <x-ui.form-select wire:model="agreementDonorId" :label="__('funding_agreements.donor')" :placeholder="__('funding_agreements.select_a_donor')" required autofocus>
                        @foreach ($donorOptions as $donorOption)
                            <option value="{{ $donorOption->id }}">{{ $donorOption->name }}</option>
                        @endforeach
                    </x-ui.form-select>
                @endif

                @if ($lockedProposalName !== null)
                    <x-ui.form-field :label="__('common.proposal')" for="agreement-locked-proposal">
                        <div id="agreement-locked-proposal" class="form-box" data-locked data-locked-proposal>
                            <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0 text-secondary-light" />
                            <span class="truncate">{{ $lockedProposalName }}</span>
                        </div>
                    </x-ui.form-field>
                @else
                    <x-ui.form-select wire:model.live="agreementProposalId" :label="__('common.proposal')" :placeholder="__('common.select_a_proposal')" required>
                        @foreach ($proposalOptions as $proposalOption)
                            <option value="{{ $proposalOption->id }}">{{ $proposalOption->name }} · {{ $proposalOption->internal_project_number }}</option>
                        @endforeach
                    </x-ui.form-select>
                @endif
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('funding_agreements.section_amount')" :fields="['budget', 'agreementCurrency', 'externalProjectNumber']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-3">
                <x-ui.form-input wire:model="budget" :label="__('funding_agreements.agreed_budget')" type="number" step="0.01" min="0" required />

                @if ($lockedCurrency !== null)
                    <x-ui.form-field :label="__('common.currency')" for="agreement-locked-currency" :help="__('funding_agreements.currency_is_fixed_once_tranches_exist')" data-locked-currency>
                        <div id="agreement-locked-currency" class="form-box" data-locked>
                            <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0 text-secondary-light" />
                            <span>{{ $lockedCurrency }}</span>
                        </div>
                    </x-ui.form-field>
                @else
                    <x-ui.form-segmented
                        wire:model="agreementCurrency"
                        :label="__('common.currency')"
                        required
                        :options="collect(App\Enums\Currency::cases())->map(fn (App\Enums\Currency $currencyCase): array => ['value' => $currencyCase->value, 'label' => $currencyCase->value])->all()"
                    />
                @endif

                <x-ui.form-input wire:model="externalProjectNumber" :label="__('funding_agreements.external_project_number')" maxlength="50" />
            </div>
        </x-ui.form-section>

        @if ($warning !== null)
            <div class="mb-3 flex items-start gap-2.5 rounded-lg border border-status-warn/40 bg-status-warn/10 p-3 text-sm text-secondary-dark dark:text-ink" role="status" data-agreement-proposal-warning>
                <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0 text-status-warn" />
                <p>{{ $warning }}</p>
            </div>
        @endif

        @if ($editing && $confirmingDelete)
            <div class="mb-3 flex flex-col gap-3 rounded-lg border border-primary/40 bg-primary/5 p-3 text-sm sm:flex-row sm:items-center sm:justify-between" role="alert" data-confirm-agreement-delete>
                <p class="text-secondary-dark dark:text-ink">{{ __('funding_agreements.delete_this_agreement_confirm') }}</p>
                <div class="flex shrink-0 gap-2">
                    <x-ui.button type="button" size="sm" wire:click="cancelAgreementDelete">{{ __('common.cancel') }}</x-ui.button>
                    <x-ui.button type="button" size="sm" tone="primary" wire:click="deleteAgreement" data-test="confirm-delete-agreement">{{ __('funding_agreements.delete_agreement') }}</x-ui.button>
                </div>
            </div>
        @endif
    @endif

    @if ($editing)
        <x-slot:footerMeta>
            @can('funding-agreements.delete')
                @if ($deleteBlocker === null)
                    <x-ui.button type="button" tone="ghost" icon="trash" class="text-status-critical!" wire:click="confirmAgreementDelete" data-test="delete-agreement-button">{{ __('funding_agreements.delete_agreement') }}</x-ui.button>
                @else
                    <p class="flex items-center gap-1.5 text-xs text-secondary-medium dark:text-ink-muted" data-agreement-delete-blocked>
                        <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0" />
                        {{ $deleteBlocker }}
                    </p>
                @endif
            @endcan
        </x-slot:footerMeta>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="save-agreement-button">
            {{ $editing ? __('common.save_changes') : __('funding_agreements.record_agreement') }}
        </x-ui.button>
    </x-slot:actions>
</x-ui.modal>
