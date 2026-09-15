# P15 · Results framework (logframe)

**Route:** `results.index` · **Group:** F · MEAL (same owner as P16–P17) · **Wave:** 1 · **Depends on:** 02 (`x-ui.tree`)

## Files you own

- `resources/views/pages/results/⚡index.blade.php`
- `lang/*/results_framework.php`
- `tests/Feature/ResultsFrameworkTest.php`

## Current state

Filters: program (incl. "Unassigned"), type; create/edit modal (statement, type, checkout_period, program, parent "contributes to"); delete blocked with children or indicators. Grouped by program (+ "Unassigned") panels, plain `x-ui.tree-rows` with statement + type badge. No indicator/target counts.

Fields: `results.statement, parent_id, program_id (nullable), type (impact | outcome | output), checkout_period`.

## Target layout

This is the core M&E artifact; it should look like a logframe.

1. **Header:** keep filters + "Add result"; add search (`#[Url] search` on `statement`, keeps ancestors of matches visible: filter in PHP after loading the program's tree).
2. **Summary slot:** three counts as a static `x-ui.status-strip`: Impact / Outcome / Output (neutral tones of increasing lightness), plus "{n} results without indicators" button (`#[Url] noIndicators`).
3. **Per program panel:** header = program name link + counts.
   - **Tier legend** row: Impact → Outcome → Output with the tier visual used in nodes.
   - `x-ui.tree` nodes:
     - Tier is encoded by **form**, not only a badge: Impact nodes `type-heading` weight 700 on a `secondary-soft` filled row; Outcome weight 600, plain row; Output weight 400, compact row. Badge still shown.
     - meta: indicators count (`chart-bar` icon) linking to `indicators.index?result={id}`, targets count through indicators (`withCount` on the recursive relation for `indicators`; targets via `withCount('indicators.planIndicators')` is not supported, so load `indicators_count` and a separate grouped query `plan_indicators join indicators group by result_id` keyed in PHP), `checkout_period` label.
     - Result with 0 indicators: warn meta "No indicators yet".
     - actions: edit/delete (keep existing method names; locked delete tooltip unchanged).
   - Keep `data-tree-depth` attributes.
4. **Wide screens (`@7xl`)**: add a "Matrix" `x-ui.view-toggle` option (`#[Url] view`: `tree` | `matrix`) that renders the same nodes as a logframe table: columns Result (indented by depth) · Type · Indicators · Targets · Checkout period. Default `tree`.

## Tests (`ResultsFrameworkTest.php`)

- Keep: nested result with `data-tree-depth="1"`; unassigned group.
- Add: indicator count per node; "no indicators" filter; search keeps ancestors; matrix view renders a table row per result.

## Acceptance

- [ ] ≤ 6 queries.
- [ ] Conventions §10 checklist.
