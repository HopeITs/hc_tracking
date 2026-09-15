# P18 · Reports index

**Route:** `reports.index` · **Group:** G · Reports (same owner as P19) · **Wave:** 1 · **Depends on:** 02

## Files you own

- `resources/views/pages/reports/⚡index.blade.php`
- `lang/*/reports.php`
- `tests/Feature/ReportDirectoryTest.php`

## Current state

Filters: search, status (`ReportStatus`: draft, reviewed, approved), MEAL seen/unseen, author; "Record report" modal (target, planned date/value/submit date, narrative window start/end, six narrative textareas). Rows: indicator name link, planned date, project, file count, reported value vs target, status badge, MEAL badge, author chip, edit or lock icon (no delete by design). 15/page.

Fields: `reports.plan_indicator_id, user_id, status, planned_date, period_start, period_end, value, narrative_report_notes, executive_summary, key_achievements, challenges, lessons_learned, next_period_plans, submit_date, is_seen_by_meal`.

## Target layout

1. **Header:** keep filters + "Record report".
2. **Summary slot:** `x-ui.status-strip` over `ReportStatus` (`wire-model` = existing status property) + "{n} awaiting MEAL review" critical-tone button setting the existing seen filter to unseen.
3. **List** (`x-ui.data-table`):
   - Report: indicator name link (`reports.show`) + meta `project · period (period_start–period_end)`
   - Planned / submitted: `x-ui.schedule-status :planned="planned_date" :actual="submit_date" :done="submit_date !== null"`
   - Achievement: `x-ui.progress-ring size="sm"` value ÷ `planIndicator.target` × 100 (null target → em dash) + "value / target unit"
   - Status `enum-badge`; MEAL: "Seen" neutral chip / "Awaiting review" warn chip
   - Referenced documents count (icon `paper-clip`, plain number, not a link)
   - Author chip; edit or lock (unchanged rules)
4. **Modal:** same fields; reorganize into two steps inside the same modal with a `flux:tabs`-like segmented header "Figures" / "Narrative" (no new validation; submitting from either step validates all; errors switch to the step containing the first error).

## Tests (`ReportDirectoryTest.php`)

- Existing tests green (edit lock rules, filters).
- Add: status strip counts; awaiting-review button filter; achievement % computed; modal step switches to Narrative when a narrative field fails validation.

## Acceptance

- [ ] Conventions §10 checklist.
