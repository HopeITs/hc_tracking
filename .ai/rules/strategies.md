---
paths:
  - resources/views/pages/strategies/⚡show.blade.php
  - resources/views/pages/strategies/⚡index.blade.php
---

# Strategies

## Programs table is paginated (15/page); totals read from a separate unpaginated computed
programs() returns a LengthAwarePaginator (paginate(15, pageName: 'programsPage'), sorted in the query via orderBy on the withCount aliases projects_count/yearly_plans_count — no more in-memory Collection::sortBy). sortBy() calls resetPage('programsPage') so changing sort doesn't strand the view on an out-of-range page.

totals(), planCoverage() and averageCoverage() must reflect the WHOLE strategy, not one page of 15 — they read from a new allPrograms() computed (unpaginated Collection, same programsQuery() base) instead of programs(). Don't point them at programs() again; that would silently make the stat tiles and plan-coverage math page-dependent.

Panel :count uses $this->programs->total(), not ->count() (which would return only the current page's row count).

## Strategies table paginates (15/page); the timeline stays unpaginated on purpose
portfolio() sorts strategies in-memory (custom active/upcoming/completed priority, not expressible as one SQL ORDER BY), so pagination is done manually: build the full sorted Collection first (still needed whole for the timeline + status counts), then slice it with a hand-built `LengthAwarePaginator` (`$orderedStrategies->forPage($this->getPage('strategiesPage'), 15)`, pageName 'strategiesPage'). Confirmed flux:pagination only needs the standard LengthAwarePaginator contract (getPageName/currentPage/hasPages/elements/etc) — it never calls ->path() or ->appends(), so a manually constructed paginator (not from ->paginate()) works with it exactly like an Eloquent one.

portfolio()'s 'strategies' key is the full unpaginated Collection (timeline + status counts + empty-state check read from this); 'paginated_strategies' is the LengthAwarePaginator (only the table `@foreach` and the `<flux:pagination>` read from this). Don't collapse them into one — the timeline is meant to show the whole roadmap even while the table below it is paginated.

search/status changes call resetPage('strategiesPage') (via updatedSearch() and inside filterByStatus()/resetFilters()) so a filter change doesn't strand the view on a now-empty page.
