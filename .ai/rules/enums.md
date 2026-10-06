---
paths:
  - '{config/fortify.php,app/Console/Commands/**,app/Enums/**}'
---

# Enums

## Registration is off; accounts come from user:create; roles are a backed enum
`Features::registration()` is deliberately removed from config/fortify.php. This is an internal system holding donor budgets — re-enabling it exposes /register to anyone on the internet. tests/Feature/Auth/RegistrationDisabledTest.php fails if it comes back.

TRAP: disabling it once broke the login page, which called route('register') unguarded and 500'd /login. The register and password-reset links, views and actions (CreateNewUser, ResetUserPassword) have since been deleted outright, so the login page links to neither. Guard any new link to an optional Fortify route with Route::has().

Accounts are created with `php artisan user:create` (interactive, or fully scripted with --name --email --role --password --no-interaction). It marks the address verified unless --unverified is passed, although the `verified` middleware and email verification were removed in 2026-09 (User is not MustVerifyEmail; emails come from HR).

`users.role` casts to App\Enums\UserRole (Officer, Manager, MEAL, Admin). Casing is part of the value — 'meal' is NOT 'MEAL' and now throws a ValueError on read rather than silently being a fourth role. `role` stays out of the User model's #[Fillable] so it can never be set by mass assignment; user:create uses forceFill.

Policies do not exist yet. Authorization rules were deliberately deferred until the UI exists and the real per-role access rules are known.

## Six workflow columns are backed enums; values are lower case

The workflow six are `ProposalStatus`, `ProposalPhase`, `MilestoneStatus`,
`TaskStatus`, `TaskPriority` and `ArStatus`, alongside the pre-existing
`UserRole`. Five more were added later for the M&E and finance columns — see
"Every enum-shaped column is now a backed enum" below for the full inventory.

Every enum in `App\Enums` is string-backed with a `values()` helper, and each
is cast in its owning model's `casts()`. `ProposalPhase` is the single
exception: it has no column of its own (see below).

Values are lower case — 'todo', 'in progress', 'concept note'. `tasks.status`
defaulted to 'TODO' before this and was the one column in the schema cased
against its neighbours; the create migration now says 'todo'. Casing is part of
the value here exactly as it is for MEAL, so a value differing only by
capitalisation throws ValueError on read. Display capitalisation is the UI's
job.

`ProposalStatus` casts BOTH `proposals.status` and
`proposal_status_notes.status`. The notes table is that column's audit trail; a
second enum would let the recorded history drift from the live vocabulary.

## proposals.phase is derived from status, not stored

There is no `phase` column. Every status belongs to exactly one phase, so two
columns could contradict each other. `ProposalStatus::phase()` holds the
mapping in a `match` with no default arm — a status added without a phase
raises UnhandledMatchError instead of silently taking a wrong one — and
`ProposalPhase::statuses()` derives the reverse lookup from that same match.

Query a whole phase with
`whereIn('status', ProposalPhase::Implementation->statuses())`. Read one
proposal's phase with `$proposal->phase`, a read-only accessor over
`status->phase()`.

TRAP: `Proposal` carries `protected $attributes = ['status' => 'concept note']`
mirroring the column default. Without it `Proposal::factory()->create()->phase`
throws, because the factory omits the column and the attribute stays unhydrated
until refresh — the same trap `Account::$is_active` documents. The column
default is therefore proven by a raw insert in tests/Feature/ProposalTest.php,
not by the factory.

Two values were in the repository and are NOT members of `ProposalStatus`:
'signed' (ProposalFactory, ProposalStatusNoteFactory, FinanceSeeder) and
'awarded' (tests/Feature/ProposalTest.php, tests/Feature/ProposalStatusNoteTest.php).
Both are now 'approved'. 'closed' existed only inside
ProposalStatusNoteFactory's hand-written value list
(`['concept note', 'submitted', 'signed', 'rejected', 'closed']`); when that
list was replaced by `ProposalStatus::cases()`, 'closed' simply disappeared —
it was never remapped to 'completed', and nothing in the repository became
'completed' as part of this work. `ProposalStatusNoteFactory` draws from
`ProposalStatus::cases()` rather than a hand-written list so it cannot drift
again.

## Every enum-shaped column is now a backed enum

The five added after the original six, all confirmed with the user rather than
inferred:

| Enum | Columns | Members |
| --- | --- | --- |
| `ResultType` | `results.type` | impact, outcome, output |
| `IndicatorMeasure` | `indicators.measure` | numeric, percentage |
| `Period` | `results.checkout_period`, `plan_indicators.period` | monthly, quarterly, semi-annual, annual |
| `FileType` | `report_files.type`, `task_attachments.type` | pdf, docx, xlsx, jpg, png |
| `Currency` | `donor_proposal.currency`, the three `transfers_tracking` currency columns | USD, EUR, SYP |

Nothing enum-shaped is left as free text. `plan_indicators.source_type` is the
morph map, not an enum — see `.ai/rules/models.md`.

TRAP: `Currency` values are UPPER case, the only enum here that is. They are
ISO 4217 codes and the columns already held 'USD'. Casing is still part of the
value, so 'usd' throws on read exactly as 'meal' does for `UserRole`.

TRAP: `donor_proposal.currency` reaches you as TWO different types depending
on the path. `DonorProposal::first()->currency` is a `Currency` case, because
the model casts it. `$proposal->donors()->first()->pivot->currency` is the raw
string 'USD', because `belongsToMany(...)->withPivot(['currency', ...])` builds
a generic Pivot that carries no casts. Proven by
tests/Feature/Models/DonorTest.php, which asserts the pivot value with
`->toBe('USD')` and passes.

This is why `Proposal::donors()`'s docblock says to prefer `donorProposals()`
when those columns matter. Casting the pivot would need a dedicated Pivot class;
do not add one without a caller that needs it. Until then, never compare a pivot
currency to a `Currency` case with `===` — it is always false.

TRAP: `FileType` is the file EXTENSION, not a display category. `report_files`
and `task_attachments` build their `path` from it (`{uuid}.{type->value}`), so
the recorded type and the extension on disk can never disagree. It doubles as
the set of formats an upload may use. Do not "improve" it into pdf/image/word/
excel categories without also changing how paths are built.

`Period` is shared by two columns that previously disagreed with each other:
`results.checkout_period` defaulted to 'quarter' while `plan_indicators.period`
defaulted to 'quarterly'. The results migration now says 'quarterly' so a report
grouping on either column sees one vocabulary.

Two values were in the repository and are NOT members, both removed rather than
added to an enum: `results.type` 'goal' (three test fixtures, now
`ResultType::Impact` — the statement 'Reduced waterborne disease.' is an impact
by any standard M&E reading), and the currencies 'GBP' and 'TRY', which only
ever appeared inside `AccountFactory`'s account NAME strings, never in a
currency column. `AccountFactory` now names accounts from `Currency::values()`.

## Blocked removed from TaskStatus — four values only

`TaskStatus::Blocked` ('blocked') was removed by user decision 2026-09-18. The enum is now exactly Todo, InProgress, UnderReview, Done — no "blocked" concept anywhere in the tasks domain.

Migration `2026_09_18_154724_remove_blocked_status_from_tasks` reassigns any existing `tasks.status = 'blocked'` row to 'todo' before the enum stops recognizing the value — `tasks.status` is a free VARCHAR(50) with no DB check constraint, so without this a pre-existing blocked row would throw a ValueError the next time Eloquent read it through the `TaskStatus` cast. There's no "previous status" recorded for a blocked task, so the fallback is always 'todo', never a guess at what it resumes as.

Also removed: the `'blocked' => 'accent'` arm from `x-ui.enum-badge`'s TaskStatus match, the `TaskStatus::Blocked` arm from the shared calendar page's (`resources/views/pages/calendar/⚡index.blade.php`) `$taskTone` match, and the `enums.task_status.blocked` key from both `lang/en/enums.php` and `lang/ar/enums.php`. All three were plain `match` expressions over `TaskStatus::cases()` with no `default` arm, so removing the case and its arm together keeps them exhaustive — do not add a `default` arm as a shortcut; a future 5th status should still be forced to declare its own tone in every match, the same way `ProposalStatus::phase()` above is deliberately exhaustive.

## PHPStan does not analyse tests/, and adding it is not a one-line change

`phpstan.neon` lists app/, bootstrap/app.php, config/, database/ and routes/.
Adding `tests/` was tried and reverted: it produces 342 errors, none of them
real bugs.

Two causes, both structural. `DB::table(...)->find()` returns
`object|array|null`, so every query-builder assertion the `.ai/rules/tests.md`
convention mandates trips `property.nonObject` — that is 300+ tests. And Pest's
`->get()`, `->actingAs()` and `->and()` chains are unknown to PHPStan without
`pestphp/pest-plugin-phpstan`, which is a new dev dependency and needs approval.

Do not add `tests/` to the paths without installing that plugin first and
solving the query-builder union type. A permanently red `phpstan analyse` is
worse than not analysing tests.

The single exception: tests/PHPStan/ (abstract Livewire host classes, no Pest tests) IS in phpstan.neon's paths so traits used only by Blade pages get analysed — see .ai/rules/p-h-p-stan.md. Everything else in tests/ stays out.
