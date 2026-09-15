# P16 · Indicators index

**Route:** `indicators.index` · **Group:** F · MEAL · **Wave:** 1 · **Depends on:** 02 (`sparkline`, `rag-chip`)

## Files you own

- `resources/views/pages/indicators/⚡index.blade.php`
- `lang/*/indicators.php`
- `tests/Feature/IndicatorDirectoryTest.php`

## Current state

Already the most visual page. Filters: search (name/result statement), result, measure, reset. Row: name, result statement (truncated), program, project (optional), `percentAchieved()`, `ragStatus()` badge, inline raw SVG sparkline of progress values, frequency badge, measure badge, `plan_indicators_count`, "Log reading" button (modal: date, value, source_of_verification, notes → `indicator_progress`), edit, delete (locked with targets). Create/edit modal: name, result, measure, frequency, project, unit, baseline, lifetime target.

## Target layout

**Polish, don't rebuild.**

1. **Header:** keep filters + "Add indicator"; add a RAG filter (`#[Url] rag`: red | amber | green | none). Because RAG is computed, filter in PHP **after** pagination is not correct: compute RAG for the filtered set with the already-loaded progress, then paginate the collection with `LengthAwarePaginator` (acceptable up to a few hundred indicators; note the limit in review).
2. **Summary slot:** `x-ui.status-strip` of RAG counts (critical/warn/good + neutral "No data"), `wire-model="rag"`.
3. **Grouped by result** (panel per `result.statement`, with `enum-badge` of result type), rows:
   - Name + meta `program · project` (project optional)
   - `x-ui.sparkline` of `indicator_progress.value` ordered by `date` (use the replaced inline SVG's data source)
   - Achieved: `x-ui.progress-bar :value="percentAchieved()"` + value text with `unit`; baseline → target line in `type-meta` ("{baseline} → {target} {unit}")
   - `x-ui.rag-chip`
   - Frequency + measure badges collapsed into one meta line ("Quarterly · Percentage")
   - Targets count
   - Actions: "Record reading" (primary ghost), edit, delete/locked (keep method names)
4. **Narrow containers (< `@4xl`)**: row shows name, RAG chip, progress bar; the rest moves into an expandable detail (`<details>`) instead of wrapping.

## Tests (`IndicatorDirectoryTest.php`)

- Existing tests green (including log-reading modal).
- Add: RAG strip counts; `rag=red` filter; grouping by result; sparkline replaced by component (aria-label present).

## Acceptance

- [ ] No inline hand-written SVG left in the page (uses `x-ui.sparkline`).
- [ ] Conventions §10 checklist.
