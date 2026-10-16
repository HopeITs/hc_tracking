{{--
    The deactivate and delete confirmations of admin/narrative-questions.
    Included by ⚡narrative-questions.blade.php. A used question's delete
    dialog explains why it cannot be deleted and offers to deactivate it
    instead.
--}}
<x-ui.confirm-modal
    wire:model="showDeactivateModal"
    close="cancelDeactivate"
    tone="neutral"
    :title="__('narrative_questions.deactivate_title')"
    :message="__('narrative_questions.deactivate_message')"
    confirm="deactivateQuestion"
    :confirm-label="__('narrative_questions.deactivate')"
    confirm-test="confirm-deactivate-narrative-question"
/>

{{-- One dialog whose text follows $deletingQuestionInUse: two dialogs swapped behind an @if would replace the open <dialog> element mid-request. --}}
<x-ui.confirm-modal
    wire:model="showDeleteModal"
    close="cancelDelete"
    :tone="$deletingQuestionInUse ? 'neutral' : 'danger'"
    :title="__('narrative_questions.delete_title')"
    :message="$deletingQuestionInUse ? __('narrative_questions.in_use') : __('narrative_questions.delete_message')"
    :confirm="$deletingQuestionInUse ? 'deactivateInsteadOfDelete' : 'deleteQuestion'"
    :confirm-label="$deletingQuestionInUse ? __('narrative_questions.deactivate_instead') : __('narrative_questions.delete')"
    :confirm-test="$deletingQuestionInUse ? 'confirm-deactivate-instead' : 'confirm-delete-narrative-question'"
/>
