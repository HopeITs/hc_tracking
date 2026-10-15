{{--
    The funding agreement form, rendered by every page that uses App\Livewire\Concerns\ManagesAgreementForm.
    Its wire:model bindings resolve against that page's component. A page pinned to one proposal passes
    lockedProposalName instead of proposal options, and the proposal is shown rather than chosen; a page
    pinned to one donor passes lockedDonorName the same way.
--}}
@props([
    'open' => true,
    'editing' => false,
    'proposalOptions' => [],
    'lockedProposalName' => null,
    'donorName' => null,
    'warning' => null,
    'installments' => [],
    'installmentsTotal' => '0.00',
    'installmentsDiffer' => false,
    'currencyCode' => 'USD',
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
        <x-ui.form-section :title="__('funding_agreements.section_parties')" :fields="['agreementProposalId']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                {{-- The donor is never chosen: it is the proposal's, shown for confirmation only. --}}
                <x-ui.form-field :label="__('funding_agreements.donor')" for="agreement-locked-donor" :help="__('funding_agreements.donor_comes_from_the_proposal')">
                    <div id="agreement-locked-donor" class="form-box" data-locked data-locked-donor>
                        <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0 text-secondary-light" />
                        <span class="truncate" dir="auto">{{ $donorName ?? __('funding_agreements.donor_follows_the_proposal') }}</span>
                    </div>
                </x-ui.form-field>

                @if ($lockedProposalName !== null)
                    <x-ui.form-field :label="__('common.proposal')" for="agreement-locked-proposal">
                        <div id="agreement-locked-proposal" class="form-box" data-locked data-locked-proposal>
                            <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0 text-secondary-light" />
                            <span class="truncate" dir="auto">{{ $lockedProposalName }}</span>
                        </div>
                    </x-ui.form-field>
                @else
                    <x-ui.form-select wire:model.live="agreementProposalId" :label="__('common.proposal')" :placeholder="__('common.select_a_proposal')" :help="__('funding_agreements.only_donor_approved_proposals_without_an_agreement')" required autofocus>
                        @foreach ($proposalOptions as $proposalOption)
                            <option value="{{ $proposalOption->id }}">{{ $proposalOption->name }} · {{ $proposalOption->internal_project_number }} · {{ $proposalOption->donor->name }}</option>
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

        <x-ui.form-section :title="__('funding_agreements.section_installments')" :fields="['installments', 'confirmInstallmentSum']" data-installments-editor>
            <div class="grid gap-3">
                <p class="form-help">{{ __('funding_agreements.installments_help') }}</p>

                @error('installments')
                    <p class="form-error"><flux:icon.exclamation-circle variant="micro" class="size-3.5 shrink-0" />{{ $message }}</p>
                @enderror

                @forelse ($installments as $index => $installment)
                    @php
                        $amountError = $errors->first("installments.{$index}.amount");
                        $dateError = $errors->first("installments.{$index}.scheduled_date");
                    @endphp
                    <div wire:key="installment-row-{{ $index }}" class="grid grid-cols-[1.75rem_minmax(0,1fr)_auto] items-start gap-2" data-installment-row>
                        <span class="pt-2.5 text-sm font-semibold tabular-nums text-secondary-medium dark:text-ink-muted" dir="ltr">{{ $index + 1 }}</span>

                        <div class="grid min-w-0 gap-2 @lg/modal:grid-cols-2">
                            <div class="min-w-0">
                                <div class="form-box" @if ($amountError !== '') data-invalid @endif>
                                    <input type="number" step="0.01" min="0" dir="ltr" class="form-box-input tabular-nums"
                                        wire:model.live.debounce.400ms="installments.{{ $index }}.amount"
                                        aria-label="{{ __('funding_agreements.installment_amount_label', ['number' => $index + 1]) }}"
                                        @if ($amountError !== '') aria-invalid="true" @endif />
                                    <span class="form-affix">{{ $currencyCode }}</span>
                                </div>
                                @if ($amountError !== '')
                                    <p class="form-error">{{ $amountError }}</p>
                                @endif
                            </div>

                            <div class="min-w-0">
                                <div class="form-box" @if ($dateError !== '') data-invalid @endif>
                                    <input type="date" dir="ltr" class="form-box-input tabular-nums"
                                        wire:model="installments.{{ $index }}.scheduled_date"
                                        aria-label="{{ __('funding_agreements.installment_date_label', ['number' => $index + 1]) }}"
                                        @if ($dateError !== '') aria-invalid="true" @endif />
                                </div>
                                @if ($dateError !== '')
                                    <p class="form-error">{{ $dateError }}</p>
                                @endif
                            </div>

                        </div>

                        <x-ui.button type="button" tone="ghost" icon="trash" class="min-h-10" wire:click="removeInstallment({{ $index }})"
                            wire:loading.attr="disabled" wire:target="removeInstallment({{ $index }})"
                            aria-label="{{ __('funding_agreements.remove_installment', ['number' => $index + 1]) }}" />
                    </div>
                @empty
                    <p class="text-sm text-secondary-medium dark:text-ink-muted" data-installments-empty>{{ __('funding_agreements.no_installments_yet') }}</p>
                @endforelse

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <x-ui.button type="button" size="sm" icon="plus" wire:click="addInstallment" wire:loading.attr="disabled" wire:target="addInstallment" data-test="add-installment">
                        {{ __('funding_agreements.add_installment') }}
                    </x-ui.button>

                    @if ($installments !== [])
                        <p class="text-sm tabular-nums" data-installments-total>
                            {{ __('funding_agreements.installments_total') }}
                            <x-ui.money :amount="$installmentsTotal" :currency="$currencyCode" />
                        </p>
                    @endif
                </div>

                @if ($installmentsDiffer)
                    <div class="flex items-start gap-2.5 rounded-lg border border-status-warn/40 bg-status-warn/10 p-3 text-sm text-secondary-dark dark:text-ink" role="alert" data-installments-mismatch>
                        <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0 text-status-warn" />
                        <div class="grid gap-2">
                            <p>{{ __('funding_agreements.installments_sum_mismatch', ['sum' => number_format((float) $installmentsTotal, 2)]) }}</p>
                            <label class="flex items-center gap-2 font-medium">
                                <input type="checkbox" wire:model.live="confirmInstallmentSum" class="size-4" data-test="confirm-installment-sum" />
                                {{ __('funding_agreements.save_anyway') }}
                            </label>
                        </div>
                    </div>
                @endif
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
