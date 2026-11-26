---
paths:
  - '{app/Livewire/Concerns/ManagesReportForm.php,resources/views/pages/reports/**,resources/views/components/reports/**}'
---

# Reports

## One report form for list and detail; submit_date belongs to the workflow
Both reports pages use the ManagesReportForm trait and x-reports.form-modal, so record/edit/delete rules live in one place. The form never writes status or submit_date: submitForReview stamps it and returnToDraft clears it, so they cannot disagree. Only drafts can be deleted (user decision); the delete also removes stored files from disk, since the DB cascade does not. planned_date must fall inside the annual plan's calendar year or the proposal's period (actual dates when known, else planned); percentage indicators cap values at 100. Deep links: reports.index ?create=1&target=ID and ?edit=ID; targets.index ?indicator=/?result= (+create=1); indicators.index ?create=RESULT_ID and ?inspect=ID.

## The register is three status buttons and one simple row (scenario rebuild 2026-11-06)
reports/index leads with three buttons — Needs attention, Drafts in progress, Approved — that call `setFocus()` (the `focus` URL property). They are disjoint and exhaustive: a **late draft** (draft past its planned date) or a report **waiting for approval** (status `reviewed`) is attention, any other draft is in progress, and the rest is approved. `focusCounts()` reads every filter except the button itself, so a click shows exactly that many, and the page count badge is its total. The four big figures, the status strip, the overdue chip and the MEAL toggle are gone from the page; `insights()` (the capped average achievement) was removed with the Portfolio code, and the headline shows no average.

The list sorts late drafts → waiting → other drafts → approved (SQL `case`, so paging is unaffected), then by planned date. A row shows the indicator, project · plan or proposal · planned date, the author, one status word (Late draft / Draft / Waiting for approval / Approved), the figure against its target with the target's own approved-only achievement, **Open report** and a ⋯ menu; edit and delete appear only for an editable draft, otherwise a disabled item says why. Project and search stay visible; Reported by and MEAL review are behind **More filters**.

The Board, Calendar and Portfolio views are retired (M-02, 2026-11-24): `?view=board|calendar|portfolio` redirects to the Report Tracker (`active-reports.index`, `pages/active-reports/⚡index`), which lists every open monthly, donor and existing report in one table (tabs Needs me / All / Done). The portfolio code (`ReadsReportPortfolio`, `insights()`, `portfolio()`) and its PHPStan host were deleted with the user's approval on 2026-11-26. The Tracker's sources share no status, so add a new type there, never a shared status column.

Reports read their place through the target's location row (Y-16): the list search matches the place's proposal name or HR program name (`HrProgram::idsMatchingName`), the strategy filter uses `whereLocationInStrategy`, and the detail page names the place with `ProposalLocation::label()` and links `projects.show` with the row. `plan_indicators.project_id` is no longer read by the report pages. The legacy `status`, `seenByMeal` and `overdue` properties still filter server-side. `ReportHeadlineTest` parses the rendered HTML to keep the buttons and rows inside the Livewire root.
