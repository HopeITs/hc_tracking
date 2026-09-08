# Typed backed enums for roles, workflow statuses, priorities and AR statuses

Date: 2026-09-08
Branch: migrations

## Problem

Six columns hold a fixed domain vocabulary in free-text `VARCHAR(50)`:

| Column | Column default |
| --- | --- |
| `proposals.status` | `concept note` |
| `proposals.phase` | `planning` |
| `proposal_status_notes.status` | none |
| `milestones.status` | `pending` |
| `tasks.status` | `TODO` |
| `tasks.priority` | `medium` |
| `transfers_tracking.ar_status` | `pending` |

Nothing constrains what is written. The complete member set for each was never
recorded, so factories, seeders and tests invented values as they went:
`signed`, `closed` and `awarded` are all in the repository today and none of
them is a real proposal status. `tasks.status` defaults to `TODO` while every
neighbouring default is lower case — the same casing split that made `meal` and
`MEAL` two different roles before `App\Enums\UserRole` existed.

`users.role` is already a backed enum and is not changed by this work. It is the
pattern the six new enums copy.

## Vocabulary

Confirmed by the user from the project's own status and phase diagrams. `←`
marks the column default.

- **ProposalStatus** — `concept note`←, `proposal draft`, `submitted`,
  `approved`, `rejected`, `active`, `final reports`, `completed`
- **ProposalPhase** — `planning`, `implementation`, `monitoring`, `closure`,
  `archived`
- **MilestoneStatus** — `pending`←, `reached`, `missed`, `cancelled`
- **TaskStatus** — `todo`←, `in progress`, `under review`, `done`, `blocked`
- **TaskPriority** — `low`, `medium`←, `high`
- **ArStatus** — `pending`←, `received`, `failed`

All values are stored lower case. Display capitalisation is the UI's concern,
not the database's. `received` is spelled correctly, matching the existing
column and factory.

## Decisions

### Phase is derived from status, not stored

Every proposal status belongs to exactly one phase:

| Status | Phase |
| --- | --- |
| concept note | planning |
| proposal draft | planning |
| submitted | implementation |
| approved | implementation |
| rejected | implementation |
| active | monitoring |
| final reports | closure |
| completed | archived |

`proposals.phase` is therefore redundant, and two independent columns can
disagree. The column is dropped. `ProposalStatus::phase()` returns the
`ProposalPhase`, and `Proposal` keeps a read-only `phase` accessor so
`$proposal->phase` still reads the same at every call site.

`phase()` uses a `match` with no `default` arm. A ninth status added without a
phase mapping raises `UnhandledMatchError` instead of silently taking a wrong
default. `ProposalPhase::statuses()` derives the reverse lookup by filtering
`ProposalStatus::cases()` through that same `match`, so the mapping exists in
one place. Querying a whole phase is
`whereIn('status', ProposalPhase::Implementation->statuses())`.

### ProposalStatus serves both status columns

`proposal_status_notes.status` is the audit trail of `proposals.status`. A
second enum would let the recorded history drift from the live vocabulary, so
both columns cast to `ProposalStatus`.

### The enums carry `values()` and nothing more

No `label()`, `color()` or `canTransitionTo()`. There are no Blade or Livewire
screens and no controllers, actions or form requests — policies were
deliberately deferred until the UI exists. A display helper with no caller is
dead code that still owes tests, and an unenforced transition matrix gives false
confidence. Both belong in the commit that first needs them.

`ProposalStatus::phase()` and `ProposalPhase::statuses()` are the exception:
they are required by the decision to drop the column, not speculative.

### Schema edits land in the create migrations

The `migrations` branch is unmerged and the development database is rebuildable
with `migrate:fresh` plus seeders. Editing the create migrations avoids an ALTER
migration whose only purpose is to correct a default set days earlier.

### Columns deliberately left free text

`results.type`, `results.checkout_period`, `indicators.measure`,
`plan_indicators.period`, `report_files.type`, `task_attachments.type` and the
three `*_currency` columns are enum-shaped but are NOT converted.

The first six are M&E classification vocabularies whose complete member sets are
recorded nowhere; that subsystem has migrations but no UI and no usage. An
invented enum is worse than free text here, because a value outside the enum
throws `ValueError` on read — a guess turns a cosmetic problem into rows the
application cannot load. Currency is an open ISO-4217 vocabulary; if it is ever
constrained the members should be the currencies donors actually transfer in,
which is a finance question.

## Changes

### New files — `app/Enums/`

`ProposalStatus`, `ProposalPhase`, `MilestoneStatus`, `TaskStatus`,
`TaskPriority`, `ArStatus`. Each carries `declare(strict_types=1);`, is backed
by `string`, uses TitleCase case names, and exposes:

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

`ProposalStatus` also has `phase(): ProposalPhase`. `ProposalPhase` also has
`statuses(): array<int, ProposalStatus>`.

### Migrations

- `2026_09_04_154815_create_proposals_table` — remove the `phase` column.
- `2026_09_07_124757_create_tasks_table` — default `'TODO'` becomes `'todo'`.

### Models

Casts added:

| Model | Cast |
| --- | --- |
| `Proposal` | `status` to `ProposalStatus` |
| `ProposalStatusNote` | `status` to `ProposalStatus` |
| `Milestone` | `status` to `MilestoneStatus` |
| `Task` | `status` to `TaskStatus`, `priority` to `TaskPriority` |
| `TransfersTracking` | `ar_status` to `ArStatus` |

`@property` docblocks retype from `string` to the enum. `Proposal` drops
`'phase'` from `#[Fillable]` and gains a read-only accessor returning
`$this->status->phase()`, documented as `@property-read ProposalPhase $phase`.

The `casts()` return type widens from `array<string, string>` to
`array<string, string|class-string>` where an enum is cast.

### Stale values to migrate

`signed`, `closed` and `awarded` are not members of `ProposalStatus`.

- `ProposalFactory::running()` — `'signed'` becomes `'approved'`; drop
  `'phase' => 'implementation'`.
- `ProposalStatusNoteFactory::definition()` — replace the value list with the
  real statuses.
- `FinanceSeeder` — `['concept note', 'submitted', 'signed']` becomes
  `['concept note', 'submitted', 'approved']`.
- `tests/Feature/ProposalTest.php` — `'awarded'` becomes `'approved'`; remove
  the two `phase` assertions and the `phase` key from the raw insert.
- `tests/Feature/TaskTest.php` — `'TODO'` becomes `'todo'`.
- `tests/Pest.php` — `insertProposal()` docblock no longer mentions `phase`.
- `MilestoneFactory` and `TaskFactory` need no value changes; `reached`, `done`
  and `medium` are all real members. `TaskFactory`'s class docblock says "an
  untouched medium-priority TODO" and follows the renamed default.

### Tests

`.ai/rules/tests.md` splits the suite: `tests/Feature/*Test.php` reads rows back
through the query builder and keeps asserting raw strings, unaffected by casts.
`tests/Feature/Models/*Test.php` uses models, so casts are visible there.

One existing breakage: `tests/Feature/Models/FactoryStateTest.php` asserts
`$tranche->ar_status` is `'received'` through a model. It becomes
`ArStatus::Received`.

Two new files, rather than one near-identical file per enum:

- **`tests/Feature/EnumCastTest.php`** — a dataset over all six columns
  asserting that the column default equals its enum case where the column has
  one (`proposal_status_notes.status` has none, so that row omits the check and
  supplies an explicit value instead), that a value assigned
  as an enum round-trips to the expected string in the database and back to the
  same case, that a casing variant written straight to the column
  (`'TODO'`, `'Pending'`) throws `ValueError` on read, and that every value fits
  `VARCHAR(50)`.
- **`tests/Feature/ProposalPhaseTest.php`** — every status maps to the phase in
  the table above, `ProposalPhase::statuses()` round-trips against
  `ProposalStatus::phase()`, and `$proposal->phase` follows `status` with no
  column behind it.

`tests/Feature/UserRoleTest.php` is unchanged and remains the reference pattern.

### Rule

`.ai/rules/enums.md` gains a section recording the six enums, the lower-case
value convention, why phase is derived rather than stored, and the explicit list
of columns left free text, so the omission reads as a decision rather than an
oversight.

## Verification

- `php artisan migrate:fresh --seed` succeeds.
- `php artisan test --compact` passes, including
  `tests/Feature/DatabaseEncodingTest.php` and `DatabaseSeederTest.php`.
- `vendor/bin/pint --dirty --format agent` reports no changes.

## Out of scope

- `App\Enums\UserRole` — already correct, untouched.
- Policies and authorization — still deferred until the UI exists.
- Validation rules and form requests — no HTTP layer consumes these yet.
- The classification and currency columns listed above.
