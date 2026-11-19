{{--
    The reviewer dialogs of proposals/show's own page (remove a reviewer, add
    the need assessment). Rendered outside the folded sections so a "Needs
    attention" fix button can open them while History is folded. The
    embedded view keeps them inside _show-reviewers. Included by _show-more.
--}}
<x-ui.confirm-modal
    icon="user-minus"
    wire:model="showRemoveReviewerModal"
    close="closeRemoveReviewerModal"
    :title="__('proposals.remove_reviewer')"
    :message="__('proposals.remove_reviewer_consequence')"
    confirm="removeReviewer"
    :confirm-label="__('proposals.remove_reviewer')"
    confirm-test="confirm-remove-reviewer"
>
    @error('removeReviewer')
        <p class="rounded-lg border border-primary bg-secondary-soft/60 p-3 text-sm text-status-critical" role="alert">{{ $message }}</p>
    @enderror
</x-ui.confirm-modal>

<x-ui.modal
    wire:model="showNeedAssessmentModal"
    size="md"
    :title="__('proposals.add_need_assessment')"
    :description="__('reports.allowed_file_types_and_size', ['types' => \Illuminate\Support\Str::upper(implode(', ', \App\Enums\FileType::values())), 'size' => '5 MB'])"
    submit="saveNeedAssessment"
    close="closeNeedAssessmentModal"
    required-note
>
    @if ($showNeedAssessmentModal)
        <x-ui.form-file
            wire:model="newFile"
            :label="__('proposals.choose_a_file')"
            :accept="collect(\App\Enums\FileType::values())->map(fn ($extension) => '.'.$extension)->implode(',')"
            required
        />
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="newFile,saveNeedAssessment" data-test="save-need-assessment">{{ __('proposals.upload_file') }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>
