---
paths:
  - '{app/Livewire/Concerns/ManagesDonorForm.php,resources/views/components/donors/**,resources/views/pages/donors/**}'
---

# Donors

## Donor form is a shared trait; the show page pins it to its donor
ManagesDonorForm + x-donors.form-modal are used by donors/index and donors/show. donors/show overrides donorFormScope() so a tampered editingDonorId cannot edit another donor and openCreateModal is a no-op there. Follow-up columns (last_contacted_on, next_step, next_step_due_on; migration 2026_09_22_101736) live on donors; next_step requires a due date and last_contacted_on cannot be in the future. transfers_tracking.scheduled_date is nullable — any list that formats it must filter whereNotNull first. Never re-inline the donor form on a page.
