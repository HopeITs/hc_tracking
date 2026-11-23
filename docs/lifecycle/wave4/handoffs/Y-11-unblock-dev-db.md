# Y-11 / Y-12 / Y-13: bringing a database up to `c122856b`

For Dev 2. Done on Dev 1's local DB on 23 Nov 2026. Repeat it on any other database: your machine, staging, production.

## The block
`php artisan migrate` stops at `2026_11_20_122855_add_unique_slug_to_proposal_locations_table`:

```
Projects no location row carries (ids): …. Each needs a proposal first (D-84, D-87); nothing was changed.
```

This block is intended (D-80). Each project it names has no proposal, so no location row carries it. The code does not invent a row.

## The fix, in order

1. **See which projects are blocked.** Use the ids from the error message. To check whether a project has any proposal:
   ```sql
   SELECT p.id, p.name, p.status, p.start_date, p.end_date
   FROM projects p LEFT JOIN project_proposal pp ON pp.project_id = p.id
   WHERE pp.id IS NULL;
   ```
2. **Create one proposal per project.** If the project has no real donor, give the proposal the internal donor (D-87):
   - `donors.is_internal = 1`. On Dev 1's DB this is donor 1, "تمويل ذاتي — Hope".
   - `name` is the project's name. All four date columns (planned/actual begin and end) take the project's start and end dates. Set `status = approved` and `kind = project`.
   - `donor_proposal` needs `budget` (NOT NULL). Use `0` and `USD`.
   - Add a `project_proposal` link to the project.
   - Wrap it in `Activity::withoutRecording()` so it records no activity and sends no notifications.
3. **Create the location rows:** `php artisan proposals:backfill-locations --apply`
4. **Migrate:** `php artisan migrate`. The four remaining migrations now pass.
5. **Fill the rows from their projects:** `php artisan locations:backfill-from-projects --apply`
   The migration only does a dry run. If you skip this step, `verify-mirror` reports hundreds of differences: empty status, dates, targets and slugs.
6. **Move the tasks onto rows:** `php artisan tasks:row-backfill --apply`
   Without `--apply` the command is only a dry run.
7. **Check that nothing else is left:**
   ```
   php artisan risks:row-backfill --dry-run
   php artisan schedule:row-backfill --dry-run
   php artisan tasks:row-backfill --dry-run
   php artisan locations:verify-mirror
   ```
   It is done when every dry run reports 0 and `verify-mirror` says `Every location row matches its project`.

## What Dev 1's DB looked like
| Project | Proposal created | Number |
| --- | --- | --- |
| 20 DISC Villages (closed) | 44 | `HC-2025-920` |
| 36 Emergency Response Latakia (closed) | 45 | `HC-2025-936` |

The ids will probably differ on another database. The two numbers above are the agreed ones for these two projects.

Results:
- `proposals:backfill-locations` inserted 6 rows.
- `backfill-from-projects` filled 44 rows (36 active, 8 closed).
- 76 tasks moved onto rows.
- The risk and schedule backfills found nothing to move.
- `verify-mirror` passed: 36 of 36 projects.

## Production
Do not create these proposals there without Dev 1. Each one needs a human decision on the donor (D-84, D-87). Run steps 3–7 only after a backup.
