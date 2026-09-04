# Archived schemas

SQL dumps taken 2026-09-04, before this project was rebuilt.

| File | Source schema |
|---|---|
| `hc-tracking_20260904-121146.sql` | `hc-tracking` (note the hyphen) |
| `hc_tracking_test_20260904-121146.sql` | `hc_tracking_test` |

Each contains 28 tables and 26 foreign keys from the previous attempt at this
project: donors, programs, strategies, projects, indicators, results, risks,
transfers, reports, requirements, media, plus centers, cities, currencies and
accounts. All business tables were empty; only the `migrations` ledger held
rows (23 entries dated 2026-07-17).

**These dumps matter.** The migration files that produced this schema no longer
exist — not on disk in `hc-tracking-old`, and not in its git history. This is
the only surviving record of how the domain was modelled.

The same content is also restored live as the `hc_tracking_old` and
`hc_tracking_test_old` schemas for convenient browsing.

To restore elsewhere:

```
mysql -u root -e "CREATE DATABASE scratch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root scratch < hc-tracking_20260904-121146.sql
```
