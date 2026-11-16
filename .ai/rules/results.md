---
paths:
  - 'resources/views/pages/results/**'
---

# Results

## The results framework loads once, then filters and rolls up in memory
results/⚡index.blade.php reads the whole chain up front — statements, indicators, `progress`, `planIndicators` (with `reports`/approved counts), the morphed `source` (AnnualPlan or Proposal, `morphWith`-loading the proposal's donors) and the projects — then resolves the tree, the measurement rollups and all four filters (search, program, type, focus) in PHP afterwards. It is a flat ~10 queries regardless of how many statements exist; if that number starts scaling with rows, an eager load has been dropped. That is deliberate: a logframe is small (see .ai/rules/concerns.md), it holds the page at a fixed query count however it is filtered, and it leaves the lineage of a parent that a filter hid available to render as context. A statement whose parent is filtered out is promoted to a display root and shows that hidden lineage rather than vanishing — `matchesFilters()` decides visibility per node, never per subtree.

`deletable` on a node comes from `childCounts` (built from the UNFILTERED set), not from the visible children, so hiding a subtree can never unlock a delete that would cascade.

Achievement is rated against the calendar year (`now()->startOfYear()`/`endOfYear()`), the same window `indicators/⚡index.blade.php` uses, so a percentage means the same thing on both pages. Any write must call `forgetFramework()`, which unsets every cached computed — clearing only `groupedRoots` re-renders stale figures in the same request.

## Three headline buttons, one honest status per statement (scenario review 2026-11-09)
The four figure tiles, the five level cards, the coverage ring and the chip row were replaced by three buttons in `_index-overview`: Needs an indicator / Behind / Waiting for a measurement. Each sets `focus` (`unmeasured` / `offtrack` / `waiting`); pressing the active one clears it. Each says its denominator ("17 of 63 statements") and `focusCounts()` counts them under the other active filters.

`measurements()[id]['status']` is `none` (no indicator), `behind` (any amber/red), `waiting` (any indicator never read or not read within its own cadence, `Period::freshnessDays()`, shared with the indicators page), `ontrack`, or `notarget`. A green rating counts as on track only while fresh, so the three counts are disjoint and never call an old figure "on track". The average achievement caps each indicator at 100%.

User decision 2026-11-09: the Cascade/Matrix switch (the `view` property and `_index-matrix`) was removed; the page is the one cascade list, one panel per program or proposal framework. The statement card says "Review: Quarterly" instead of "Quarterly check-out". Per-target percentages on the card (e.g. 326% of a commitment) are real approved-report figures and stay uncapped; only averages are capped.

## One list view, filters behind More filters (chart removed, user decision 2026-11-16)
The chart view (program-by-level worst-status grid, `_index-chart`, `statusGrid()`, the `view` switch) was removed on 2026-11-16: the owner found it not useful, since it only repeated the three headline buttons and led nowhere. The page is the one cascade list; an old `?view=chart` link just shows it. Do not bring a chart view back without asking (stacked bars were rejected too). Search is in the register's `search` slot (`x-desk.register-search`), Program stays visible and Framework and Type sit behind More filters, open when one is active, like the Indicators page.

## Tree view: a top-down logframe diagram beside the list (user decision 2026-11-16)
Replaces the removed chart. The register switch is "View as list" / "View as tree" (`view`: `list` default, `tree`). `_index-tree` draws one org-chart tree per `groupedRoots` group from the same filtered nodes as the list, via the recursive `_index-tree-node`; connector lines are pure CSS in `resources/css/logframe-tree.css` (`lf-*`, logical properties for Arabic). Each box shows the ResultType mark/accent, the statement (4 lines max), the status word with an icon (on track / below target / waiting / needs an indicator / no target) and "N indicators · avg%" (capped at 100). Branches fold; outputs start folded so activities stay hidden until asked for. Clicking a box switches to the list and scrolls to `#framework-result-{id}` (the id on x-ui.framework-node) — the tree is for seeing the shape, the list card holds the actions. Any other `view` value (an old `?view=chart` link) shows the list.
