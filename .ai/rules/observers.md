---
paths:
  - 'app/Observers/**'
---

# Observers

## plan_indicators orphan cleanup, and why ProgramObserver exists
`PlanIndicatorSourceObserver` is attached to YearlyPlan and Proposal with `#[ObservedBy]` and deletes their plan_indicators on `deleted`. source_type/source_id are both NOT NULL, so deleting is the only option — detaching is impossible. reports and report_files below each target are removed by the database, which cascades from plan_indicators.

`ProgramObserver::deleting()` deletes a program's yearlyPlans through Eloquent one at a time. This looks redundant next to the `cascadeOnDelete()` on yearly_plans.program_id, but it is load-bearing: a database cascade fires NO Eloquent events, so without this hop the plans vanish, PlanIndicatorSourceObserver never runs, and the targets are orphaned. Any future table that cascade-deletes a morph source needs the same treatment.

Limitation, deliberately left: a raw query-builder delete fires no model events either, so `DB::table('programs')->delete()` still orphans targets. `tests/Feature/PlanIndicatorTest.php` has 'a target outlives a source deleted through the query builder' documenting exactly that; `tests/Feature/Models/PlanIndicatorCleanupTest.php` covers the Eloquent paths that are handled. Delete these rows through Eloquent.
