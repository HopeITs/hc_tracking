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
