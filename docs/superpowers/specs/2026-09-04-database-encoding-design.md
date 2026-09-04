# Database engine, credentials and encoding — design

**Date:** 2026-09-04
**Status:** implemented

## Goal

Make the database engine, credentials and `utf8mb4_unicode_ci` encoding an
*enforced* property of HC Tracking's configuration rather than an incidental
property of how the schemas happened to be created.

## Context discovered before building

The task was written as "configure MySQL 8.0". The machine does not run MySQL:

- `mysqld --version` reports **MariaDB 10.4.28** (XAMPP). The Windows service is
  *named* `mysql`, which is misleading. No MySQL server is installed anywhere -
  nothing under `Program Files`, `Program Files (x86)` or `ProgramData`.
- **21 other schemas** share that server, backing 8 sibling apps in
  `C:\xampp\htdocs`. Several depend on `utf8mb4_general_ci` or `utf8mb4_bin`.
- Credentials were **already correct** from the Laravel 13 baseline: a dedicated
  `hc_tracking` user, grants scoped to the two live schemas, never `root`.
- Encoding was **already correct but unenforced**. Both schemas and all 20 tables
  were `utf8mb4` / `utf8mb4_unicode_ci` on InnoDB/Dynamic, but nothing in the
  repository required that: `.env` did not pin `DB_CHARSET` / `DB_COLLATION`, and
  `config/database.php` had `'engine' => null`.
- The **server** default collation is `utf8mb4_general_ci`, so a schema created
  without an explicit `COLLATE` would silently drift.

The engine gap was put to the user as an explicit choice. They chose to stay on
MariaDB and harden the encoding rather than install MySQL.

## Decisions

| # | Decision | Rationale |
|---|---|---|
| 1 | Stay on MariaDB 10.4.28; do not install MySQL 8.0 | User's call, made with the gap stated plainly. Avoids a second database server and a dev/prod engine question that is not settled yet. |
| 2 | Pin `DB_CHARSET` / `DB_COLLATION` in `.env` and `.env.example` | The correct values were arriving from framework defaults. Pinning puts the contract next to the credentials and survives an upstream default change. |
| 3 | `'engine' => env('DB_ENGINE', 'InnoDB ROW_FORMAT=DYNAMIC')` in the `mysql` **and** `mariadb` connections | `null` means "inherit from `my.ini`", which is shared with 8 other apps. `ROW_FORMAT=DYNAMIC` also guarantees the 3072-byte index prefix `utf8mb4` `string()` columns need. Both blocks changed so switching driver changes nothing. |
| 4 | No `ALTER` of existing tables | All 20 tables were audited column by column and already comply. Altering them would be churn with nonzero risk and no benefit. |
| 5 | `my.ini` left untouched | Changing the server default collation would reach every sibling app, several of which rely on `general_ci` or `utf8mb4_bin`. |
| 6 | Keep the `mysql` driver rather than switching to `mariadb` | `MySqlConnection::isMaria()` sniffs the server at runtime and `MySqlGrammar` branches on it, so behaviour is already correct. Switching would churn `.env`, `phpunit.xml` and docs for no behavioural gain. |
| 7 | `passkeys.credential` stays `utf8mb4_bin` | MariaDB implements `$table->json()` as `longtext ... COLLATE utf8mb4_bin CHECK (json_valid(...))`. Not drift. The test encodes this so nobody "fixes" it later. |
| 8 | Enforce with a test, not a checklist | `tests/Feature/DatabaseEncodingTest.php` asserts connection config, the compiled `CREATE TABLE` DDL, every table's engine/row format/collation, every column's charset and collation, and a four-byte round trip. |

## Why the DDL assertion exists

Asserting only against `information_schema` would pass even if the `engine`
setting were a no-op, because the server default already is InnoDB/Dynamic. The
test therefore also compiles a `Blueprint` and asserts the generated statement
contains `default character set utf8mb4`, `collate 'utf8mb4_unicode_ci'` and
`engine = InnoDB ROW_FORMAT=DYNAMIC`. That distinguishes "configured" from
"coincidentally true".

## Not in scope

Installing MySQL 8.0 or 8.4, migrating sibling apps, changing `my.ini`, domain
modelling, seeders, and the production hosting decision.
