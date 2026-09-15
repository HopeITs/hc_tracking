# P19 · Report detail

**Route:** `reports.show` · **Group:** G · Reports · **Wave:** 1 · **Depends on:** 02 (`reference-list`, `progress-ring`)

## Files you own

- `resources/views/pages/reports/⚡show.blade.php`
- `lang/*/reports.php` (shared with P18)
- `tests/Feature/ReportDetailTest.php`

## Decision

Supporting files are **relabelled as referenced documents** (`x-ui.reference-list`), no downloads.

## Current state

Detail header (breadcrumbs Reports → indicator, result statement subtitle, meta period/project/source, author chip); 4 stat tiles (Reported, Target, Achievement %, Period); Workflow panel (status, submitted/seen/locked badges, stage buttons: submit for review → approve → return to draft, mark seen by MEAL); narrative window panel; one stacked panel per non-null narrative field; supporting files list.

## Target layout

1. **Detail header:** `status` prop; `summary` slot:
   - `x-ui.progress-ring size="lg"` achievement (value ÷ target) with "value of target unit" caption.
   - **Workflow stepper**: Draft → Reviewed → Approved as three connected steps (current filled, completed checked, future outline) + MEAL "Seen" marker. The **existing** stage action buttons sit under the stepper, same method names, same authorization and visibility rules.
2. **Tabs** (`x-ui.relation-tabs`, `#[Url] tab`): 
   - **Overview:** executive summary (if any) + key figures (reported value, target, baseline from `planIndicator.indicator.baseline`, period, narrative window) + indicator context (result statement, indicator link).
   - **Narrative:** key achievements, challenges, lessons learned, next period plans, notes: each a section with heading, max 70ch, line breaks preserved (escaped). Hidden sections for null fields; if all null, empty state.
   - **Documents:** `x-ui.reference-list` of report files.
   - Tab counts: Narrative = number of filled narrative fields; Documents = file count.

## Tests (`ReportDetailTest.php`)

- Existing workflow tests green (transitions, lock, MEAL seen).
- Add: stepper marks current status; narrative tab hides null fields; documents tab renders no links.

## Acceptance

- [ ] Conventions §10 checklist.
