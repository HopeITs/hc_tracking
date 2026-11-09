---
paths:
  - '{app/Livewire/Concerns/ManagesReportForm.php,resources/views/pages/reports/**,resources/views/components/reports/**}'
---

# Reports

## One report form for list and detail; submit_date belongs to the workflow
Both reports pages use the ManagesReportForm trait and x-reports.form-modal, so record/edit/delete rules live in one place. The form never writes status or submit_date: submitForReview stamps it and returnToDraft clears it, so they cannot disagree. Only drafts can be deleted (user decision); the delete also removes stored files from disk, since the DB cascade does not. planned_date must fall inside the annual plan's calendar year or the proposal's period (actual dates when known, else planned); percentage indicators cap values at 100. Deep links: reports.index ?create=1&target=ID and ?edit=ID; targets.index ?indicator=/?result= (+create=1); indicators.index ?create=RESULT_ID and ?inspect=ID.

## The register is three status buttons and one simple row (scenario rebuild 2026-11-06)
reports/index leads with three buttons — Needs attention, Drafts in progress, Approved — that call `setFocus()` (the `focus` URL property). They are disjoint and exhaustive: a **late draft** (draft past its planned date) or a report **waiting for approval** (status `reviewed`) is attention, any other draft is in progress, and the rest is approved. `focusCounts()` reads every filter except the button itself, so a click shows exactly that many, and the page count badge is its total. The four big figures, the status strip, the overdue chip and the MEAL toggle are gone from the page; `insights()` (including the capped average achievement) still exists for the Portfolio view and its test, but the headline no longer shows an average.

The list sorts late drafts → waiting → other drafts → approved (SQL `case`, so paging is unaffected), then by planned date. A row shows the indicator, project · plan or proposal · planned date, the author, one status word (Late draft / Draft / Waiting for approval / Approved), the figure against its target with the target's own approved-only achievement, **Open report** and a ⋯ menu; edit and delete appear only for an editable draft, otherwise a disabled item says why. Project and search stay visible; Reported by and MEAL review are behind **More filters**.

The Table / Board / Calendar / Portfolio switch is unchanged: replacing a register's view switch needs the owner's decision. The legacy `status`, `seenByMeal` and `overdue` properties still filter server-side. `ReportHeadlineTest` parses the rendered HTML to keep the buttons and rows inside the Livewire root.

## Monthly Matrix: three status buttons, a month is late only after it ends (scenario review 2026-11-09)
reports/monthly-matrix leads with three disjoint buttons — Report not sent, Waiting for approval, Up to date — that call `setFocus()` (the `focus` URL property). Every row belongs to exactly one (`rowFocus()`: any ended month without a report wins, then any submitted month, else up to date); `matrix()['counts']` totals the unfiltered rows, and the focus filters the rows themselves, so the list, the count badge and the CSV agree. A month that has not ended is "Not due yet", never "Not sent", while its report is missing or still a draft. Proposal, center, year and My projects stay visible; donor, city, indicator and pillar sit behind **More filters** (open by default when one is active). The table has one name cell (the viewer's language first), one "Center and funding" column and the measure under the target; the achieved cell shows "370 of 600". The By center / By indicator switch is unchanged (replacing it needs the owner's decision). MonthlyMatrixTest keeps the buttons and More filters inside the Livewire root.
