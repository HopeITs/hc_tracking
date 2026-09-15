# P6 · Program detail

**Route:** `programs.show` · **Group:** B · Strategy · **Wave:** 1 · **Depends on:** 02, P15 tree contract

## Files you own

- `resources/views/pages/programs/⚡show.blade.php`
- `lang/*/programs.php` (shared with P5, same owner)
- `tests/Feature/ProgramDetailTest.php`

## Current state

Detail header (name, updated, strategy link, manager chip); `x-ui.relation-tabs` (`#[Url] tab`): Projects (name, city, risks_count, milestones_count, user chip), Annual Plans (year, plan_indicators_count, manager), Results (recursive tree via `x-ui.tree-rows`, statement + type badge). No rollup, no pagination/search in tabs.

## Target layout

1. **Detail header:** keep; `summary` slot = delivery rollup:
   - `x-ui.progress-bar` "Milestone progress": Σ `weight_percentage` of milestones with `status = Reached` ÷ Σ `weight_percentage` of all milestones (excluding `Cancelled`) across the program's projects, one aggregate query. Null when no weighted milestones.
   - `x-ui.status-strip` (static) of the program's projects by `ProjectStatus`.
2. **Stat row:** Projects, Annual plans, Results, Open risks (`risks where happened = false`, through projects). Tiles link to the relevant tab.
3. **Tabs** (add icons: `rectangle-stack`, `calendar-days`, `chart-bar`):
   - **Projects:** `x-ui.data-table`: name link, status `enum-badge`, city, dates (`start_date`–`end_date`), milestones count, risks count (critical tone if any materialised: add `withCount(['risks as materialised_risks_count' => fn ($q) => $q->where('happened', true)])`), owner chip. Add in-tab search (`#[Url] projectSearch`) and paginate at 15 (`paginate(15, pageName: 'projectsPage')`).
   - **Annual plans:** a year strip: one `x-ui.heat-cell` per plan year (value = `plan_indicators_count`, max = the largest count) linking to `yearly-plans.show`, with manager chip under each. Sorted by year desc.
   - **Results:** `x-ui.tree` (see P15 for the node meta pattern) with type badge and per-node indicator count (`withCount('indicators')` on the recursive relation; eager-load `childrenRecursive` with the count). Keep `data-tree-depth`.

## States

- Each tab has its own empty state; search-empty uses `filtered`.

## Tests (`ProgramDetailTest.php`)

- Keep: nested outcome renders with `data-tree-depth="1"`; program with nothing attached renders.
- Add: milestone progress rollup (weights 40 reached + 60 pending → 40%); cancelled milestones excluded; projects tab search narrows; heat-cell links to plan show.

## Acceptance

- [ ] ≤ 8 queries on any tab.
- [ ] Conventions §10 checklist.
