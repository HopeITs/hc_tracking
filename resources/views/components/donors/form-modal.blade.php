{{--
    The donor profile form, rendered by every page that uses App\Livewire\Concerns\ManagesDonorForm.
    Its wire:model bindings resolve against that page's component.
--}}
@props([
    'editing' => false,
    'typeOptions' => [],
])

<flux:modal wire:model="showDonorModal" wire:close="closeDonorModal" class="w-full max-w-lg border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
    <form wire:submit="saveDonor" class="space-y-5">
        <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
            <flux:heading size="lg">{{ $editing ? __('donors.edit_donor') : __('donors.add_donor') }}</flux:heading>
            <flux:subheading>{{ __('donors.donor_names_need_not_be_unique_two_country_offices_may_share') }}</flux:subheading>
        </div>

        <x-ui.input wire:model="name" :label="__('donors.donor_name')" type="text" maxlength="255" required autofocus />

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select wire:model="donorType" :label="__('common.type')">
                <flux:select.option value="">{{ __('donors.not_classified') }}</flux:select.option>
                @foreach ($typeOptions as $typeOption)
                    <flux:select.option :value="$typeOption->value" :wire:key="'donor-type-'.$typeOption->value">
                        {{ $typeOption->label() }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <x-ui.input wire:model="contactPerson" :label="__('donors.contact_person')" type="text" maxlength="255" />
            <x-ui.input wire:model="email" :label="__('common.email')" type="email" maxlength="255" />
            <x-ui.input wire:model="phone" :label="__('donors.phone')" type="tel" maxlength="50" dir="ltr" />
            <x-ui.input wire:model="country" :label="__('donors.country')" type="text" maxlength="100" class="sm:col-span-2" />
        </div>

        <flux:textarea wire:model="notes" :label="__('common.notes')" rows="3" maxlength="2000" />

        <fieldset class="space-y-4 border-t border-secondary-soft pt-4 dark:border-secondary-medium" data-follow-up-fields>
            <legend class="type-label pb-3 text-secondary-medium dark:text-secondary-soft">{{ __('donors.follow_up') }}</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="lastContactedOn" :label="__('donors.last_contacted_on')" type="date" :max="now()->toDateString()" />
                <x-ui.input wire:model="nextStepDueOn" :label="__('donors.next_step_due_on')" type="date" />
                <x-ui.input wire:model="nextStep" :label="__('donors.next_step')" :placeholder="__('donors.next_step_placeholder')" type="text" maxlength="255" class="sm:col-span-2" />
            </div>
        </fieldset>

        <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
            <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeDonorModal">{{ __('common.cancel') }}</x-ui.button>
            <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-donor-button">
                {{ $editing ? __('common.save_changes') : __('donors.create_donor') }}
            </x-ui.button>
        </div>
    </form>
</flux:modal>
