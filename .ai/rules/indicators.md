---
paths:
  - 'resources/views/pages/indicators/**'
---

# Indicators

## The indicator directory loads once, then filters, rates and pages in memory
indicators/⚡index.blade.php reads every indicator up front with the whole chain — result (+program, +`parentRecursive` for lineage), project, `progress` (+recorder), `planIndicators` (+project, the morphed `source` with the proposal's donors, and the `reports` rows themselves) — then resolves the six filters, the RAG rating, staleness, the overview, the five attention chip counts and pagination in PHP afterwards. Flat at 20 queries however it is filtered or paged; `IndicatorDirectoryTest` asserts that, so a dropped `with()` fails the suite rather than quietly going N+1.

`reports` are loaded as rows, not `withCount`, because the page shows what was actually reported (`sum(reports.value)`) against what was committed (`plan_indicators.target`) — the question the funding side asks. Counts alone cannot answer it. A report still `draft` past its `planned_date` is what the "Reporting overdue" chip counts.

Lineage is walked over the eager-loaded `parentRecursive` via `lineageOf()`, never `HasTree::ancestors()` — `ancestors()` reads `parent`, a different relation key, so it would fire a query per level per card.

Because the filters run in memory, the paginator is hand-built (`new LengthAwarePaginator(...)` over `filteredRows`), not `->paginate()`. `WithPagination` is still used for `getPage()`/`resetPage()`.

Achievement is rated against the calendar year (`now()->startOfYear()`/`endOfYear()`), the same window results/⚡index.blade.php uses, so a percentage means the same thing on both pages. The "expected by now" marker on a card's trajectory rail is that window's elapsed share — it is what the RAG rating compares achievement against, so the two must keep using the same figure.

Any write must call `forgetIndicators()`, which unsets every cached computed — clearing only `pagedRows` re-renders stale headline figures in the same request.

All four dialogs (indicator form, delete, reading, chain inspector) are bound with `wire:model` on a boolean, never `:open`; see .ai/rules/pages.md for why.
