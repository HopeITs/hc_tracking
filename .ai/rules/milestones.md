---
paths:
  - 'resources/views/pages/milestones/**'
---

# Milestones

## Milestones delete confirmation fixed — remove from pages.md outstanding list
Fixed the milestones delete modal's `:open="$deletingMilestoneId !== null"` bug (see the general note in pages.md about flux:modal + wire:model). Now uses `showDeleteModal` bound with `wire:model`, a `cancelDelete()` method, and a `deletingMilestone` computed property whose name is shown in the confirmation text (`milestones.are_you_sure_you_want_to_delete_this_milestone`). Tests assert `showDeleteModal` directly, not just the id. Milestones can be removed from the "still outstanding" list in pages.md; admin/accounts, donors, indicators (both modals), proposals, risks, targets and tasks still have the bug.
