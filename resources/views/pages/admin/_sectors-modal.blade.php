{{--
    The create/edit dialog of admin/sectors. Included by ⚡sectors.blade.php.
    Only the two names are typed; position and the active flag have their own
    controls on the list.
--}}
<x-ui.modal
    wire:model="showSectorModal"
    size="sm"
    :title="$editingSectorId === null ? __('sectors.add_sector') : __('sectors.edit_sector')"
    :description="__('sectors.dialog_description')"
    submit="saveSector"
    close="closeSectorModal"
    required-note
>
    @if ($showSectorModal)
        <div class="grid gap-y-3">
            <x-ui.form-input wire:model="nameAr" :label="__('sectors.fields.name_ar')" required maxlength="255" dir="rtl" lang="ar" data-test="sector-name-ar" />
            <x-ui.form-input wire:model="nameEn" :label="__('sectors.fields.name_en')" :help="__('sectors.name_en_help')" maxlength="255" dir="ltr" lang="en" data-test="sector-name-en" />
        </div>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="save-sector-button">
            {{ __('common.save_changes') }}
        </x-ui.button>
    </x-slot:actions>
</x-ui.modal>
