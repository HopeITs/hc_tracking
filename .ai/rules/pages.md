---
paths:
  - 'resources/views/pages/**'
---

# Pages

## Use the shared Programs-style page header
Every authenticated page directory/index header must use x-ui.page-header. Keep the title bold at the shared component scale, omit descriptive subtitle copy, show a live count badge when the page has a meaningful visible-record count, and place page search/filter/action controls in the component slots so responsive layout stays consistent.

## Bind flux:modal with wire:model, never :open
A `<flux:modal :open="$someId !== null">` never opens from a Livewire round trip. The expression is evaluated when the dialog first renders; a later patch flips the attribute but nothing calls showModal() on the `<dialog>`, so the component state changes and the user sees nothing. Confirmed in the browser on annual-plans and risks.

Bind the modal to a boolean with `wire:model` instead, and set that flag in the same action that sets the id: annual-plans/index carries `deletingPlanId` for the record plus `showDeleteModal` for the dialog. A Livewire test asserting the id alone passes while the UI is dead, so assert the boolean too.

Still outstanding on the delete confirmations in admin/accounts, donors, indicators (both modals), milestones, proposals, results, risks, targets and tasks.
