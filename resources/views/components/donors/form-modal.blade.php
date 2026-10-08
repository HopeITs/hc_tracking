{{--
    The donor profile form, rendered by every page that uses App\Livewire\Concerns\ManagesDonorForm.
    Its wire:model bindings resolve against that page's component.
--}}
@props([
    'open' => true,
    'editing' => false,
    'typeOptions' => [],
])

<x-ui.modal
    wire:model="showDonorModal"
    size="xl"
    :title="$editing ? __('donors.edit_donor') : __('donors.add_donor')"
    :description="__('donors.donor_names_need_not_be_unique_two_country_offices_may_share')"
    submit="saveDonor"
    close="closeDonorModal"
    required-note
>
    @if ($open)
        <x-ui.form-section :title="__('common.section_details')" :fields="['name', 'donorType', 'notes']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-3">
                <x-ui.form-input wire:model="name" :label="__('donors.donor_name')" maxlength="255" required autofocus class="@2xl/modal:col-span-2" />

                <x-ui.form-select wire:model="donorType" :label="__('common.type')">
                    <option value="">{{ __('donors.not_classified') }}</option>
                    @foreach ($typeOptions as $typeOption)
                        <option value="{{ $typeOption->value }}">{{ $typeOption->label() }}</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-textarea wire:model="notes" :label="__('common.notes')" rows="2" maxlength="2000" class="@2xl/modal:col-span-3" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('donors.section_contact')" :fields="['contactPerson', 'email', 'phone', 'country']">
            <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                <x-ui.form-input wire:model="contactPerson" :label="__('donors.contact_person')" maxlength="255" />
                <x-ui.form-input wire:model="email" :label="__('common.email')" type="email" maxlength="255" />
                <x-ui.form-input wire:model="phone" :label="__('donors.phone')" type="tel" maxlength="50" dir="ltr" />
                <x-ui.form-input wire:model="country" :label="__('donors.country')" maxlength="100" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section :title="__('donors.follow_up')" :fields="['lastContactedOn', 'nextStepDueOn', 'nextStep']" data-follow-up-fields>
            <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-3">
                <x-ui.form-input wire:model="lastContactedOn" :label="__('donors.last_contacted_on')" type="date" :max="now()->toDateString()" />
                <x-ui.form-input wire:model="nextStepDueOn" :label="__('donors.next_step_due_on')" type="date" />
                <x-ui.form-input wire:model="nextStep" :label="__('donors.next_step')" :placeholder="__('donors.next_step_placeholder')" maxlength="255" class="col-span-2 @4xl/modal:col-span-1" />
            </div>
        </x-ui.form-section>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="save-donor-button">
            {{ $editing ? __('common.save_changes') : __('donors.create_donor') }}
        </x-ui.button>
    </x-slot:actions>
</x-ui.modal>
