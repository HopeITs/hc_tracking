{{--
    The deactivate and delete confirmations of admin/sectors. Included by
    ⚡sectors.blade.php. A used sector's delete dialog explains why it cannot
    be deleted and offers to deactivate it instead.
--}}
<x-ui.confirm-modal
    wire:model="showDeactivateModal"
    close="cancelDeactivate"
    tone="neutral"
    :title="__('sectors.deactivate_title')"
    :message="__('sectors.deactivate_message')"
    confirm="deactivateSector"
    :confirm-label="__('sectors.deactivate')"
    confirm-test="confirm-deactivate-sector"
/>

{{-- One dialog whose text follows $deletingSectorInUse: two dialogs swapped behind an @if would replace the open <dialog> element mid-request. --}}
<x-ui.confirm-modal
    wire:model="showDeleteModal"
    close="cancelDelete"
    :tone="$deletingSectorInUse ? 'neutral' : 'danger'"
    :title="__('sectors.delete_title')"
    :message="$deletingSectorInUse ? __('sectors.in_use') : __('sectors.delete_message')"
    :confirm="$deletingSectorInUse ? 'deactivateInsteadOfDelete' : 'deleteSector'"
    :confirm-label="$deletingSectorInUse ? __('sectors.deactivate_instead') : __('sectors.delete')"
    :confirm-test="$deletingSectorInUse ? 'confirm-deactivate-instead' : 'confirm-delete-sector'"
/>
