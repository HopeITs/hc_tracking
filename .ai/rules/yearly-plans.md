---
paths:
  - 'resources/views/pages/yearly-plans/**'
---

# Yearly Plans

## The annual plan matrix pivots in PHP, never per cell
The programs × fiscal years grid is filled by exactly four reads of yearly_plans, whatever its size: year options, manager options, one whereIn over the visible program ids keyed "programId:year", and one grouped count for the column totals. Cells are array lookups through planFor(); never query inside the cell loop. YearlyPlanMatrixTest asserts that count of 4, so an added query there is a deliberate change, not an accident.

Columns are capped at MAX_YEAR_COLUMNS (10). An explicit From anchors the window at its start; otherwise it anchors at its end so the default view shows the most recent years. A reversed From/To is ordered rather than rejected — YearlyPlan::forYears() also min/maxes its bounds, so the guarantee holds below the UI.
