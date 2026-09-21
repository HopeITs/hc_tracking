---
paths:
  - 'resources/views/pages/results/**'
---

# Results

## The results framework loads once, then filters and rolls up in memory
results/⚡index.blade.php reads the whole chain up front — statements, indicators, `progress`, `planIndicators` (with `reports`/approved counts), the morphed `source` (AnnualPlan or Proposal, `morphWith`-loading the proposal's donors) and the projects — then resolves the tree, the measurement rollups and all four filters (search, program, type, focus) in PHP afterwards. It is a flat ~10 queries regardless of how many statements exist; if that number starts scaling with rows, an eager load has been dropped. That is deliberate: a logframe is small (see .ai/rules/concerns.md), it holds the page at a fixed query count however it is filtered, and it leaves the lineage of a parent that a filter hid available to render as context. A statement whose parent is filtered out is promoted to a display root and shows that hidden lineage rather than vanishing — `matchesFilters()` decides visibility per node, never per subtree.

`deletable` on a node comes from `childCounts` (built from the UNFILTERED set), not from the visible children, so hiding a subtree can never unlock a delete that would cascade.

Achievement is rated against the calendar year (`now()->startOfYear()`/`endOfYear()`), the same window `indicators/⚡index.blade.php` uses, so a percentage means the same thing on both pages. Any write must call `forgetFramework()`, which unsets every cached computed — clearing only `groupedRoots` re-renders stale figures in the same request.
