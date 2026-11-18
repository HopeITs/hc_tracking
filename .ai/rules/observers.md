---
paths:
  - 'app/Observers/**'
---

# Observers

## plan_indicators orphan cleanup, and why ProgramObserver exists
`PlanIndicatorSourceObserver` is attached to AnnualPlan and Proposal with `#[ObservedBy]` and deletes their plan_indicators on `deleted`. source_type/source_id are both NOT NULL, so deleting is the only option — detaching is impossible. reports and report_files below each target are removed by the database, which cascades from plan_indicators.

`ProgramObserver::deleting()` deletes a program's annualPlans through Eloquent one at a time. This looks redundant next to the `cascadeOnDelete()` on annual_plans.program_id, but it is load-bearing: a database cascade fires NO Eloquent events, so without this hop the plans vanish, PlanIndicatorSourceObserver never runs, and the targets are orphaned. Any future table that cascade-deletes a morph source needs the same treatment.

Limitation, deliberately left: a raw query-builder delete fires no model events either, so `DB::table('programs')->delete()` still orphans targets. `tests/Feature/PlanIndicatorTest.php` has 'a target outlives a source deleted through the query builder' documenting exactly that; `tests/Feature/Models/PlanIndicatorCleanupTest.php` covers the Eloquent paths that are handled. Delete these rows through Eloquent.

## Tree integrity lives in observers, not the schema
ResultObserver guards the self-referencing tree on saving(), throwing App\Exceptions\InvalidParentException. InnoDB would otherwise accept a parent_id pointing at the result itself or one of its descendants.

The guard fires when parent_id changes to a non-null value, so detaching a result and saving unrelated fields stay cheap.

Like every observer here, a raw query-builder write bypasses this. Move nodes through Eloquent.
