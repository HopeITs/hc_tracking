---
paths:
  - 'app/Observers/**'
  - 'app/Observers/{MilestoneObserver,ResultObserver}.php'
---

# Observers

## plan_indicators orphan cleanup, and why ProgramObserver exists
`PlanIndicatorSourceObserver` is attached to AnnualPlan and Proposal with `#[ObservedBy]` and deletes their plan_indicators on `deleted`. source_type/source_id are both NOT NULL, so deleting is the only option — detaching is impossible. reports and report_files below each target are removed by the database, which cascades from plan_indicators.

`ProgramObserver::deleting()` deletes a program's annualPlans through Eloquent one at a time. This looks redundant next to the `cascadeOnDelete()` on annual_plans.program_id, but it is load-bearing: a database cascade fires NO Eloquent events, so without this hop the plans vanish, PlanIndicatorSourceObserver never runs, and the targets are orphaned. Any future table that cascade-deletes a morph source needs the same treatment.

Limitation, deliberately left: a raw query-builder delete fires no model events either, so `DB::table('programs')->delete()` still orphans targets. `tests/Feature/PlanIndicatorTest.php` has 'a target outlives a source deleted through the query builder' documenting exactly that; `tests/Feature/Models/PlanIndicatorCleanupTest.php` covers the Eloquent paths that are handled. Delete these rows through Eloquent.

## Tree integrity lives in observers, not the schema
MilestoneObserver and ResultObserver guard the self-referencing trees on saving(), throwing App\Exceptions\InvalidParentException. Neither guard is expressible in the schema, so removing the observer silently reopens both holes:

- InnoDB will happily store a row whose parent_id points at itself or at one of its own descendants.
- milestones.project_id and parent_milestone_id are independent foreign keys, so nothing at the database level stops a milestone nesting under one in another project. MilestoneFactory::childOf() copies the parent's project_id for this reason.

Both guards only fire when the parent column is dirty and non-null, so detaching a node (parent -> null) and untouched saves stay cheap. They throw rather than returning false: a cycle is a programming error, and a silent cancelled save would corrupt the tree quietly.

Like every observer here, a raw query-builder write bypasses this. Move nodes through Eloquent.
