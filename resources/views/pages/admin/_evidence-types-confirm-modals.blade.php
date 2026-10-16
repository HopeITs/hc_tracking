{{--
    The deactivate and delete confirmations of admin/evidence-types. Included
    by ⚡evidence-types.blade.php. A used type's delete dialog explains why it
    cannot be deleted and offers to deactivate it instead.
--}}
<x-ui.confirm-modal
    wire:model="showDeactivateModal"
    close="cancelDeactivate"
    tone="neutral"
    :title="__('evidence_types.deactivate_title')"
    :message="__('evidence_types.deactivate_message')"
    confirm="deactivateEvidenceType"
    :confirm-label="__('evidence_types.deactivate')"
    confirm-test="confirm-deactivate-evidence-type"
/>

{{-- One dialog whose text follows $deletingEvidenceTypeInUse: two dialogs swapped behind an @if would replace the open <dialog> element mid-request. --}}
<x-ui.confirm-modal
    wire:model="showDeleteModal"
    close="cancelDelete"
    :tone="$deletingEvidenceTypeInUse ? 'neutral' : 'danger'"
    :title="__('evidence_types.delete_title')"
    :message="$deletingEvidenceTypeInUse ? __('evidence_types.in_use') : __('evidence_types.delete_message')"
    :confirm="$deletingEvidenceTypeInUse ? 'deactivateInsteadOfDelete' : 'deleteEvidenceType'"
    :confirm-label="$deletingEvidenceTypeInUse ? __('evidence_types.deactivate_instead') : __('evidence_types.delete')"
    :confirm-test="$deletingEvidenceTypeInUse ? 'confirm-deactivate-instead' : 'confirm-delete-evidence-type'"
/>
