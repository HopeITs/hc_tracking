# P8 · Annual plan detail

**Route:** `yearly-plans.show` · **Group:** C · Plans · **Wave:** 1 · **Depends on:** 02

## Files you own

- `resources/views/pages/yearly-plans/⚡show.blade.php`
- `lang/*/yearly_plans.php` (shared with P7)
- `tests/Feature/YearlyPlanDetailTest.php`

## Current state

Detail header "FY #### · Program" (subtitle strategy, manager, `plan_indicators_count`); one panel "Committed Targets" with link to `targets.index` filtered by source type; flat rows: `indicator.name`, `indicator.result.statement`, `project.name`/`city.name` or "organisation-wide", `period` badge, `target`, `reports_count`.

## Target layout

1. **Detail header:** keep; `actions` = link "All targets for this plan" (existing link) + previous/next year links for the same program when those plans exist (one query: `YearlyPlan::where('program_id', ...)->whereIn('year', [year-1, year+1])`).
2. **Stat row:** Targets committed (count), Indicators covered (distinct `indicator_id`), Projects covered (distinct non-null `project_id`), Reports filed (Σ `reports_count`).
3. **Targets grouped by result** (accordion panels, one per `indicator.result`, expanded by default when ≤ 3 groups):
   - Group header: result statement + `enum-badge` of `result.type` + target count.
   - Rows (`x-ui.data-table`): Indicator name · Scope (project + city, or "Organisation-wide" chip) · Period badge · Target (numeric, with `indicator.unit`) · **Achieved**: latest reported value for that plan indicator (`reports.value` of the most recent report by `planned_date`) and `x-ui.progress-bar` achieved ÷ target (null when no report) · Reports count.
   - Latest report value: load via `withMax`-style subquery `addSelect(['latest_value' => Report::select('value')->whereColumn('plan_indicator_id', 'plan_indicators.id')->latest('planned_date')->limit(1)])`, not a per-row query.

## States

- Plan with no targets: empty state + link to `targets.index` to add targets.

## Tests (`YearlyPlanDetailTest.php`)

- Existing tests green.
- Grouping: two results → two groups with correct counts.
- Achieved bar: target 100, latest report value 40 → 40%; older report ignored.
- Prev/next links appear only when the adjacent plan exists.

## Acceptance

- [ ] ≤ 6 queries.
- [ ] Conventions §10 checklist.
