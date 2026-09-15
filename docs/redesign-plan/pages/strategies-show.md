# P4 · Strategy detail

**Route:** `strategies.show` · **Group:** B · Strategy · **Wave:** 1 · **Depends on:** 02

## Files you own

- `resources/views/pages/strategies/⚡show.blade.php`
- `lang/*/strategies.php` (shared with P3, same owner)
- `tests/Feature/StrategyDetailTest.php`

## Current state

`x-ui.detail-header` (breadcrumbs, name, meta: programs count + updated date), three stat tiles (start, end, programs), one panel listing programs as rows: name link, manager (`HrEmployee` chip), `projects_count`, `yearly_plans_count`.

## Target layout

1. **Detail header:** keep breadcrumbs/title; `summary` slot shows a single-row `x-ui.timeline` of this strategy's span with a today marker, plus "Year {n} of {total}" when active (trans_choice).
2. **Stat row** (three `x-ui.stat-tile`): Programs (count), Projects (sum of `projects_count` across programs), Annual plans (sum of `yearly_plans_count`). Each `href` → `programs.index?strategy={id}` (use that page's existing strategy filter key).
3. **Programs panel** → `x-ui.data-table` with columns:
   - Program (name link → `programs.show`)
   - Manager (`x-ui.employee-chip compact`)
   - Projects (numeric)
   - Annual plans (numeric) + `x-ui.progress-bar` "plan coverage" = distinct plan years ÷ years in the strategy span, capped at 100 (years span = `end_year - start_year + 1`). Compute from `yearlyPlans` years within span via one grouped query (`yearly_plans` group by `program_id` count where `year between start and end`).
   - Sortable by name, projects, plans (`#[Url] sort`, `#[Url] direction`; whitelist columns, default name asc).

## States

- Strategy with no programs: `empty-state` "No programs linked to this strategy" + link to `programs.index` (assignment happens there).

## Tests (`StrategyDetailTest.php`)

- Existing tests green (update only intentionally changed markup).
- Plan coverage computed correctly for a program with plans in 2 of 4 span years (50%) and never above 100.
- Sorting by projects desc orders rows.
- Totals in stat row equal sums.

## Acceptance

- [ ] ≤ 5 queries; no lazy loading (`programs.manager` eager-loaded).
- [ ] Conventions §10 checklist.
