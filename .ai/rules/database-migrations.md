---
paths:
  - 'database/migrations/**'
---

# Database Migrations

## ERD tables are plural and always carry timestamps
The HC-Tracking ERD mixes singular (`city`, `program`, `donor`) and plural (`tasks`, `risks`, `reports`) table names. We normalize to Laravel plural everywhere: `cities`, `programs`, `projects`, `donors`, `proposals`, `strategies`. This matches the existing `users` table and means no model ever needs a `protected $table` override.

Every ERD table gets `$table->timestamps()`, even the small lookups the ERD drew without them. So no model needs `public $timestamps = false;`.

The ERD's `user` entity is Laravel's existing `users` table, not a second table. Its `role` column (VARCHAR(50) DEFAULT 'Officer') was added directly to the base create_users_table migration.
