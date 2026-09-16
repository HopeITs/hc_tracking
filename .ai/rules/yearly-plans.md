---
paths:
  - 'resources/views/pages/yearly-plans/**'
---

# Yearly Plans

## The annual plan matrix pivots in PHP, never per cell
The programs × fiscal years grid is filled by exactly four reads of yearly_plans, whatever its size: year options, manager options, one whereIn over the visible program ids keyed "programId:year", and one grouped count for the column totals. Cells are array lookups through planFor(); never query inside the cell loop. YearlyPlanMatrixTest asserts that count of 4, so an added query there is a deliberate change, not an accident.

Columns are capped at MAX_YEAR_COLUMNS (10). An explicit From anchors the window at its start; otherwise it anchors at its end so the default view shows the most recent years. A reversed From/To is ordered rather than rejected — YearlyPlan::forYears() also min/maxes its bounds, so the guarantee holds below the UI.

## Delivery signal on the matrix rides plan_indicators, never yearly_plans
The four-reads-of-yearly_plans guarantee (asserted by YearlyPlanMatrixTest) still holds after the redesign, because delivery signal never adds a fifth: per-cell targets/reports come from planDelivery(), one grouped read of plan_indicators left-joined to reports keyed by the plan ids already in planCells; the per-year totals ride along on the same grouped read that counts the plans (yearStats()). Reports fan out against targets, so every aggregate is count(distinct ...). Anything that needs a program-level "has no plan in year X" set would add a yearly_plans subquery — the Gaps toggle is deliberately a client-side Alpine filter over the rendered page instead.

focusYear is a read, not a filter: it moves the cockpit and the highlighted column, and the whole span stays on screen. A focusYear outside the visible columns is ignored rather than corrected, so narrowing the range cannot leave the cockpit reporting on a column nobody can see.

Cell tint ramps var(--color-primary) to at most 34% of --color-board so the 20px bold figure on it keeps contrast in both themes; brand red as solid text on a dark ground fails, so active markers use the border/pill, not red type.
