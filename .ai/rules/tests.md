---
paths:
  - 'tests/**'
---

# Tests

## Test helpers are factory-backed but return ids, and read back through the query builder
Every insertX() helper — the 14 shared ones in tests/Pest.php and the file-local ones in individual Feature tests — builds rows through a model factory. No helper does a raw DB::table()->insert() any more.

They still return int ids, not models, and the tests still read rows back with DB::table(...)->find(). That is deliberate: these assert database-level behaviour (column defaults, foreign key actions, unique indexes), so no Eloquent cast may sit between the assertion and the stored value. Do not "improve" them into returning models.

The helpers pass the exact attribute set the old raw insert used, and nothing more. Any column with a DB default stays omitted so the default is still what is being tested — this is why the factories omit default-bearing columns in the first place (see .ai/rules/seeders.md). When adding a helper, omit anything you want the database to decide.

tests/Feature/Models/** is the other half: those use models directly and assert relations and casts, where Eloquent IS the thing under test.

## Enum-cast tests live at the tests/Feature root, not under Models/
`tests/Feature/UserRoleTest.php`, `tests/Feature/EnumCastTest.php` and `tests/Feature/ProposalPhaseTest.php` test a cast, which looks like it belongs under `tests/Feature/Models/**` by the rule above. It doesn't move: all three test an enum's contract with its column (or, for `ProposalPhaseTest.php`, an accessor derived from one) rather than a model's relations, and `UserRoleTest.php` established this location before any other enum existed. Do not "fix" these into query-builder tests reading raw strings, and do not relocate them under `tests/Feature/Models/**`.

## Proposal/funding/transfer writes are permission-gated; one forbidden call per Livewire test instance
Proposal pages authorize through ProposalPolicy (proposals.*), and the shared ManagesAgreementForm / ManagesTransferForm check funding-agreements.* / transfers.*. Tests that write must seed RolesAndPermissionsSeeder and use User::factory()->role('Financial'). After ->assertForbidden() the Livewire Testable snapshot is invalid ("Invalid Livewire snapshot structure"), so build a fresh component for each denied call: $fresh = fn () => Livewire::actingAs($u)->test(...).
