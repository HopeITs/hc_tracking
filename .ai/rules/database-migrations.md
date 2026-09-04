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
