# P7 · Annual plans matrix

**Route:** `yearly-plans.index` · **Group:** C · Plans (same owner as P8) · **Wave:** 1 · **Depends on:** 02

## Files you own

- `resources/views/pages/yearly-plans/⚡index.blade.php`
- `lang/*/yearly_plans.php`
- `tests/Feature/YearlyPlanMatrixTest.php`

## Binding rules (`.ai/rules/yearly-plans.md`): do not break

- The matrix is filled by **exactly 4 queries** against `yearly_plans` (year options, manager options, one `whereIn` lookup keyed `programId:year`, one grouped column total). Cells use `planFor()`. **No query in the cell loop.** `YearlyPlanMatrixTest` asserts the count.
- Max `MAX_YEAR_COLUMNS = 10`; explicit From anchors at start, otherwise anchors at end (most recent years); reversed ranges are reordered.

## Current state

Header + total programs badge; filters yearFrom / yearTo / manager / reset; sticky-first-column `flux:table` (rows = programs with strategy name, columns = years with per-year totals; cell = manager initials + `plan_indicators_count`, or "No plan"); mobile = stacked list; 15 programs/page; "narrowed" notice when capped.

## Target layout

1. **Header:** unchanged filters; `summary` slot: "{covered} of {cells} program-years planned" + `x-ui.progress-bar` of that share (derive from the existing lookup array, no new query).
2. **Heatmap matrix** (keeps the same data):
   - Sticky first column: program name (link `programs.show`) + strategy meta.
   - Year header cells: year + total plans for that year (existing grouped count) as a tiny `x-ui.progress-bar` (count ÷ programs on page).
   - Body cells → `x-ui.heat-cell` with `value = plan_indicators_count`, `max = max count in the lookup`, `href = yearly-plans.show`, content = manager initials avatar (`size xs`) + count. Missing plan → empty dashed heat-cell with aria-label "No plan for {program} in {year}".
   - Legend under the matrix: the five heat steps with ranges ("0", "1–{25%}", …) computed from `max`.
   - Horizontal scroll inside the panel only; first column + header sticky.
3. **Mobile (< `@3xl`):** **keep a matrix**, not a list: sticky program column (truncate to 9rem) + horizontally scrollable year cells (`scroll-snap-type: x`), scrolled to the latest year on load (`x-init="$el.scrollLeft = ..."`, logical: in RTL scroll to start).
4. Narrowed notice unchanged, styled as a `warn` inline note.

## Data

- **Do not add queries** to the 4 above. `max` for heat is computed in PHP from the lookup. Manager initials come from the already-eager-loaded manager on the lookup query (confirm it's in that query's `with`; if it isn't, add it to that same query as an eager load, and update the test's expected count only if Laravel executes it as a separate query, stating why in the review).

## Tests (`YearlyPlanMatrixTest.php`)

- Query-count test unchanged and green.
- Heat-cell step classes: cell with max count gets the top step; empty cell has "No plan" aria-label.
- Legend renders ranges.

## Acceptance

- [ ] 4-query invariant holds.
- [ ] Mobile shows a scrollable matrix.
- [ ] Conventions §10 checklist.
