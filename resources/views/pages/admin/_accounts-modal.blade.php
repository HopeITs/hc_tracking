{{--
    The open/edit account modal of the accounts page. Included by
    ⚡accounts.blade.php; reads $this, $showAccountModal, $editingAccountId, $editingAccountIsActive,
    $editingAccountClosedAt and $editingAccountTransfersCount.
--}}
@use('App\Enums\Currency')

    <x-ui.modal
        wire:model="showAccountModal"
        size="xl"
        :title="$editingAccountId === null ? __('accounts.open_account') : __('accounts.edit_account')"
        :description="__('accounts.a_new_account_is_usable_immediately_to_stop_it_receiving_tra')"
        submit="saveAccount"
        close="closeAccountModal"
        required-note
    >
        @if ($showAccountModal)
        <x-ui.form-section :title="__('common.section_details')" :fields="['accountName', 'bankName', 'currency', 'authorizedPerson', 'openedAt']">
            @if ($editingAccountId !== null)
                <x-slot:hint>
                    <span class="flex flex-col gap-0.5">
                        <span @class([
                            'inline-flex w-fit items-center rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap',
                            'bg-status-good-soft text-status-good' => $editingAccountIsActive,
                            'bg-secondary-soft text-secondary-medium dark:bg-surface-2 dark:text-ink-muted' => ! $editingAccountIsActive,
                        ])>
                            {{ $editingAccountIsActive ? __('accounts.open') : __('accounts.closed') }}
                        </span>
                        @if ($editingAccountClosedAt !== null)
                            <span>{{ __('accounts.closed_date', ['date' => \Carbon\CarbonImmutable::parse($editingAccountClosedAt)->translatedFormat(__('formats.date'))]) }}</span>
                        @endif
                        <span>{{ trans_choice('accounts.count_transfer', $editingAccountTransfersCount, ['count' => $editingAccountTransfersCount]) }}</span>
                    </span>
                </x-slot:hint>
            @endif

            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-input wire:model="accountName" :label="__('accounts.account_name')" maxlength="255" required autofocus class="@2xl/modal:col-span-2" />
                <x-ui.form-input wire:model="bankName" :label="__('accounts.bank_name')" maxlength="255" required />

                <x-ui.form-segmented
                    wire:model="currency"
                    :label="__('common.currency')"
                    required
                    :options="collect(Currency::cases())->map(fn (Currency $currencyCase): array => ['value' => $currencyCase->value, 'label' => $currencyCase->value])->all()"
                />

                <x-ui.form-input wire:model="authorizedPerson" :label="__('accounts.authorized_person')" maxlength="255" />
                <x-ui.form-input wire:model="openedAt" :label="__('accounts.opened_on')" type="date" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('accounts.section_bank')" :fields="['accountNumber', 'iban', 'swiftCode', 'country']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-input wire:model="accountNumber" :label="__('accounts.account_number')" maxlength="100" required dir="ltr" />
                <x-ui.form-input wire:model="iban" :label="__('accounts.iban')" maxlength="100" dir="ltr" />
                <x-ui.form-input wire:model="swiftCode" :label="__('accounts.swift_code')" maxlength="50" dir="ltr" />
                <x-ui.form-input wire:model="country" :label="__('donors.country')" maxlength="100" required />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('accounts.section_address')" :fields="['poBoxStreet', 'companyAddress']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-textarea wire:model="poBoxStreet" :label="__('accounts.po_box_street')" rows="2" />
                <x-ui.form-textarea wire:model="companyAddress" :label="__('accounts.company_address')" rows="2" />
            </div>
        </x-ui.form-section>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-account-button">
                {{ $editingAccountId === null ? __('accounts.open_account') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
