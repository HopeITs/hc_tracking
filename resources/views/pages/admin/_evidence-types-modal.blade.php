{{--
    The create/edit dialog of admin/evidence-types. Included by
    ⚡evidence-types.blade.php. Only the two names are typed; position and the
    active flag have their own controls on the list.
--}}
<x-ui.modal
    wire:model="showEvidenceTypeModal"
    size="sm"
    :title="$editingEvidenceTypeId === null ? __('evidence_types.add_evidence_type') : __('evidence_types.edit_evidence_type')"
    :description="__('evidence_types.dialog_description')"
    submit="saveEvidenceType"
    close="closeEvidenceTypeModal"
    required-note
>
    @if ($showEvidenceTypeModal)
        <div class="grid gap-y-3">
            <x-ui.form-input wire:model="nameAr" :label="__('evidence_types.fields.name_ar')" required maxlength="255" dir="rtl" lang="ar" data-test="evidence-type-name-ar" />
            <x-ui.form-input wire:model="nameEn" :label="__('evidence_types.fields.name_en')" :help="__('evidence_types.name_en_help')" maxlength="255" dir="ltr" lang="en" data-test="evidence-type-name-en" />
        </div>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="save-evidence-type-button">
            {{ __('common.save_changes') }}
        </x-ui.button>
    </x-slot:actions>
</x-ui.modal>
