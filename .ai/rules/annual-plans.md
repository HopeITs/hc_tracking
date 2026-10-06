---
paths:
  - 'resources/views/pages/annual-plans/**'
---

# Annual Plans

## The annual plan matrix pivots in PHP, never per cell
The programs × fiscal years grid is filled by exactly four reads of annual_plans, whatever its size: year options, manager options, one whereIn over the visible program ids keyed "programId:year", and one grouped count for the column totals. Cells are array lookups through planFor(); never query inside the cell loop. AnnualPlanMatrixTest asserts that count of 4, so an added query there is a deliberate change, not an accident.

Columns are capped at MAX_YEAR_COLUMNS (10). An explicit From anchors the window at its start; otherwise it anchors at its end so the default view shows the most recent years. A reversed From/To is ordered rather than rejected.

## Delivery signal on the matrix rides plan_indicators, never annual_plans
The four-reads-of-annual_plans guarantee (asserted by AnnualPlanMatrixTest) still holds after the redesign, because delivery signal never adds a fifth: per-cell targets/reports come from planDelivery(), one grouped read of plan_indicators left-joined to reports keyed by the plan ids already in planCells; the per-year totals ride along on the same grouped read that counts the plans (yearStats()). Reports fan out against targets, so every aggregate is count(distinct ...). Anything that needs a program-level "has no plan in year X" set would add an annual_plans subquery — the Gaps toggle is deliberately a client-side Alpine filter over the rendered page instead.

focusYear is a read, not a filter: it moves the cockpit and the highlighted column, and the whole span stays on screen. A focusYear outside the visible columns is ignored rather than corrected, so narrowing the range cannot leave the cockpit reporting on a column nobody can see.

Cell tint ramps var(--color-primary) to at most 34% of --color-board so the 20px bold figure on it keeps contrast in both themes; brand red as solid text on a dark ground fails, so active markers use the border/pill, not red type.

## Matrix grid switches at @4xl and caps its own tracks
The cards/grid switch is `@4xl/matrix` (56rem container), not `@5xl`. At `@5xl` the grid needed roughly a 1440px viewport: the sidebar plus padding left a 1366x768 laptop with a 1023px content box, one pixel short of the 64rem query, so the two most common laptop sizes fell back to the phone card list and never saw the matrix. Measured, not guessed — check with a container-width readout before moving it again.

Row tracks are `minmax(15rem, 26rem) repeat(var(--matrix-columns), minmax(6.25rem, 14rem)) 1fr`. The caps matter: with `1fr` on the name track and a capped year track, a short year span handed the name column every spare pixel (1142px against 144px cells at 1920). The trailing `1fr` parks the leftover at the end of the row instead. Both the header row and the program rows carry this template — change them together.

A write whose year falls outside the visible span clears yearFrom/yearTo and sets focusYear, so a plan created under a narrow year filter is not saved onto a screen that cannot show it.
