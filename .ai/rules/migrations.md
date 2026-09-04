---
paths:
  - '{config/database.php,.env.example,database/migrations/**}'
---

# Migrations

## Database encoding contract: utf8mb4_unicode_ci on InnoDB DYNAMIC
Every schema, table and column is utf8mb4 / utf8mb4_unicode_ci on InnoDB with ROW_FORMAT=DYNAMIC. It is pinned, not inherited: DB_CHARSET / DB_COLLATION in .env, and 'engine' => env('DB_ENGINE', 'InnoDB ROW_FORMAT=DYNAMIC') in both the mysql and mariadb connections.

Never change XAMPP's my.ini to fix a collation problem. That server is shared with 20+ schemas backing 8 other apps in C:\xampp\htdocs, several of which rely on utf8mb4_general_ci or utf8mb4_bin. The server default collation is utf8mb4_general_ci, so always state COLLATE utf8mb4_unicode_ci explicitly when creating a schema.

tests/Feature/DatabaseEncodingTest.php enforces this and fails on drift. It asserts the compiled CREATE TABLE DDL as well as information_schema, because information_schema alone would pass even if the engine setting were a no-op.
