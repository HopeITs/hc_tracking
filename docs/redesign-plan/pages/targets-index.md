# P17 · Targets index

**Route:** `targets.index` · **Group:** F · MEAL · **Wave:** 1 · **Depends on:** 02

## Files you own

- `resources/views/pages/targets/⚡index.blade.php`
- `lang/*/targets.php`
- `tests/Feature/TargetDirectoryTest.php`

## Current state

Flat list, 15/page. Filters: search (indicator name), source type (Annual Plan / Proposal), project (incl. organisation-wide), period, reset. Row: indicator name, result statement, project + city or "organisation-wide", source type badge, source label (plan year or proposal name, or "source no longer available" warning), period badge, target value, `reports_count`. Create/edit modal (source type toggle, dynamic source, indicator, value, period, project); delete blocked with reports.

Fields: `plan_indicators.source_type ('yearly_plan' | 'proposal'), source_id, indicator_id, target, project_id (nullable), period`.

## Target layout

1. **Header:** keep filters + "Add target"; add `#[Url] group` select: `indicator` (default) | `source`.
2. **Summary slot:** static counts: total targets, "{n} with no report yet" button (`#[Url] unreported`: `reports_count = 0`), "{n} with a missing source" button (`#[Url] orphaned`, reuse the existing dangling-source detection).
3. **Grouped panels:**
   - `group=indicator`: panel per indicator (name + result statement), rows = its targets.
   - `group=source`: panel per source (FY #### · program, or proposal name), rows = its targets.
   - Group headers show row count and Σ of `target` **only when all rows share the indicator's unit** (indicator grouping only; never sum across indicators).
4. **Rows** (`x-ui.data-table`): Scope (project + city or "Organisation-wide" chip) · Source (badge + label + dangling warning, unchanged logic) · Period badge · Target (numeric + unit) · **Achieved**: latest report value + `x-ui.progress-bar` (subquery pattern from P8) · Reports count · actions (keep method names).
5. Pagination: paginate **groups' rows** as today (15 rows), grouping the current page in PHP; group headers repeat on the next page with "continued".

## Tests (`TargetDirectoryTest.php`)

- Keep the dangling-source warning test.
- Add: grouping by indicator/source; unreported filter; achieved bar from latest report; no cross-indicator sums.

## Acceptance

- [ ] ≤ 6 queries.
- [ ] Conventions §10 checklist.
