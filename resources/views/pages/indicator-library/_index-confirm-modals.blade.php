{{--
    The deactivate and delete confirmations of indicator-library/index.
    Included by ⚡index.blade.php.
--}}
<x-ui.confirm-modal
    icon="no-symbol"
    wire:model="showDeactivateModal"
    close="cancelDeactivate"
    tone="neutral"
    :title="__('indicator_library.deactivate_title', ['name' => $deactivatingEntryName])"
    :message="__('indicator_library.deactivate_message')"
    confirm="deactivateEntry"
    :confirm-label="__('indicator_library.deactivate')"
    confirm-test="confirm-deactivate-library-entry"
/>

<x-ui.confirm-modal
    permanent
    wire:model="showDeleteModal"
    close="cancelDelete"
    :title="__('indicator_library.delete_title', ['name' => $deletingEntryName])"
    :message="__('indicator_library.delete_message')"
    confirm="deleteEntry"
    :confirm-label="__('indicator_library.delete')"
    confirm-test="confirm-delete-library-entry"
/>
