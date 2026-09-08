---
paths:
  - 'database/migrations/**'
---

# Database Migrations

## ERD tables are plural and always carry timestamps
The HC-Tracking ERD mixes singular (`city`, `program`, `donor`) and plural (`tasks`, `risks`, `reports`) table names. We normalize to Laravel plural everywhere: `cities`, `programs`, `projects`, `donors`, `proposals`, `strategies`. This matches the existing `users` table and means no model ever needs a `protected $table` override.

Every ERD table gets `$table->timestamps()`, even the small lookups the ERD drew without them. So no model needs `public $timestamps = false;`.

The ERD's `user` entity is Laravel's existing `users` table, not a second table. Its `role` column (VARCHAR(50) DEFAULT 'Officer') was added directly to the base create_users_table migration.

## Name columns get no UNIQUE index by default
Standing decision for the ERD tables: `name` columns are plain, non-unique VARCHARs unless the user is asked and says otherwise. `cities.name` is the one exception already agreed (UNIQUE). `strategies.name`, `donors.name` and `programs.name` are deliberately NOT unique.

Raise the question explicitly only when a duplicate would corrupt reporting (e.g. a master-data table referenced with RESTRICT), and let the user decide. Do not add a unique index on your own.

The ERD types short labels as TEXT. Use VARCHAR instead: TEXT cannot be indexed without a prefix length, carries no DEFAULT, and is stored off-page in InnoDB.

## Pivot tables stay singular — the plural rule covers entity tables only
The "ERD tables are plural" rule applies to entity tables. Many-to-many pivots follow Laravel's own convention instead: singular model names, alphabetical, underscore-joined — `project_proposal`, not `project_proposals`. Naming a pivot plural silently breaks `belongsToMany()` inference and forces a `$table` override at every call site.

Pivots also get a UNIQUE on the id pair (`unique(['project_id', 'proposal_id'])`). Without it `attach()` is silently additive and duplicate link rows double-count in rollups.

Note `donor_proposal` later in the ERD is NOT a plain pivot — it carries budget and currency, so it is an entity in pivot clothing. Decide its name when you reach it.

## plan_indicators.source_id has no FK — orphans are possible by design
`plan_indicators.source_type`/`source_id` is polymorphic, so no foreign key can exist on it. Deleting a source row (e.g. a yearly_plan cascade-deleted with its program) leaves the plan_indicators row behind with a dangling source_id. The database will not stop this.

Cleanup lives in application code: `PlanIndicatorSourceObserver` on YearlyPlan and Proposal, plus `ProgramObserver` to bridge the database cascade that fires no model events. See `.ai/rules/observers.md`.

This covers Eloquent deletes only. A raw query-builder delete still orphans targets and no foreign key can stop it — `tests/Feature/PlanIndicatorTest.php` has a test named "a target outlives a source deleted through the query builder" documenting that remaining route, and `tests/Feature/Models/PlanIndicatorCleanupTest.php` covers the paths that are handled.

`source_type` is VARCHAR(50), so a `Relation::enforceMorphMap()` with short keys ('yearly_plan', not 'App\\Models\\YearlyPlan') is required rather than optional.

## Two reports columns are deliberately renamed from the ERD
The ERD draws `reports.plan_indicators_id` (plural) and `reports.is_seen_by_MEAL` (mixed case). The migration uses `plan_indicator_id` and `is_seen_by_meal` instead.

Singular FK means `$report->belongsTo(PlanIndicator::class)` infers the key with no second argument, matching every other FK in the schema. Lowercase snake_case matches `is_approved` / `is_editable` and removes a case-sensitivity trap in attribute access.

If you diff the schema against the ERD export, these two are intentional, not drift.

## reports_files was renamed to report_files with a report_id column
The ERD draws `reports_files` with a `reports_id` column. The migration creates `report_files` with `report_id` instead — singular owner, matching `task_attachments` and the `plan_indicator_id` rename on reports.

Consequence: model `ReportFile` resolves to `report_files` with no `$table` override, and `$file->report()` infers its key.

Its `date` column also gained a `default (CURDATE())` that the ERD does not have, so an omitted upload date means today — consistent with `task_attachments.date`. An explicit date still wins, so files can be backdated to the document's own date.

## accounts is a new table; transfers_tracking diverges from the ERD in three ways
`accounts` (name, opened_at, closed_at, is_active) does not exist in the ERD. It was added so `transfers_tracking.account_id` could become a real FK instead of the unconstrained BIGINT the ERD drew. Note `is_active` and `closed_at` overlap. That is now settled: `is_active` decides usability, `closed_at` only records when. See `.ai/rules/models.md`.

`transfer_tracking` in the ERD is `transfers_tracking` here, and the ERD's misspelled `amount_recevied` is `amount_received`. Both deliberate.

Two `$table` overrides are required, because Eloquent's pluralization does not match these names:
    class TransfersTracking { protected $table = 'transfers_tracking'; }
    class DonorProposal { protected $table = 'donor_proposal'; }

`transfers_tracking.donor_proposal_id` must use `constrained('donor_proposal')` explicitly — inference would look for `donor_proposals`.

account_id is nullable with RESTRICT: a tranche can be scheduled before an account is assigned, but an account money has passed through can never be deleted. Close it with closed_at / is_active instead.

## donor_proposal.proposal_id RESTRICTs to protect the money trail
`donor_proposal.proposal_id` restricts on delete; it does NOT cascade. This was changed deliberately after the original cascade was found to let one `$proposal->delete()` reach through donor_proposal into transfers_tracking and erase every record of money received — going around the RESTRICT on transfers_tracking.account_id that exists precisely to prevent that.

Consequence: a proposal with any funding agreement cannot be deleted. Remove the agreement first, which is a separate deliberate act. A proposal with no funding still deletes normally and its tasks, project links and status notes cascade as before.

Deleting a donor_proposal DOES still cascade to its transfers_tracking rows. That is intended — acting on the agreement itself is the deliberate act.

Covered by "a proposal cannot be deleted while a funding agreement points at it" in tests/Feature/DonorProposalTest.php and by tests/Feature/Models/TransactionalDeleteTest.php.
