{{--
    The deactivate and delete confirmations of admin/risk-library. Included by
    ⚡risk-library.blade.php. A used entry's delete dialog explains why it
    cannot be deleted and offers to deactivate it instead.
--}}
<x-ui.confirm-modal
    wire:model="showDeactivateModal"
    close="cancelDeactivate"
    tone="neutral"
    :title="__('risk_library.deactivate_title')"
    :message="__('risk_library.deactivate_message')"
    confirm="deactivateEntry"
    :confirm-label="__('risk_library.deactivate')"
    confirm-test="confirm-deactivate-entry"
/>

{{-- One dialog whose text follows $deletingEntryInUse: two dialogs swapped behind an @if would replace the open <dialog> element mid-request. --}}
<x-ui.confirm-modal
    wire:model="showDeleteModal"
    close="cancelDelete"
    :tone="$deletingEntryInUse ? 'neutral' : 'danger'"
    :title="__('risk_library.delete_title')"
    :message="$deletingEntryInUse ? __('risk_library.in_use') : __('risk_library.delete_message')"
    :confirm="$deletingEntryInUse ? 'deactivateInsteadOfDelete' : 'deleteEntry'"
    :confirm-label="$deletingEntryInUse ? __('risk_library.deactivate_instead') : __('risk_library.delete')"
    :confirm-test="$deletingEntryInUse ? 'confirm-deactivate-instead' : 'confirm-delete-entry'"
/>
