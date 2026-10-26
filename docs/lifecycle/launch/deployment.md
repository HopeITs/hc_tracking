# Production deployment checklist

> **DRAFT. The gate on 2026-10-26 was NO-GO. Team A reported the same day that production is fully deployed. Evidence pending: §1 "Deploy reported (2026-10-26)".**
> - Written 2026-10-26 (day 11, CP4) on `lifecycle/T29-deploy` from GitHub `main` `9b4d3db0` (`HopeITs/hc_tracking`).
> - Dev 1 approved drafting before T29's start state passes. T27 is still an early draft, and T28 has not run (§1).
> - Every **TBD** has an owner in [Appendix B](#appendix-b-open-items). Durations marked "local" come from T27's local rehearsal on a developer PC. They are **not** production rehearsal timings.
> - Next: collect the deploy evidence (§1), fill the deploy log (§7) from pasted outputs, and run the monitoring checks (§5) from T, the time of `up`. Remove this banner when the deploy log is complete.

The operator is **Dev 1** (backup Dev 2). T29 prepares this document and reads the outputs the operator pastes. It never connects to production, reads a secret or runs a step. Sources: [`runbook.md`](runbook.md) Part B and Part C, [`checks.sql`](checks.sql), [`rehearsal-report.md`](rehearsal-report.md).

| Field | Value |
|---|---|
| Deploy day | **Thu 29 Oct 2026** (day 14). Never on a Friday without monitoring. |
| Window | `<start>`–`<end>` Damascus time (`APP_TIMEZONE` Asia/Damascus). TBD |
| Total window | **TBD** = the sum of the step durations in §3 plus the smoke tests (§4), from T27's final rehearsal. Local reference only: migrations 57 / 57 in 5 s; restore 2 s. |
| Release | `<release-tag>` (the commit T28 tested). Previous release for rollback: `<previous-release>` |
| Variant | **full / phased**: decision D5, TBD |
| Topology | **(a) VPS:** Supervisor runs `queue:work` and `reverb:start`; cron runs `schedule:run` every minute. Q2, Team A, 2026-10-26. Option (b), Pusher with `QUEUE_CONNECTION=sync`, is not used. |
| File encryption | inside the window / after `up`: decision D4, TBD |
| Operator · backup | Dev 1 · Dev 2 |

**Placeholders.** None of these is ever written down with its real value in this file, a chat or a ticket.
- `<php>`: the host's PHP binary.
- `<app-path>`: the application folder.
- `<backup-dir>`: the backup folder.
- `<db>`: the tracking schema.
- `<app-url>`: the site URL.
- `<release-tag>`, `<previous-release>`: the releases.
- `<deployer-email>`: Dev 1's own HR account.
- `<bypass-secret>`: printed once by `down`. It stays in the operator's session only.

**How to read a step.** Each step gives:
- **Owner**;
- **Do**: the exact command, one action per step;
- **Expected**: what the screen should show;
- **Duration**: the rehearsal time, or "local" / TBD;
- **Stop if**: the condition that stops the deploy;
- **On failure → R-n**: the rollback step in §6 to jump to.

Paste every output into the deploy log (§7) as counts and ids only.

## Contents
1. [Go/No-Go gate](#1-gono-go-gate)
2. [T-2 days](#2-t-2-days)
3. [Deploy sequence](#3-deploy-sequence)
4. [Smoke tests](#4-smoke-tests)
5. [Monitoring (48 hours and month end)](#5-monitoring-48-hours-and-month-end)
6. [Rollback](#6-rollback)
7. [Templates: deploy log and monitoring report](#7-templates-deploy-log-and-monitoring-report)
8. [Close-out](#8-close-out)
- [Appendix A: announcements (en + ar)](#appendix-a-announcements-en--ar)
- [Appendix B: open items](#appendix-b-open-items)

---

## 1. Go/No-Go gate

Check every row on the morning of the deploy. **Any ❌ or ⏸ → No-Go. Do not start §3.**

| # | Gate item | Owner | Status 2026-10-26 | Evidence |
|---|---|---|---|---|
| 1 | Branch `lifecycle/T29-deploy` from `main` | T29 | ✅ | `9b4d3db0` |
| 2 | **T27** passed with no manual step; durations recorded; rollback tried; every blocking PRE is 0 or decided; the copy is deleted | Dev 1 | ❌ | `handoffs/T27.md` says "slice 1/2 early draft, pre-CP5". Gaps G2, G3, G4, G6, G7, G8 and G10 are open. `rehearsal-report.md` §1–7 are empty. |
| 3 | **T28** Go at CP6 (full or phased); 0 open Critical/High; UAT signed by MEAL, Funding and Finance; full suite green | Dev 1 + management | ❌ | No `handoffs/T28.md`, no `launch/qa-report.md`. CP6 is Wed 28 Oct. |
| 4 | Every runbook command and flag exists; the schedule shows `monthly-reports:prepare`, the daily scanner and `reports:monthly-digest` | T29 | ⚠ partial | Schedule ✅ (§3 D17). Commands: `annual-plans:ensure-2026` and `report-templates:prefill-active` are not registered (G2, G3). The other commands and flags match `--help` (2026-10-26). |
| 5 | Topology confirmed | Dev 1 | ✅ | (a) VPS + Supervisor + cron (Q2, Team A, 2026-10-26). The program names and the cron line are checked at T-2 (§2). |
| 6 | Coverage: deploy Thu 29 Oct; two people on call for 48 h; a **named** Team A on-call for Sat 31 Oct and Sun 1 Nov | Dev 1 | ⏸ TBD | §2 |
| 7 | Readiness tests green | T29 | ✅ | 19 files, 192 passed (2026-10-26, local); list in `handoffs/T29.md` |
| 8 | Final backup taken **and test-restored** | Dev 1 | ⏸ evidence pending | Deploy reported done (below); no backup time or restore result received |
| 9 | `FILE_ENCRYPTION_KEY` present, with two off-server copies verified by a second person | Dev 1 + 1 | ⏸ evidence pending | Deploy reported done (below); no confirmation received |
| 10 | Go recorded at CP6 | management | ⏸ evidence pending | No Go record received |

**Decision: NO-GO** (2026-10-26, T29 slice 1 draft). Reason: rows 2, 3 and 4. Re-evaluate at CP6.

| Role | Go / No-Go | Initials (role only) | Date, time |
|---|---|---|---|
| Dev 1 (operator) | | | |
| Management | | | |

### Deploy reported (2026-10-26)

Team A answered every open item with one line, relayed by the developer: «تم النشر على السيرفر كاملا» ("the deploy to the server is complete"). No output, time or commit came with it. It is recorded here **as reported, not verified**.

| Item | Status |
|---|---|
| Time of `up` (T) | pending. It starts the 48-hour monitoring (§5). |
| Deployed commit | **`96ea52be` on `lifecycle/T29-deploy`** (`git log -1` on the server, pasted 2026-10-26). Its code equals `main` `9b4d3db0`: the four commits after it change only `deployment.md` and `handoffs/T29.md`. Production runs a working branch; switch it to `main` once this branch is merged (no code change). |
| Gate evidence, rows 2, 3, 8, 9, 10 | pending |
| Step outputs D1–D20 | pending (deploy log, §7) |
| Full or phased (D5) | pending |

**T33 gap (High), confirmed on production 2026-10-26.** The developer opened `/report-templates` on the production site while signed in, and got the app's own 404 page (`errors.404_heading`, "This record isn't on the map"). The app runs, but the route is not registered, so T33 slice 3 is not deployed. Slice 4 comes after slice 3 and is very likely missing too; confirm with `<php> artisan list` on the server. Production therefore lacks T33 slices 3–4:
- **No `report-templates:prefill-active` (D11).** Projects already active at launch got no pending template, so their monthly reports have no indicator lines (D-45 not met).
- **No `/report-templates` page.** MEAL has no screen to confirm a template. `ConfirmReportTemplate` is the only way to call `ActivateProject` (§12.4), so a project approved after launch stays `approved`, never becomes `active` (D-04b, D-39) and gets no monthly reports. The approval listener still creates its pending template.
- **No `annual-plans:ensure-2026` (D10).** No 2026 annual plans were created for the org-wide indicators (D-44).

Fix: merge T33 slices 3–4, then a follow-up deploy that runs D10 and D11 (dry → real → again), POST-15 / POST-16 and smoke test S9. Until then, tell MEAL and the PR Manager that newly approved projects stay `approved` (no monthly reports yet).

**Checks to run now** (§5; they go into the monitoring report as C1):
- both off-server copies of the file key (§2);
- the pre-deploy backups kept;
- `sudo supervisorctl status`, and whether `queue:restart` ran after the deploy (D16);
- the `schedule:run` cron line (§2);
- M2: decryption failures;
- M4: `queue:failed`.

---

## 2. T-2 days

By Tue 27 Oct; at the latest at CP6 (Wed 28 Oct). Write "done" with the time and the role.

- [ ] **Announce** the window: send [A1](#a1-before-the-window). Owner: Dev 1 with management.
- [ ] **On-call:**
  - two people for 48 h from `up`: `<on-call 1>`, `<on-call 2>`;
  - named Team A on-call for Sat 31 Oct (month end) `<name or initials>` and Sun 1 Nov `<name or initials>`;
  - support channel `<support-contact>`.

  Owner: Dev 1. TBD.
- [ ] **VPS processes** (topology (a), Q2). Owner: Dev 1.
  - `sudo supervisorctl status` lists the queue worker and the Reverb program, both RUNNING. This document uses the names `hc-tracking-queue` and `hc-tracking-reverb` from `docs/superpowers/plans/2026-09-23-notifications.md`; if the server uses other names, write them here and use them in D16, M5, M8 and R5.
  - `crontab -l` for the web user shows `* * * * * cd <app-path> && <php> artisan schedule:run >> /dev/null 2>&1`. **No cron line → stop:** nothing scheduled would run (05:00 prepare, 06:00 scan, 08:00 digest).
- [ ] **Production `.env` checked by name.** Dev 1 reads the file on the server; nobody pastes or says a value. Each key is present with the expected setting:
  - `APP_ENV=production`
  - `APP_DEBUG=false`
  - `APP_URL` starts with `https://`
  - `SESSION_EXPIRE_ON_CLOSE=true`. The config defaults to `true` when the key is absent; it is missing from `.env.example` (Appendix B).
  - `REVERB_ALLOWED_ORIGINS` set to the site's origin, **never `*`**
  - `AUTH_TWO_FACTOR_ENFORCED` unchanged
  - `TRUSTED_PROXIES` (and `TRUSTED_PROXIES_REQUIRED`) unchanged
  - `DB_USERNAME` is **not** root (least-privilege account)
  - `MAIL_MAILER` and the `MAIL_*` keys set
  - `FILE_ENCRYPTION_KEY` and `FILE_ENCRYPTION_KEY_ID` present
  - `BROADCAST_CONNECTION=reverb` and `QUEUE_CONNECTION=database` (topology (a))
- [ ] **File encryption key** (D-19, T22). Owner: Dev 1.
  1. If production `.env` already has a `FILE_ENCRYPTION_KEY`, **do not generate another one**. A second key after files are encrypted means data loss.
  2. Otherwise generate it **once**, on the server terminal only, before the first encrypted write (that is, before D3): `<php> -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`. Put it in `FILE_ENCRYPTION_KEY`. Set `FILE_ENCRYPTION_KEY_ID` (empty = `k1`).
  3. Store two off-server copies in `<key-store-1>` and `<key-store-2>`.
  4. A second person checks that both copies match the server key without displaying it, by comparing SHA-256 fingerprints.
  5. **Never** run `key:generate`. It breaks `users.two_factor_*`, the `accounts` banking columns and every session. Rotating `APP_KEY` is `security:reencrypt` with `APP_PREVIOUS_KEYS`; it is not part of this deploy.
- [ ] **Disk:** free space ≥ 1.5 × the size of `storage/app/private` (PRE-17). Owner: Dev 1.
  - `du -sh <app-path>/storage/app/private`
  - `df -h <app-path>`
- [ ] **DB account** has ALTER, INDEX, REFERENCES and CREATE on `<db>` (`SHOW GRANTS FOR CURRENT_USER();`, read by Dev 1). Owner: Dev 1.
- [ ] **T27 decisions recorded** in `rehearsal-report.md` §7: D1 baseline, D2 prechecks, D3 APP_KEY, D4 encryption window, D5 full/phased. Also **G6** (each center-conflict pair, project 19) and **G7** (duplicated HR program codes 003, 004, 009 fixed). Owner: Dev 1 with management and HR.
- [ ] **Human data entry** (runbook §7):
  - the MEAL Manager enters the governorate and project officers in `/admin/cities` (D-12a);
  - an admin sets the default reviewer slots in `/admin/notification-settings`;
  - the PR Manager fills the `target_beneficiaries` list from `report-templates:prefill-active --dry-run` (runbook step 8a; TBD, G3);
  - Dev 1 decides each self-funded relinking id (POST-20).
- [ ] **Warn MEAL and the managers** that the first scans after `up` send many reminders (§5 "Expected first-day alerts").
- [ ] **Notification baseline B** for the flood rule (§5, Q6). Dev 1 runs this read-only query on production and records only the top count: `SELECT DATE(created_at) AS day, notifiable_id AS user_id, COUNT(*) AS n FROM notifications WHERE created_at >= CURDATE() - INTERVAL 14 DAY GROUP BY day, user_id ORDER BY n DESC LIMIT 5;`. B = the highest `n` (0 if there are no rows).
- [ ] **Release tags:** `<release-tag>` is cut from the commit T28 tested; `<previous-release>` is recorded for R3.
- [ ] **Agreed test record** for the new-upload smoke test S22: `<agreed-record>`, or "none" (then watch the first real upload).

---

## 3. Deploy sequence

The order is T27 Part B, as the T29 prompt sets it. Failure routing:
- fail at D1–D2 (nothing changed yet) → **R0**;
- fail at D3–D4 (code changed, database untouched) → **R3**;
- fail at D5 or later (database written) → **R1**.

**Never re-run a failed step blindly.** Scheduled tasks and queued jobs do not run while the site is down. No entry uses `evenInMaintenanceMode`, so the 03:00 `files:prune`, 05:00 `monthly-reports:prepare`, 06:00 scan and 08:00 digest are skipped if the window covers them.

### D1 · Maintenance mode on
- **Owner:** Dev 1 · **Duration:** seconds (TBD) · **On failure → R0**
- **Do:** `<php> artisan down --render="errors::503" --retry=60 --with-secret`
- **Expected:**
  - `Application is now in maintenance mode.`
  - `You may bypass maintenance mode via [<app-url>/<bypass-secret>].` Keep the secret in the session only.
  - `<app-url>` shows the 503 page.
- **Stop if:** the 503 page does not show.

### D2 · Final backups, after `down` ⛁
- **Owner:** Dev 1 · **Duration:** TBD (T27; grows with the DB and `storage/app/private`) · **On failure → R0**
- **Do:**
  1. `mysqldump --single-transaction --routines --triggers <db> > <backup-dir>/<db>-predeploy.sql` (or `mariadb-dump`)
  2. `tar -czf <backup-dir>/private-predeploy.tgz -C <app-path>/storage/app private`
  3. Copy `.env` to secure storage.
  4. Restore the dump into a scratch schema and run PRE-20 (`checks.sql`) on both schemas.
- **Expected:** the three backups exist; PRE-20 counts are equal on the scratch schema and on `<db>`. Write the backup time in the deploy log: it starts the data-loss window (§6).
- **Stop if:** a backup fails or the restore counts differ. **No deploy without a restore-verified backup.**

### D3 · Release code
- **Owner:** Dev 1 · **Duration:** TBD · **On failure → R3**
- **Do:**
  1. `git fetch --tags`, then `git checkout <release-tag>`
  2. `composer install --no-dev --optimize-autoloader`
  3. `npm ci`, then `npm run build`. `public/build` is not in git, so it must be built here (or copied from a build of the same tag).
- **Expected:** each command exits 0; `public/build/manifest.json` exists.
- **Note:** `laravel/pail` is a dev dependency and is gone after `--no-dev`. Use `tail` (§5).
- **Stop if:** any command fails.

### D4 · Clear caches
- **Owner:** Dev 1 · **Duration:** seconds · **On failure → R3**
- **Do:** `<php> artisan optimize:clear`
- **Expected:** every cache line `DONE`.
- **Stop if:** an error is printed.

### D5 · Permissions (T02; D-09a, D-23, D-31b) ⛁
- **Owner:** Dev 1 · **Duration:** seconds (local) · **On failure → R1**
- **Do:**
  1. `<php> artisan permissions:sync --dry-run`
  2. `<php> artisan permissions:sync`
  3. the same again
- **Expected:**
  - only `Permission added: …`, `Role added: …` and `<role> gains: …` lines;
  - the dry run ends with `Dry run: nothing was changed.`;
  - the last run prints `Permissions are already up to date.`;
  - no role → permission pair removed (diff PRE-18b).
- **Stop if:** a pair was removed. **Never** run `RolesAndPermissionsSeeder` or `db:seed`.

### D6 · Migrations ⛁
- **Owner:** Dev 1 · **Duration:** local 5 s for 57 migrations; production TBD (T27) · **On failure → R1**
- **Do:** `<php> artisan migrate --force --step`. The `--step` flag is used as in the rehearsal. **Never** use `--graceful`, `migrate:fresh` or `migrate:refresh`.
- **Expected:**
  - every migration (the CP5 inventory, 57 on `main` today) prints `DONE`;
  - #13 (`final reports` → `active`) and #15 (retired `submitted`) print counts equal to the rehearsal;
  - then POST-01…POST-11 (`checks.sql`) equal rehearsal invariants 3a–3d: POST-01 0, POST-02 0, POST-03 4, POST-04 1, POST-05 0 / PRE-06, POST-06 0 / 0 / 0, POST-07 0, POST-08 6 / 5 / 6, POST-09 0 / 0 / 0, POST-10 0 / 0.
- **Stop if:** any migration fails, or a POST value differs. Record the ids and go to R1.

### D7 · HR centers (T30; D-30a) ⛁
- **Owner:** Dev 1 · **Duration:** seconds (local) · **On failure → R1**
- **Do:**
  1. `<php> artisan hr:map-project-centers --dry-run`
  2. Compare it with the matching management approved (D-30c) and its **Conflicts** list with PRE-07.
  3. `<php> artisan hr:map-project-centers --apply`
  4. The same `--apply` again.
- **Expected:**
  - the dry run equals the approved matching;
  - every conflict has a recorded decision (G6);
  - the second apply updates 0;
  - POST-12a = the accepted unmapped list; POST-12b = 0.
- **Stop if:** the dry run differs from the approval, or a conflict has no decision.

### D8 · Strategy pillars, then the Micro framework (T32) ⛁, before D9
- **Owner:** Dev 1 · **Duration:** seconds each (local) · **On failure → R1**
- **Do:** each command dry, then real, then again:
  1. `<php> artisan strategy:import-pillars database/seeders/data/strategy-2026-2030.json --strategy=<id> --dry-run`
  2. `<php> artisan meal:import-framework database/seeders/data/logframes/micro-urban.json --program=<hr id> --dry-run`
  3. `<php> artisan meal:import-framework database/seeders/data/logframes/micro-agri.json --program=<hr id> --dry-run`

  Run urban before agri.
- **Expected:**
  - each dry run ends with `Dry run: nothing was changed.`;
  - the real runs print the created / updated / conflicts the rehearsal printed;
  - the second runs change nothing;
  - POST-13 matches.
- **Stop if:** the conflicts differ from `handoffs/T32.md`, or MEAL has not approved the T32 data (G8).

### D9 · Indicator Library (T11) ⛁
- **Owner:** Dev 1 · **Duration:** seconds (local) · **On failure → R1**
- **Do:**
  1. `<php> artisan meal:seed-indicator-library --dry-run`
  2. the same without `--dry-run`
  3. again
- **Expected:**
  - the dry run lists the codes MEAL reviewed;
  - the last run prints `Every indicator is already in the library.`;
  - POST-14 = 0.
- **Stop if:** it stops on a duplicated HR program code (G7).

### D10 · 2026 annual plans (T33) ⛁: **TBD (G2)**
- **Owner:** Dev 1 · **Duration:** TBD · **On failure → R1**
- **Do:** `<php> artisan annual-plans:ensure-2026 --dry-run`, then real, then again.
- **Expected:** POST-15 = 0 / 0; other plans unchanged.
- **Stop if:** the command is still not registered at CP6. That is a No-Go, not a skip.

### D11 · Report templates for already-active projects (T33; D-45) ⛁: **TBD (G3)**
- **Owner:** Dev 1 · **Duration:** TBD · **On failure → R1**
- **Do:** `<php> artisan report-templates:prefill-active --dry-run`, then real, then again.
- **Expected:**
  - POST-16 = 0 / 0;
  - project statuses unchanged (active projects stay active);
  - the first real run adds `report_template.created` activities and `report_template.pending` notifications by design, and the second adds none (PRE-20b);
  - the list of lines missing `fixed_denominator` is empty or accepted (§2).
- **Stop if:** a project status changed.

### D12 · Monthly report backfill (T17A; D-29) ⛁
- **Owner:** Dev 1 · **Duration:** TBD (local: not timed) · **On failure → R1**
- **Do:**
  1. `<php> artisan monthly-reports:backfill --from=2026-10 --dry-run`
  2. the same without `--dry-run`
  3. again
- **Expected:**
  - one row per active project: `Project <id>: <n> months created, <n> already there, <n> lines built.`;
  - the dry run ends with `Dry run: nothing was written.`;
  - the second real run creates 0;
  - POST-17 = a 0, b 0, c = PRE-27a `expected_months`, d 0.
  - The backfill sends **no** notification: generation runs under `Activity::withoutRecording`. The grouped `monthly_reports.generated` comes only from projects activated later (Q1: Team A confirmed the code's behaviour, 2026-10-26).
- **Stop if:** POST-17 a, b or d is not 0.

### D13 · Encrypt existing files (T22; D-19) ⛁, inside the window or after `up` (D4)
- **Owner:** Dev 1 · **Duration:** TBD (T27 step 10 on production-size data; locally 0 files) · **On failure → R1** if decryption errors are systematic (§6); otherwise re-run
- **Do:**
  1. Check free disk again (§2).
  2. `<php> artisan files:encrypt-existing --dry-run`
  3. `<php> artisan files:encrypt-existing`. It has no `--force` and asks `Are you sure you want to run this command?`: type `yes`.
  4. `--dry-run` again.
- **Expected:**
  - per table: `N to encrypt|encrypted, N already encrypted, N missing on disk, N skipped`;
  - the last dry run shows `0 to encrypt` in every table;
  - no `*.enc.tmp` left in `storage/app/private`;
  - POST-18 = 0 except the rows reported missing (reference-only rows are counted, never encrypted).
- **If it stops:** re-run it. It is resumable and skips rows that already have a key id.
- **If deferred after `up`:** run it in a quiet window, never across 03:00 (`files:prune`). Existing files stay plaintext and keep downloading meanwhile; every new upload is encrypted.
- **Stop if:** errors repeat across files or one key id. That is a rollback trigger (§6).

### D14 · POST checks
- **Owner:** Dev 1 runs, T29 interprets the pasted counts · **Duration:** minutes · **On failure → R1**
- **Do:** run every POST check in `checks.sql`, plus PRE-20 and PRE-20b. Paste counts and ids only.
- **Expected:** values as in the final rehearsal; untouched tables equal PRE-20 (POST-19 list).
- **Stop if:** a BLOCKING POST check differs.

### D15 · Cache for production
- **Owner:** Dev 1 · **Duration:** seconds · **On failure → R1**
- **Do:** `<php> artisan optimize`
- **Expected:** config, events, routes and views cached (`DONE`).

### D16 · Restart workers
- **Owner:** Dev 1 · **Duration:** seconds · **On failure → R1**
- **Do:**
  1. `<php> artisan queue:restart`. The workers finish their current job, exit, and Supervisor starts them on the new code.
  2. `sudo supervisorctl restart hc-tracking-reverb` (or `<php> artisan reverb:restart`; Supervisor starts it again).
- **Expected:** `sudo supervisorctl status` shows `hc-tracking-reverb` and `hc-tracking-queue:*` RUNNING.
- **Stop if:** a program is not RUNNING. Workers left on the old code would fail on the new tables.

### D17 · Schedule
- **Owner:** Dev 1 · **Duration:** seconds · **On failure → R1**
- **Do:** `<php> artisan schedule:list`
- **Expected:** these 9 entries (as on `main`, 2026-10-26):
  ```
  * * * * *     notifications:wake-snoozed
  */15 * * * *  notifications:scan frequent
  0 6 * * *     notifications:scan daily
  * * * * *     notifications:digests
  0 8 * * *     reports:monthly-digest        (withoutOverlapping, onOneServer)
  0 5 * * *     monthly-reports:prepare       (withoutOverlapping, onOneServer)
  0 0 * * *     model:prune (Activity, TrustedDevice, UserNotification)
  0 3 * * *     files:prune
  0 * * * *     cache:prune-expired
  ```
  The cron line `* * * * * cd <app-path> && <php> artisan schedule:run >> /dev/null 2>&1` exists for the web user (§2).
- **Stop if:** an entry is missing or extra. **Never** schedule `files:encrypt-existing`.

### D18 · Phased release only: withheld permissions
- **Owner:** Dev 1 · **Duration:** minutes · **On failure → R1**
- **Do:** D5 grants new permissions to their baseline roles. For each module D5 (the decision) withholds, remove its permissions from every role in **Admin > Users > Roles**, then confirm no role holds them. The withheld list is TBD at CP6, from `contracts.md §8`.
- **Expected:** the withheld permissions are held by no role except Super Admin, which holds everything technically (D-20a).
- **Later:** grant them in **Admin > Users**. No second deploy: a later `permissions:sync` adds only permissions that do not exist yet.
- Full release: skip this step.

### D19 · Smoke tests through the bypass
- **Owner:** Dev 1 · **Duration:** TBD (T28 timing) · **On failure → R1** if it hits a §6 trigger; otherwise log a defect
- **Do:** open `<app-url>/<bypass-secret>`, then run §4.
- **Expected:** every §4 row passes.

### D20 · Live
- **Owner:** Dev 1 · **Duration:** seconds
- **Do:** `<php> artisan up`
- **Expected:** `Application is now live.` Send [A2](#a2-after-up). Start the clock for §5 (T = this time).

---

## 4. Smoke tests

These are read-only, except S22 and S23. No business records are created. No amounts appear in shared screenshots. Check each page in **Arabic and English, light and dark**. Phased: skip the rows of withheld modules and write "skipped (phased)".

| # | Check | Expected |
|---|---|---|
| S1 | `<php> artisan about` | Environment `production`, Debug Mode `OFF`, config/events/routes/views `CACHED` |
| S2 | `<php> artisan migrate:status` | every migration `Ran`; none pending |
| S3 | Log in with the deployer's own HR account (`<deployer-email>`), with 2FA | Home opens. **If HR is unreachable:** login fails (`SyncProgramsFromHr` reports it). Postpone S3–S22 and stay down, or announce the delay. Migrations are not affected. |
| S4 | Home (`/`) | loads; the sidebar shows Indicator Library, Monthly Reports, Monthly Matrix, My Reporting Duties |
| S5 | One project | HR center, sectors and status badge shown |
| S6 | One proposal: tabs **Reviewers**, **Logical Framework**, **Indicators & Targets**, **Plan** | each tab loads |
| S7 | The same proposal: **Change Status** | the dialog opens; press **Cancel**. **Never choose `approved`.** |
| S8 | `/indicator-library` | list loads with codes |
| S9 | `/report-templates` | **TBD:** the route is not on `main` (T33 slice 3). Expected: pending templates of the already-active projects (D11). |
| S10 | `/monthly-reports` | the months created by D12 are listed |
| S11 | `/reports/monthly-matrix` | loads (validated figures only; empty is normal on day 1) |
| S12 | `/department-tasks` | loads, no amounts |
| S13 | `/donor-reports/compose` | loads |
| S14 | `/reporting-duties` (**My Reporting Duties**) | loads |
| S15 | `/funding-agreements` (open one agreement), `/transfers` | load |
| S16 | `/admin/cities` | MEAL coverage officers shown (§2) |
| S17 | `/admin/users`, **Supervision** tab | loads |
| S18 | `/admin/notification-settings` | loads, with the default reviewer slots set |
| S19 | `/admin/sectors` | the 6 sectors listed |
| S20 | `/executive-summary`, `/results`, `/indicators`, `/targets`, `/reports` | each loads |
| S21 | Download **and** preview one old (now encrypted) file each from a project, a proposal, a task and a report | each opens. `grep -c 'Stored file could not be read.' <app-path>/storage/logs/laravel*.log` is unchanged. |
| S22 | New upload on `<agreed-record>` only: upload, download, then delete it | works. If no record was agreed, skip and watch the first real upload at C1–C2. |
| S23 | `<php> artisan notifications:test <deployer-email>` (keep a page open in the browser) | `Test notification sent to …` (do not paste the name); a toast and a desktop popup appear; `SELECT COUNT(*) FROM failed_jobs` unchanged |

---

## 5. Monitoring (48 hours and month end)

T = the time of D20. Each checkpoint runs every check M1–M11 and fills one row of the monitoring report (§7).

| Checkpoint | When | Owner |
|---|---|---|
| C1 | T + 15 min | Dev 1 |
| C2 | T + 1 h | Dev 1 |
| C3 | T + 4 h | Dev 2 |
| C4 | End of day 14 (Thu 29 Oct) | Dev 1 |
| C5 | Day 15 morning (Fri 30 Oct), after the 06:00 scan | on-call |
| C6 | T + 48 h (Sat 31 Oct, month end) | named Team A on-call |
| C7 | Sun 1 Nov | named Team A on-call |
| C8 | **Mon 2 Nov** after 08:00, the first working day of November: 05:00 `monthly-reports:prepare`, 06:00 daily scan, 08:00 monthly digest | Dev 1 |

Sat and Sun are non-working days (Mon–Fri default, D-22), so the scanners send nothing on them. C6 and C7 watch errors, the queue, disk and user reports.

**Checks.** `<since>` = the previous checkpoint's time. Paste counts and ids only. Never paste a name, email, path, file name or amount from a log line.

| # | What | Command | Expected / act when |
|---|---|---|---|
| M1 | Errors | `grep -cE '\.(ERROR\|CRITICAL\|ALERT\|EMERGENCY):' <app-path>/storage/logs/laravel*.log`, then `tail -n 200 <app-path>/storage/logs/laravel*.log` for the latest messages | No new CRITICAL/ALERT/EMERGENCY. Each new ERROR is classified in the report. |
| M2 | Decryption failures (`app/Exceptions/UnreadableStoredFile.php`) | `grep 'Stored file could not be read.' <app-path>/storage/logs/laravel*.log \| tail -n 20`. Each line carries only `table`, `id`, `key_id` (`contracts.md §16`). | 0 new. Several files, one shared `key_id`, or any file uploaded after `up` → **rollback trigger** (§6). |
| M3 | Key-ring refused (`app/Support/FileKeyring.php`) | `grep -c 'File encryption is not configured.' <app-path>/storage/logs/laravel*.log` | 0. Any → uploads are refused: Critical. Check `FILE_ENCRYPTION_KEY*` by name (§2). |
| M4 | Failed jobs | `<php> artisan queue:failed` | No new rows. Record the job class only. |
| M5 | Queue backlog | `SELECT queue, COUNT(*) AS waiting, FROM_UNIXTIME(MIN(available_at)) AS oldest FROM jobs WHERE reserved_at IS NULL GROUP BY queue;` | Oldest waiting job < 5 min. Otherwise check `sudo supervisorctl status hc-tracking-queue:*` and `tail -n 50 <app-path>/storage/logs/queue.log`. |
| M6 | Scheduler activity | `SELECT rule, COUNT(*) AS fired, MAX(fired_at) AS last FROM notification_alert_ledger WHERE fired_at >= '<since>' GROUP BY rule ORDER BY rule;` | New rows after each 06:00 scan on working days. The ledger stops duplicates, so a re-scan adds no rows. |
| M7 | Notification flood | Per key: `SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.key')) AS message_key, COUNT(*) AS n FROM notifications WHERE created_at >= '<since>' GROUP BY message_key ORDER BY n DESC;` Per recipient: `SELECT notifiable_id AS user_id, COUNT(*) AS n FROM notifications WHERE created_at >= '<since>' GROUP BY notifiable_id ORDER BY n DESC LIMIT 10;` | Apply the flood rule below (Q6). Compare with "Expected first-day alerts". |
| M8 | Real-time (Reverb) | `sudo supervisorctl status hc-tracking-reverb`, `tail -n 50 <app-path>/storage/logs/reverb.log` | RUNNING, no errors; a toast arrives without a page refresh |
| M9 | Disk | `df -h <app-path>`; `du -sh <app-path>/storage/app/private <app-path>/storage/logs` | ≥ 20 % free; logs not growing fast |
| M10 | Key-page timings | Open Home, one proposal, `/monthly-reports` and `/reports/monthly-matrix`; time each page | Within the T28 baseline (TBD) |
| M11 | User reports | `<support-contact>` | Each report classified (severity, §7) |

**Flood rule (Q6).** `config/notifications.php` has no flood limit. Team A asked for the most suitable rule given the app's own defaults (2026-10-26). The rule below is that recommendation; revisit it at C5 with the first day's real numbers. It is built on what the code already guarantees:
- **The ledger** (`notification_alert_ledger`, unique on rule, subject, stage and user) sends a scheduled alert at most once per item, stage and person. The reminder stages are spaced over days: due soon at 7, 3 and 1 working days; overdue every 3 working days.
- **The grouper** merges a repeat into the latest unread row with the same group key within `grouping_window_minutes` = 5.
- **Digest keys** reach MEAL and General Management only in the one daily oversight summary.

So a same-day duplicate of a scheduled alert is a defect, while a high count of distinct items may be normal.

1. **Duplicates (defect).** Expected: 0 rows.
   ```sql
   SELECT notifiable_id AS user_id, JSON_UNQUOTE(JSON_EXTRACT(data, '$.key')) AS message_key, subject_type, subject_id, DATE(created_at) AS day,
          SUM(COALESCE(CAST(JSON_EXTRACT(data, '$.count') AS UNSIGNED), 1)) AS deliveries
   FROM notifications
   WHERE created_at >= '<since>' AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.key')) LIKE 'notifications.alerts.%'
   GROUP BY user_id, message_key, subject_type, subject_id, day
   HAVING deliveries > 1;
   ```
   Any row → check that subject's ledger rows. A true repeat is **High**.
2. **Volume per recipient and working day.** Leave out D11's one-off `report_template.pending` (its expected count is the number of templates D11 created). Flag a user above:
   - first 24 h after `up`: **max(30, 3 × B)**, because the first scans catch up on everything already due;
   - from day 2: **max(20, 2 × B)**.

   B is the T-2 baseline (§2). The floors cover a quiet production where B is near 0. Oversight is new in this release, so MEAL and General Management users get more than their baseline: a flag means **review**, not failure.
3. **When flagged:** find the key with the M7 per-key query filtered on that `notifiable_id`.
   - If it is real movements or distinct items, record it as expected.
   - If it repeats one key, it is **High**: switch that key off in `/admin/notification-settings` (Event Notifications), write the time in the monitoring report, fix it, then switch it back on. A disabled key sends nothing, direct recipients included.
   - A flood is never a rollback trigger on its own.

**At C4 only:** POST-18 (`checks.sql`) = 0, except the rows `files:encrypt-existing` reported missing. This is the acceptance check for encryption.

**At C8 only:**
- Lines built for November: `SELECT COUNT(DISTINCT r.id) AS reports, COUNT(l.id) AS line_rows FROM monthly_reports r LEFT JOIN monthly_report_lines l ON l.monthly_report_id = r.id WHERE r.period = '2026-11-01';`. Lines > 0 only for projects whose template MEAL confirmed.
- Monthly digest: `SELECT COUNT(*) FROM notification_alert_ledger WHERE rule = 'monthly_digest' AND stage = '2026-10';` > 0. The mail is queued, so it needs the worker (M5).

**Expected first-day alerts.** These are normal, and the ledger stops duplicates:
- monthly report due;
- department tasks;
- donor-report owners;
- project start / end;
- templates to confirm (`report_template.pending`, after D11);
- the grouped `monthly_reports.generated` for projects **activated after `up`** (the backfill sends none; Q1, confirmed);
- the oversight digest to MEAL and General Management.

Mail failures still leave the in-app notification. The queue retries a failed mail 3 times, then it appears in `queue:failed` (M4).

**Acceptance (prompt):** the system works; 0 critical errors in 48 h; POST-18 as above.

---

## 6. Rollback

Restore is the production rollback. **Never `migrate:rollback`** in production: it is refused there (G10), and several `down()` methods cannot undo data (runbook Part C). Decision owner: **Dev 1 with management**.

### Triggers

| Trigger | Threshold |
|---|---|
| A migration fails (D6) | any failure that is not a recorded, decided PRE case. Stop; never re-run blindly. |
| Systematic decryption errors (M2) | the same failure across several files (≥ `<n>`, TBD by Dev 1 after the rehearsal), all failures on one `key_id`, **or** any failure on a file uploaded after `up` |
| The approval path breaks | `ApproveProposal` errors (M1) or leaves a wrong proposal or project status (user report, M11) |

**Cut-off (Q4: Team A agreed 2026-10-26; management confirms in the §1 sign-off):** restore is the default until the end of day 14 (Thu 29 Oct). After that, a **forward fix** is the default, unless a trigger puts data at risk: new uploads that cannot be decrypted, or approvals writing a wrong status.

**Data-loss window:** the D2 backup is taken after `down`, so a rollback **before D20** loses nothing (the bypass session writes only S22's test upload, which is deleted). A rollback **after D20** loses every change users made between `up` and R1. Tell them with A3.

### Steps

| # | Do | Expected |
|---|---|---|
| R0 | Nothing changed yet (failure at D1–D2): `<php> artisan up` | `Application is now live.` Announce the new date. |
| R1 | `<php> artisan down --render="errors::503" --retry=60 --with-secret` | `Application is now in maintenance mode.` (or `Maintenance mode options updated.` if already down) |
| R2 | Restore the database **and** `storage/app/private` **together**, from the D2 backup point. Post-deploy rows carry `encryption_key_id`; the old code would serve ciphertext if only one were restored. Steps: 1. Move the current folder aside: `mv <app-path>/storage/app/private <backup-dir>/private-failed-<release-tag>` (kept for investigation). 2. `tar -xzf <backup-dir>/private-predeploy.tgz -C <app-path>/storage/app`. 3. Restore `<backup-dir>/<db>-predeploy.sql` into an **empty** schema: the release created tables the dump does not drop. Use T27's step 13.1 method (TBD, Appendix B). 4. Restore `.env` from D2 only if it changed during the deploy. | PRE-20 counts equal the D2 values |
| R3 | Previous release: `git checkout <previous-release>`, then `composer install --no-dev --optimize-autoloader`, then `npm ci` and `npm run build` | each exits 0 |
| R4 | `<php> artisan optimize` | `DONE` |
| R5 | `<php> artisan queue:restart`, then `sudo supervisorctl restart hc-tracking-reverb` (or `<php> artisan reverb:restart`) | `sudo supervisorctl status` shows both programs RUNNING |
| R6 | `<php> artisan up` | `Application is now live.` |
| R7 | Verify: PRE-20 counts = D2; one file downloads; one login works. Send A3. | all three pass |

**One unreadable file, no systematic failure:** not a rollback. Dev 1 can extract that file with `<php> artisan files:decrypt <table> --id=<id> --to=<path outside the upload folders and public/>`. The table is one of `media`, `report_files`, `task_attachments` or `monthly_report_files`; the code accepts the fourth although `--help` omits it. Delete the extracted copy afterwards.

**Message (A3, from T27):**
- en: "We restored the system to its state before the update. Changes made after `<time>` need to be re-entered."
- ar: «أعدنا النظام إلى حالته قبل التحديث. يلزم إعادة إدخال التغييرات التي تمت بعد `<time>`.»

---

## 7. Templates: deploy log and monitoring report

Filled during the deploy and the 48 hours (T29 slice 2), from pasted outputs. Counts and ids only.

### Deploy log

**Status 2026-10-26:** Team A reported the deploy as done (§1 "Deploy reported"). No output has been received yet. The rows stay empty until the outputs are pasted; never fill a row from memory.

| Step | Started | Ended | Output summary (counts / ids) | Operator (role) | Result |
|---|---|---|---|---|---|
| §1 gate | — | — | No-Go on 2026-10-26 (rows 2, 3, 4); the deploy went ahead per Team A's report | — | reported |
| D1 `down` | | | | | |
| D2 backups + test restore | | | backup time: | | |
| D3 code, composer, npm | | | | | |
| D4 `optimize:clear` | | | | | |
| D5 `permissions:sync` dry / real / again | | | | | |
| D6 `migrate --force --step` + POST-01…11 | | | Original output not received. Post-deploy `migrate:status`: every migration `Ran` (pasted 2026-10-26). POST-01…11 pending | | pass (post-check) |
| D7 `hr:map-project-centers` dry / apply / apply | | | | | |
| D8 pillars, Micro urban, Micro agri | | | | | |
| D9 `meal:seed-indicator-library` | | | | | |
| D10 `annual-plans:ensure-2026` | | | Command absent on `9b4d3db0`: not run, unless production runs a newer commit (T33 gap, §1) | | pending |
| D11 `report-templates:prefill-active` | | | Command absent on `9b4d3db0`: not run, unless production runs a newer commit (T33 gap, §1) | | pending |
| D12 `monthly-reports:backfill --from=2026-10` | | | | | |
| D13 `files:encrypt-existing` (or D4 deferral) | | | | | |
| D14 POST checks, PRE-20, PRE-20b | | | | | |
| D15 `optimize` | | | | | |
| D16 restarts | | | | | |
| D17 `schedule:list` | | | | | |
| D18 phased permissions | | | | | |
| D19 smoke tests S1–S23 | | | | | |
| D20 `up` (T) | | | Reported done by Team A (2026-10-26); the time of `up` is pending | | reported, not verified |

### Monitoring report

Severity:
- **Critical** = a §6 trigger, or data at risk;
- **High** = a feature broken for a role, with no workaround;
- **Medium** = a workaround exists;
- **Low** = cosmetic.

T is pending (§1 "Deploy reported"), so the checkpoint times are set once Team A gives it. Checks run before then go into C1 with their actual time. The T33 gap is already a High finding to carry into C1.

| Checkpoint | Time | Checked by (role) | Findings (M1–M11) | Actions | Severity |
|---|---|---|---|---|---|
| C1 T + 15 min | 2026-10-26 (pasted checks) | developer, Team A | **Confirmed:** `/report-templates` returns the app's 404 on production, so T33 slices 3–4 are not deployed (§1); no template confirmation screen, no templates for already-active projects, no 2026 annual plans. Commit `96ea52be` (code = `main` `9b4d3db0`). `migrate:status`: all `Ran`. M2 decryption failures: 0. M4 `queue:failed`: empty. Not yet checked: file-key copies and backups, `supervisorctl status`, `queue:restart`, cron, M1 errors, pages S4–S21 | T33 slices 3–4, then a follow-up deploy (D10, D11, S9); warn MEAL and the PR Manager; send the unchecked items | High (T33 gap); the rest pass |
| C2 T + 1 h | | | | | |
| C3 T + 4 h | | | | | |
| C4 end of day 14 (+ POST-18) | | | | | |
| C5 day 15 morning | | | | | |
| C6 T + 48 h (Sat 31 Oct) | | | | | |
| C7 Sun 1 Nov | | | | | |
| C8 Mon 2 Nov (+ November lines, digest) | | | | | |

---

## 8. Close-out

- [ ] Minor defects (Medium, Low) moved to the maintenance list, each with its owner.
- [ ] Backups (D2 dump, storage archive, `.env` copy) **kept** until monitoring ends and management signs off. Retention date: `<date>`.
- [ ] Both off-server `FILE_ENCRYPTION_KEY` copies confirmed again by the second person. **Never delete them.**
- [ ] T27's rehearsal copy, its backups and its throwaway key confirmed deleted (`rehearsal-report.md` §6).
- [ ] Deploy log and monitoring report complete; `handoffs/T29.md` closed (T29 slice 2).
- [ ] Management sign-off: `<role, date>`.

---

## Appendix A: announcements (en + ar)

Sent by Dev 1 or management. The values in `<…>` are filled at CP6. Ask Dev 1 where users read the guide; the repository copies are `docs/user-guide.md` and `docs/user-guide.ar.md`.

### A1: before the window

**English**

> **Subject:** HC Tracking update on Thursday 29 October, `<start>`–`<end>`
>
> Hello everyone,
>
> HC Tracking will be unavailable on **Thursday 29 October from `<start>` to about `<end>`** (Damascus time) while we install a major update. Please save your work and close the system before `<start>`.
>
> **What changes**
> - Every project now belongs to an HR center and a program. A new project starts as a draft, without dates.
> - Each proposal goes through clear stages: the concept note (drafted, reviewed, sent to the donor), then the proposal (drafted, reviewed, submitted). The proposal holds its plan: logical framework, indicators and targets, milestones and department tasks.
> - Reviewers are invited automatically at each review stage, and you can see who has looked at it.
> - The donor's approval sets the project's real dates. The project becomes active once MEAL confirms its monthly report template.
> - **Indicator Library:** one shared list of indicators, each with its own code.
> - **Monthly reports** are created automatically for active projects: the center manager submits, MEAL validates, and the **Monthly Matrix** shows the validated figures.
> - Donor reports are put together from validated monthly reports.
> - Media and Finance receive their work as department tasks.
> - MEAL and General Management receive notifications about project, proposal and report movements.
> - Uploaded files are now stored encrypted. Uploading and downloading work as before.
>
> **After the update**
> - Please refresh any page you left open. If a page shows an error or asks you to reload, reload it.
> - During the first days you may receive several reminders at once (monthly reports due, templates to confirm, department tasks). This is expected and settles quickly.
> - User guide: `<user-guide-link>`
> - Questions or problems: `<support-contact>`

**العربية**

> **الموضوع:** تحديث نظام HC Tracking يوم الخميس 29 تشرين الأول، من `<start>` إلى `<end>`
>
> مرحبًا بالجميع،
>
> سيتوقف نظام HC Tracking **يوم الخميس 29 تشرين الأول من الساعة `<start>` حتى نحو `<end>`** (بتوقيت دمشق) لتثبيت تحديث كبير. يُرجى حفظ عملكم وإغلاق النظام قبل الساعة `<start>`.
>
> **ما الذي تغيّر**
> - أصبح كل مشروع مرتبطًا بمركز وبرنامج من نظام الموارد البشرية، ويبدأ المشروع الجديد مسودةً بلا تواريخ.
> - يمرّ كل مقترح بمراحل واضحة: المذكرة المفاهيمية (إعداد، فمراجعة، فإرسال إلى المانح)، ثم المقترح (إعداد، فمراجعة، فتقديم). ويضمّ المقترح خطته: الإطار المنطقي، والمؤشرات والمستهدفات، والمراحل الرئيسية، ومهام الأقسام.
> - يُدعى المراجعون تلقائيًا في كل مرحلة مراجعة، ويمكنكم معرفة من اطّلع منهم.
> - تُحدِّد موافقة المانح التواريخ الفعلية للمشروع، ويصبح المشروع نشطًا بعد أن يعتمد قسم المتابعة والتقييم قالب تقريره الشهري.
> - **مكتبة المؤشرات:** قائمة موحّدة للمؤشرات، لكل مؤشر رمزه الخاص.
> - **التقارير الشهرية** تُنشأ تلقائيًا للمشاريع النشطة: يرسلها مدير المركز، ويعتمدها قسم المتابعة والتقييم، وتعرض **المصفوفة الشهرية** الأرقام المعتمدة.
> - تُجمَّع تقارير المانحين من التقارير الشهرية المعتمدة.
> - تصل أعمال فريقي الإعلام والمالية على شكل مهام أقسام.
> - تصل إلى قسم المتابعة والتقييم والإدارة العامة إشعارات بتحرّكات المشاريع والمقترحات والتقارير.
> - تُحفظ الملفات المرفوعة الآن مشفّرة، ويبقى رفعها وتنزيلها كما كان.
>
> **بعد التحديث**
> - يُرجى إعادة تحميل أي صفحة تركتموها مفتوحة. وإذا ظهر خطأ في صفحة أو طُلب منكم إعادة تحميلها، فأعيدوا تحميلها.
> - قد تصلكم في الأيام الأولى عدة تذكيرات دفعة واحدة (تقارير شهرية مستحقة، قوالب تنتظر الاعتماد، مهام أقسام). هذا متوقع ويهدأ سريعًا.
> - دليل المستخدم: `<user-guide-link>`
> - للأسئلة أو المشكلات: `<support-contact>`

### A2: after `up`

**English**

> HC Tracking is back online. Thank you for your patience. Please reload any page you had open. During the first days you may receive several reminders at once; this is expected. User guide: `<user-guide-link>`. Questions or problems: `<support-contact>`.

**العربية**

> عاد نظام HC Tracking إلى العمل، شكرًا لصبركم. يُرجى إعادة تحميل أي صفحة كانت مفتوحة لديكم. قد تصلكم في الأيام الأولى عدة تذكيرات دفعة واحدة، وهذا متوقع. دليل المستخدم: `<user-guide-link>`. للأسئلة أو المشكلات: `<support-contact>`.

### A3: after a rollback
Use the message in §6.

---

## Appendix B: open items

| # | Item | Owner | Blocks |
|---|---|---|---|
| B1 | T27 slice 2: first rehearsal (CP5) and final rehearsal (day 13), with durations, rollback timings and the copy deleted | Dev 1 | §1 row 2; every Duration; total window |
| B2 | T28: UAT signed by MEAL, Funding and Finance; QA report; Go at CP6 (full or phased) | Dev 1 + management | §1 rows 3, 10; S-test baseline; M10 |
| B3 | G2: `annual-plans:ensure-2026` not registered | T33 slice 4 · B4 | D10, POST-15 |
| B4 | G3: `report-templates:prefill-active` not registered | T33 slice 4 · B4 | D11, POST-16, §2 D-25 list |
| B5 | `/report-templates` page not on `main` | T33 slice 3 · B3 | S9 |
| B6 | G6: center conflicts (9/25, 11/26) and project 19 | management via Dev 2 (T30) | D7 |
| B7 | G7: duplicated HR program codes 003, 004, 009; cities without an active center | HR via Dev 2 | D9, D7 |
| B8 | G4 / D2: `prechecks.md` missing; PRE-01…10 accepted or not | Dev 1, Dev 2 | §2 |
| B9 | G8: MEAL approval of the T32 data; `donor_attribution` per program | Dev 1 | D8 |
| B10 | ✅ **Q2, closed 2026-10-26:** topology (a) VPS. Still to check at T-2: the Supervisor program names and the `schedule:run` cron line (§2). | Team A · Dev 1 | — |
| B11 | On-call names (48 h; Team A Sat 31 Oct, Sun 1 Nov); support contact | Dev 1 | §1 row 6; §2 |
| B12 | D4: encryption inside or after the window | Dev 1 + management | D13 |
| B13 | D5: full or phased, and the withheld permission list | management | D18; S-test skips |
| B14 | ✅ **Q1, closed 2026-10-26:** Team A adopts the code's behaviour. The backfill sends no `monthly_reports.generated` (`BackfillMonthlyReports` → `MonthlyReportGenerator::generate()` under `Activity::withoutRecording`); only `GenerateMonthlyReportsForProject` on `ProjectActivated` does. | Team A | — |
| B15 | **Q4:** rollback cut-off (§6). Team A ✅ 2026-10-26; management ⏸ (§1 sign-off) | management | §6 |
| B16 | **Q5:** where users read the user guide (`<user-guide-link>`) | Dev 1 | Appendix A |
| B17 | ✅ **Q6, closed 2026-10-26:** the flood rule in §5. Team A asked for the most suitable rule given the defaults. Revisit at C5 with real numbers; B is measured at T-2 (§2). | Team A · Dev 1 (B) | — |
| B18 | Restore method into an empty schema (R2), from T27 step 13.1 | Dev 1 | R2 |
| B19 | Decryption-failure threshold `<n>` | Dev 1 (after the rehearsal) | §6 triggers |
| B20 | `.env.example` lacks `SESSION_EXPIRE_ON_CLOSE` although `.ai/rules/config-2.md` says it is there. The config defaults to `true`. | config owner (follow-up, not T29) | none (§2 still checks the key by name) |
| B21 | Window times, `<release-tag>`, `<previous-release>`, `<agreed-record>`, backup retention date | Dev 1 | header, §2, S22, §8 |
| B22 | **Deploy reported done (2026-10-26), evidence pending.** Received: commit `96ea52be`; `migrate:status` all `Ran`; 0 decryption failures; `queue:failed` empty. Still pending: the time of `up` (T); backup + restore result; file-key copies (yes/no); `supervisorctl status`, `queue:restart` and the cron line; D1–D20 outputs; POST checks; pages S4–S21; full or phased | Team A · Dev 1 | §1 rows 2, 3, 8–10; §7 deploy log; §5 checkpoint times |
| B23 | **T33 gap after the deploy (High), confirmed 2026-10-26** (`/report-templates` → 404 on production): without slices 3–4, MEAL cannot confirm templates (no project reaches `active`), already-active projects have no templates, and no 2026 annual plans exist | T33 · B3 (slice 3), B4 (slice 4); Dev 1 (follow-up deploy) | follow-up deploy: D10, D11, POST-15, POST-16, S9 |
