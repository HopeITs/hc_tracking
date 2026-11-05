{{--
    The create/edit dialog of admin/risk-library. Included by
    ⚡risk-library.blade.php. Position and the active flag have their own
    controls on the list.
--}}
<x-ui.modal
    wire:model="showEntryModal"
    size="sm"
    :title="$editingEntryId === null ? __('risk_library.add_entry') : __('risk_library.edit_entry')"
    :description="__('risk_library.dialog_description')"
    submit="saveEntry"
    close="closeEntryModal"
    required-note
>
    @if ($showEntryModal)
        <div class="grid gap-y-3">
            <x-ui.form-input wire:model="titleAr" :label="__('risk_library.fields.title_ar')" required maxlength="255" dir="rtl" lang="ar" data-test="risk-library-title-ar" />
            <x-ui.form-input wire:model="titleEn" :label="__('risk_library.fields.title_en')" required maxlength="255" dir="ltr" lang="en" data-test="risk-library-title-en" />
            <x-ui.form-input wire:model="category" :label="__('risk_library.fields.category')" :help="__('risk_library.category_help')" maxlength="40" data-test="risk-library-category-input" />
            @foreach (['defaultLikelihood' => 'risks.likelihood', 'defaultSeverity' => 'risks.severity'] as $ratingField => $ratingLabel)
                <x-ui.form-segmented
                    wire:model="{{ $ratingField }}"
                    :label="__($ratingLabel)"
                    :options="[
                        ['value' => '', 'label' => __('risks.not_rated')],
                        ['value' => '1', 'label' => __('risks.rating_low'), 'test' => 'risk-library-'.$ratingField.'-1'],
                        ['value' => '2', 'label' => __('risks.rating_medium'), 'test' => 'risk-library-'.$ratingField.'-2'],
                        ['value' => '3', 'label' => __('risks.rating_high'), 'test' => 'risk-library-'.$ratingField.'-3'],
                    ]"
                />
            @endforeach
        </div>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="save-risk-library-entry-button">
            {{ __('common.save_changes') }}
        </x-ui.button>
    </x-slot:actions>
</x-ui.modal>
