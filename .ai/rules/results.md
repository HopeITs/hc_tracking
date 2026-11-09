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
