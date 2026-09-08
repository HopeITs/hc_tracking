---
paths:
  - '{database/factories/**,database/seeders/**,tests/Pest.php}'
---

# Seeders

## Factories omit default-bearing columns; Pest helpers stay id-returning
Factory `definition()` sets only NOT NULL columns without a DB default, plus a few readable extras. Every column with a default (status, priority, currencies, is_active, is_approved, is_editable, is_seen_by_meal, happened, sequence, weight_percentage, progress_percentage, exchange_rate, fees, measure, period, checkout_period, ar_status, and the `date` columns using CURDATE()) is deliberately left out, so the schema tests keep exercising the database default rather than a value the factory injected. Use a named state (`approved()`, `received()`, `closed()`, `completed()`, `reached()`, `happened()`) to set them.

Derived columns inside factory states are per-attribute closures, so they DO see the array passed to `create()`. See `.ai/rules/factories.md`. Do not reintroduce the old `->state([...])->approved()` workaround — it is no longer needed.

`tests/Pest.php` helpers are factory-backed but still return ints, not models: the tests around them assert DB-level behaviour and read rows back through the query builder, so no Eloquent cast may sit between assertion and stored value. `insertMilestone`, `insertTask` and `insertReport` pass explicit nulls for description / narrative_report_notes because their factories would otherwise fill them.

Seeders run in dependency order from DatabaseSeeder: CoreData, Programme, Delivery, Meal, Finance. `tests/Feature/DatabaseSeederTest.php` fails if a new table is left unseeded.
