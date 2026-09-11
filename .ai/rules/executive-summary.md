---
paths:
  - 'resources/views/pages/executive-summary/**'
---

# Executive Summary

## Coverage cards: what may be measured, and how the share is rounded
Do not add a "projects attached to a program" card: projects.program_id is NOT NULL, so that ratio is 100% forever. Any new card must use a column that can actually be empty (plan_indicators.project_id and results.program_id are nullable; yearly_plans.year is the fiscal scope).

percentageOf() floors a partial share and caps it at 99, returning 100 only when covered >= total. A near-miss must never report full coverage. A zero total returns null and the card renders an em dash, so an empty install still renders.

Year-scoped cards filter plan_indicators on source_type 'yearly_plan' (the morph key, never the class name) joined to that year's plans, so donor-proposal targets are excluded. Geographic reach is deliberately portfolio-wide — projects carry no year — and says "Portfolio" on its pill instead of "FY ####".
