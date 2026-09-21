---
paths:
  - '{app/Livewire/Concerns/ManagesReportForm.php,resources/views/pages/reports/**,resources/views/components/reports/**}'
---

# Reports

## One report form for list and detail; submit_date belongs to the workflow
Both reports pages use the ManagesReportForm trait and x-reports.form-modal, so record/edit/delete rules live in one place. The form never writes status or submit_date: submitForReview stamps it and returnToDraft clears it, so they cannot disagree. Only drafts can be deleted (user decision); the delete also removes stored files from disk, since the DB cascade does not. planned_date must fall inside the annual plan's calendar year or the proposal's period (actual dates when known, else planned); percentage indicators cap values at 100. Deep links: reports.index ?create=1&target=ID and ?edit=ID; targets.index ?indicator=/?result= (+create=1); indicators.index ?create=RESULT_ID and ?inspect=ID.
