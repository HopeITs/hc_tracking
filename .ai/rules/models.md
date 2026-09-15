---
paths:
  - 'app/Models/**'
  - app/Models/Account.php
  - 'app/Models/Hr*.php'
  - app/Models/Program.php
  - app/Models/User.php
---

# Models

## plan_indicators sources are YearlyPlan and Proposal, via a morph map
`plan_indicators.source_type`/`source_id` is a morphTo relation with exactly two valid sources: YearlyPlan and Proposal. A target is committed either in an annual operational plan or in a donor grant proposal.

`source_type` is VARCHAR(50), so register short morph keys rather than storing class names:

    Relation::enforceMorphMap([
        'yearly_plan' => YearlyPlan::class,
        'proposal' => Proposal::class,
    ]);

Put this in a service provider's boot(). Storing 'App\\Models\\YearlyPlan' would work today but leaves no headroom in the column and couples stored data to the namespace.

No foreign key backs source_id — see the migrations rule about orphans for the cleanup obligation.

## Model file shape: strict_types, attributes, casts() and CarbonImmutable
Every class in app/Models carries `declare(strict_types=1);`. Pint's laravel preset does not add or strip it, so keep it by hand on new models.

Mass assignment and hidden fields use the Laravel 13 class attributes `#[Fillable([...])]` / `#[Hidden([...])]`, not `protected $fillable`. Casts go in `protected function casts(): array`. `users.role` is deliberately NOT fillable — adding it opens privilege escalation through registration.

`AppServiceProvider` calls `Date::use(CarbonImmutable::class)`, so every `date` / `datetime` cast returns `CarbonImmutable`, never `Illuminate\Support\Carbon`. Type `@property` docblocks accordingly.

`DonorProposal` and `TransfersTracking` are the only models needing `protected $table` (donor_proposal, transfers_tracking); everything else infers.

## is_active decides whether an account is usable, not closed_at
`accounts.is_active` is the single source of truth for whether an account can receive a transfer. `closed_at` only records when it was closed and must never be read as a usability check.

Call sites go through `isUsable()`, the `#[Scope] active()` query scope, or `close()` — never the raw columns, so the two can never be interpreted apart. `close()` writes both together so a closed account is never left looking active.

The model carries `protected $attributes = ['is_active' => true]` mirroring the column default. Without it `Account::create([...])->isUsable()` throws a TypeError, because the factory deliberately omits the column and the attribute is therefore unhydrated until the model is refreshed. The column default is still proven independently by a raw-insert test in tests/Feature/AccountTest.php.

Accounts are never deleted — transfers_tracking.account_id restricts on delete. Close them instead.

## HR database is read-only; tracking links to it by id
The `hr` connection (hr_db, prefix hchr_) is owned by another app. It opens every MySQL session READ ONLY, and HrModel::save()/delete() throw. Never write, migrate or seed HR tables. Cross-DB links (projects.city_id, projects.user_id, milestones.responsible_user_id, tasks.assigned_to, programs/yearly_plans.manager_id, users.hr_user_id/employee_id) hold HR ids with NO foreign key; search by HR names resolves ids first (HrEmployee::idsMatchingName) instead of whereHas across connections. HrModel::newRelatedInstance stops tracking models inheriting the hr connection. Tests run `hr` on in-memory SQLite (phpunit.xml); createHrSchema() runs before every Feature test and insertHr*() helpers build fixtures.

## Programs mirror HR programs under the same id
Each programs row mirrors one hchr_programs row with the same id, so local FKs and cascades keep working. `name` is a copy refreshed by Program::syncFromHr(), run by SyncProgramsFromHr web middleware at most once a minute; programs removed from HR are kept. Tracking owns only strategy_id and manager_id, both nullable (shown as "Not assigned"). Programs are never created or renamed in tracking; ProgramFactory writes the HR row first (test DB only), seeders sync from HR.

## Mirror DB column defaults in User's $attributes for strict mode
`User::factory()->create()` without an explicit role never puts `role` in the INSERT payload (it's only set via the `role()` factory state, or `createFromHrAccount()`/`forceFill` in real app code). Under `Model::shouldBeStrict()`, the resulting in-memory model then throws `MissingAttributeException` the moment anything reads `$user->role` (e.g. a Policy). Fixed by adding `'role' => 'Officer'` to `User::$attributes` alongside the existing `'locale' => null` — mirroring the DB column default so a user that hasn't round-tripped through the database still reports it. Apply the same pattern to any other User (or other model) column that has a DB default but isn't always present in factory-created instances.
