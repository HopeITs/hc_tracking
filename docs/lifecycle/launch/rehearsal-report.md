# Rehearsal report

> **DRAFT (pre-CP5).** Template prepared by T27 on 2026-10-21 (GitHub `main` `21b3187a`). Sections 1–7 are filled from the outputs the operator pastes during the rehearsals (T27 slice 2, days 12–13: first rehearsal at CP5 Tue 27 Oct, final rehearsal day 13 morning). Section 0 holds local runs made while drafting; they are **not** rehearsal results and not evidence that production is ready.

Rules for whoever fills this in:
- **Counts and ids only.** No host, user, key, name, email, amount, account number or file name. Remove them from pasted outputs.
- Time every step (start, end, minutes) and write its backup point.
- A step that needed a manual fix is **Failed**, even if it then worked.

| Field | Value |
|---|---|
| Rehearsal | first (day 12, CP5) / final (day 13 morning) |
| Date | |
| Operator | Dev 1 / Dev 2 (role only) |
| Release commit | |
| Baseline commit (D1) | `0a21efc5`, or the one Dev 1 named |
| Copy restored from the production backup taken at | |
| Safe `.env` set (runbook §3 item 2), incl. `QUEUE_CONNECTION=database`, no worker, no scheduler | yes / no |
| D3 APP_KEY | production key on the closed host / without it; not checkable: |
| D4 encryption | inside the window / after `up` |
| D5 launch | full / phased |
| Result | Passed (no manual step) / Failed |
| Total duration | |

---

## 0. Local runs made while drafting (developer PC, 2026-10-21)

The local DB (`hc_tracking`) holds seed data. It started with 39 of the 57 release migrations pending (#19 `2026_10_14_155709` on), and was then backed up and migrated locally. Fill steps 4–10 were not run for real. HR is a SQLite stand-in with no centers and invented program codes. Outputs are reduced to counts and ids.

| Command | Exit | Output (counts / ids) |
|---|---|---|
| `migrate:status --pending` | 0 | 39 pending; **0 after** the local `migrate --step` below |
| `mysqldump --single-transaction --routines --triggers hc_tracking` (local backup, before migrating) | 0 | taken; kept outside the project folder |
| `migrate --step` (local DB only) | 0 | #19–#57 all `DONE`, about 3.1 s in total |
| POST-01…POST-23 on the migrated local DB | — | 0 SQL errors; after-step-3 checks at their expected values (POST-01 0, POST-02 0, POST-03 4, POST-04 1, POST-05 0 / 0, POST-06 0 / 0 / 0, POST-07 0, POST-08 6 / 5 / 6, POST-09 0 / 0 / 0, POST-10 0 / 0); later checks show the fill steps were not run (POST-13 0 pillars, POST-14 51, POST-16 26 without template, POST-17 0 months) |
| `migrate --pretend` | 1 | #19–#23 previewed, then #24 `2026_10_15_105819` throws "funding_agreement_installments must exist first." (runbook G5) |
| `permissions:sync --dry-run` | 0 | "Permissions are already up to date." |
| `hr:map-project-centers --dry-run` | 0 | Matched 0 · No center 28 (projects 1–28) |
| `strategy:import-pillars …/strategy-2026-2030.json --dry-run` | 1, then 0 after migrating | before: `strategic_pillars` missing; after: plan printed, "Dry run: nothing was changed." |
| `meal:import-framework …/micro-urban.json --dry-run` | 1 | "Program 2 has the HR code sf1600, but the file is for program code 002. Nothing was changed." (invented local HR code) |
| `meal:import-framework …/micro-agri.json --dry-run` | 1 | same refusal |
| `meal:seed-indicator-library --dry-run` | 0 | 35 proposed entries; "Dry run: nothing was changed." |
| `monthly-reports:backfill --from=2026-10 --dry-run` | 1, then 0 after migrating | after: 20 projects with months, **74 months** = PRE-27a; 0-month projects 11, 20, 23, 25, 26, 27 = PRE-27b |
| `monthly-reports:prepare --dry-run` | 1, then 0 after migrating | after: "Dry run: nothing was written." |
| `files:encrypt-existing --dry-run` | 1 | "File encryption is not configured. No file was changed." (no key on the PC, by design) |

**With the real HR dump** (imported 2026-10-22 into the local MySQL as `hr_db`; the app's session there is read-only, and a probe write was refused with error 1792):

| Command / check | Exit | Output (counts / ids) |
|---|---|---|
| `meal:import-framework` urban and agri `--dry-run` | 0 / 0 | runs; existing results 3, 4, 19, 20, 21 left as they are; nothing changed |
| `hr:map-project-centers --dry-run` | 0 | Matched 10 · Ambiguous 1 (project 19) · No center 13 · **Conflicts 4: 9/25 at ADISC, 11/26 at HDISC** · Duplicated program codes 3 |
| `meal:seed-indicator-library --dry-run` | 1 | stops on duplicated HR codes 003 and 004 (G7); nothing changed |
| `strategy:import-pillars … --dry-run` | 0 | plan printed; nothing changed |
| PRE-09a | — | 003 (3, 4), 004 (5, 6), 009 (11, 12, 14) |
| PRE-21 | — | 10 cities without an active center: 1, 7–15 |

The local tracking data was seeded against the old stand-in HR, so tracking ↔ HR links are only indicative (project city 17 does not exist in the real HR). On 2026-10-22 the local DB was rebuilt from real data against the real HR (§0b); city 17 is still missing from HR (G7).

### 0b. Local end-to-end rehearsal of Part A (developer PC, 2026-10-22)

**Not a production rehearsal.** The CP5 date was set aside at the developer's request.

**How the copy was made:**
- The local `hc_tracking` was rebuilt from real data only: real seeders and real HR, with placeholder people fields set to the admin account. It was then dumped into a throwaway schema `hc_tracking_rehearsal`.
- The copy was rolled back to the baseline (74 / 0) with `migrate:rollback --step=57`. That stopped at #24 (G11), so #24's `down()` was applied by hand in the right order to finish the setup (setup only, not a Part A step).
- `.env` per §3 item 2, set per process: `APP_ENV=production`, `APP_DEBUG=false`, `MAIL_MAILER=log`, `BROADCAST_CONNECTION=log`, `QUEUE_CONNECTION=database`, no worker, no scheduler.
- A throwaway file key made for the rehearsal and destroyed after it.
- HR = the real dump `hr_db`. The app's session there is read-only, and the HR tables' checksums were identical before and after.

**What a local copy cannot show:**
- **No files:** PRE-17 is 0, so step 10 measures nothing.
- **No production data:** counts are local.
- **Permissions:** the copy's roles came from the current seeder, so step 2 had nothing to add.
- **Steps 7, 8, 8a:** the commands do not exist (G2, G3).

| Step | Backup (test-restored) | Dry / real / again | Result (counts / ids) | Verdict |
|---|---|---|---|---|
| 0 PRE checks | step-0 dump; restored into a scratch schema, counts equal | — | PRE-00 74 / 0 · PRE-01, 02, 03, 05, 11 = 0 · PRE-04 projects active 26, closed 2; proposals active 8, approved 25, completed 1 · PRE-06 0 · PRE-07 2 groups (9/25, 11/26) · PRE-08 cities 14 (project 6), 17 (5, 18, 24) · **PRE-09 programs 3, 4, 6** (duplicated code with indicators) · PRE-10 33 / 45 / 9 · PRE-17 0 files · PRE-19 1 holder · PRE-20b all 0 · PRE-22 16 breaks · PRE-26 projects 12, 28 · PRE-27a 20 / 74 | Fail on the real data: PRE-09 has no decision (G7) |
| 1 clear, status | — | — | 57 pending = the inventory exactly | Pass |
| 2 `permissions:sync` | yes | 0 / 0 / 0 | "already up to date" ×3; PRE-18b diff: 0 removed, 0 added | Pass (nothing to add on this copy) |
| 3 migrations | yes | — | `--pretend` stops at #24 (G5) · `migrate --force --step`: **57 / 57 `DONE`, 0 FAIL, 5 s** · #13: 0 moved · #15: active 26 / closed 2 before and after; drafts with dates: none · T02: 4 grants revoked, 3 granted | Pass |
| 3a–3d invariants | — | — | POST-01 0 · POST-02 0 · POST-03 4 · POST-04 1 · POST-05 0 / 0 · POST-06 0 / 0 / 0 · POST-07 0 · POST-08 6 / 5 / 6 · POST-09 0 / 0 / 0 · POST-10 0 / 0 | Pass |
| 4 `hr:map-project-centers` | yes | dry / apply / apply | Matched 10 · Ambiguous 1 (19) · No center 13 · Conflicts 4 = PRE-07 · second apply "Updated 0" · POST-12a 18 unmapped | Mechanics pass; **blocked on G6** (no decision) |
| 5 pillars, Micro urban, Micro agri | yes | 0 / 0 / 0 each | pillars 3 created; 8 programs assigned; 5 missing in HR (= T32 handoff) · urban 16 entries / 20 results · agri 21 / 21 · second runs all unchanged · POST-13 3 / 8 / 37 / 0 | Pass (T32 data not yet MEAL-approved, G8) |
| 6 `meal:seed-indicator-library` | yes | 1 / — / — | stops: codes 003 (3, 4) and 004 (5, 6); nothing changed; POST-14 33 | **Blocked on G7** |
| 7, 8, 8a | — | — | commands not registered | PENDING (G2, G3) |
| 9 `monthly-reports:backfill --from=2026-10` | yes | 0 / 0 / 0 | real: 74 months / 20 projects = PRE-27a · again: 0 created, 74 already there · POST-17 0 / 0 / 74 / 0 | Pass |
| 10 `files:encrypt-existing` | pre-step-10 dump | 0 / 0 / 0 | 0 files in all 4 tables; no `*.enc.tmp`; **the real run asks for confirmation (no `--force`)** | Pass (nothing to encrypt locally) |
| 11 everything again | yes | real only | PRE-20 / 20b identical; **all 73 tables' counts identical** before and after | Pass |
| 12 POST, pages, approval | yes | — | all POST: 0 SQL errors · POST-23 = PRE-22 (16 breaks; the 41 imported results add none) · 29 pages (list pages, admin pages, one project / proposal / program show page) HTTP 200 as user 1 · test proposal: 5 transitions, then `ApproveProposal` → proposal and project `approved`, warning `no_agreement` · after it: activities +18, notifications 0, jobs +26 (`FanOutActivity` 18, `ShiftActivitySchedulesOnApproval` 6, `PrefillReportTemplates` 1, `RebaseProposalPlanOnApproval` 1), never run · HR checksums unchanged | Pass (no file downloads: no files) |
| 12a self-funded relinking | — | — | POST-20 projects 12, 28 | Waiting for Dev 1's decision |
| 13.1 restore proof | step-0 dump | — | restored in 2 s; 74 migrations; counts equal step 0 | Pass (DB only; no storage locally) |
| 13.2 `migrate:rollback --step=57` | pre-step-10 dump | — | `APP_ENV=production`: refused (G10) · `APP_ENV=local`: 33 `DONE`, then **#24 FAIL, error 1553** (G11) | **Fail (G11)** |
| 13.3 forward again | — | — | 33 / 33 `DONE`, 4 s; POST-01…POST-11 identical to after step 3 | Pass |
| 13.5 delete the copy | — | — | copy schema, its dumps and the throwaway key deleted; logs (counts and ids) kept outside the project | Done |

**G11 re-test after the fix (same day):** on a new copy, `migrate:rollback --step=57` ran 57 / 57 `DONE` in 3 s, back to 74 / 0. `migrate --force --step` then ran 57 / 57 in 6 s, with POST-01…POST-11 as after step 3. Step 13.2 therefore passes with the fix; it was a Fail before it.

Defects and findings for owners: **G10** (Dev 1), **G11** (T09 · Dev 1; fixed on this branch, not merged), the `files:encrypt-existing` prompt (T22, informational), POST-22 is now runnable (0).

Checks: every local result is inline in [`checks.sql`](checks.sql), labelled "local, 2026-10-21". The SQL validation:
- PRE section on the local DB: only the 4 expected errors (the `<hr_db>` placeholder; `hchr_centers`, `hchr_programs` and `hchr_cities` live in the HR DB).
- POST section on the test DB `hc_tracking_test` (all 57 migrations applied): 0 errors.

Start-state tests (T27 prompt, Start state 5), local, 2026-10-21. Files: `EncryptExistingFilesCommandTest`, `ImportFrameworkCommandTest`, `ImportPillarsCommandTest`, `MapProjectCentersCommandTest`, `MonthlyReportGenerationTest`, `SeedIndicatorLibraryCommandTest`, `SyncPermissionsCommandTest`, `ReencryptSecretsCommandTest`, `PruneStoredFilesTest`, `DatabaseEncodingTest`.
```
{"tool":"pest","result":"passed","tests":102,"passed":102,"assertions":1169,"duration_ms":87502}
```

---

## 1. Step 0: PRE checks

Paste each result. BLOCKING ones: Pass, Decided (who decided and what) or Fail.

| Check | Type | Expected | Result (counts / ids) | Pass / Decided / Fail | Note |
|---|---|---|---|---|---|
| PRE-00 | blocking | 74 / 0 | | | D1 |
| PRE-01 | blocking | 0 | | | |
| PRE-02 | blocking | 0 | | | |
| PRE-03 | blocking | 0 | | | |
| PRE-04 | info | record | | — | |
| PRE-05 | blocking | 0 | | | |
| PRE-06 | info | record | | — | |
| PRE-07 | info (blocking at step 4) | record | | — | compare with the step-4 Conflicts list (G6) |
| PRE-08 | info | record | | — | management note (D-30c) |
| PRE-09 | blocking | 0 | | | |
| PRE-10 | info | record | | — | reference 61 / 61 / 33 |
| PRE-11 | blocking | 0 | | | |
| PRE-12 | info (fails POST-06 if not decided) | record | | — | |
| PRE-13 … PRE-27 (incl. PRE-20b) | info | record | | — | one management note each |

Backup before step 1: dump taken at ______, storage archive taken at ______, test-restored into a scratch schema with matching counts: yes / no.

## 2. Steps 1–13

| Step | Backup point | Started | Ended | Minutes | Dry / real / again | Output pasted | Invariant (check ids) | Result | Re-runs |
|---|---|---|---|---|---|---|---|---|---|
| 1 `optimize:clear`, `migrate:status` | — (reads only) | | | | — | | pending = inventory | | |
| 2 `permissions:sync` | | | | | / / | | PRE-18b diff | | |
| 3 `migrate --pretend`, `migrate --force --step` | | | | | — | | POST-10 | | |
| 3a T03 | | | | | — | | POST-01…04 | | |
| 3b T04 | | | | | — | | POST-05 | | |
| 3c T05 | | | | | — | | POST-06; PRE-04/20 re-run | | |
| 3d rest | | | | | — | | POST-07, 08, 09, 11 | | |
| 4 `hr:map-project-centers` | | | | | / / | | Conflicts decided; POST-12 | | |
| 5 `strategy:import-pillars`, `meal:import-framework` ×2 | | | | | / / | | POST-13 | | |
| 6 `meal:seed-indicator-library` | | | | | / / | | POST-14 | | |
| 7 `annual-plans:ensure-2026` | | | | | / / | | POST-15 | PENDING (G2) | |
| 8 `report-templates:prefill-active` | | | | | / / | | POST-16; PRE-20/20b | PENDING (G3) | |
| 8a `target_beneficiaries` entry | | | | | — | | fixed-denominator list; POST-21 | PENDING (G3) | |
| 9 `monthly-reports:backfill` | | | | | / / | | POST-17 | | |
| 10 `files:encrypt-existing` (or D4 deferral) | pre-step-10 backup: | | | | / / | | POST-18 | | |
| 11 re-run 2, 4–10 | | | | | — | | POST-19 | | |
| 12 POST checks, pages, downloads, test approval | | | | | — | | all POST; PRE-20b | | |
| 12a self-funded relinking | | | | | — | | POST-20 | | |
| 13 restore proof, `migrate:rollback` proof, forward, delete copy | | | | | — | | Part C | | |

For step 3, paste the `migrate --force --step` output and record each migration's time when the output shows it.

## 3. Counts before and after

| Table | PRE-20 | After 3c | Before step 11 | After step 11 | Expected change |
|---|---|---|---|---|---|
| proposals | | | | | none |
| donor_proposal | | | | | none |
| donors | | | | | +1 internal donor at most |
| projects | | | | | none (statuses change at 3c, not counts) |
| project_proposal | | | | | none |
| programs | | | | | none |
| milestones | | | | | none |
| tasks | | | | | none |
| results | | | | | + Micro framework (step 5) |
| indicators | | | | | + Micro framework (step 5) |
| plan_indicators | | | | | none |
| reports | | | | | none |
| proposal_status_notes | | | | | + one per `final reports` proposal (PRE-06) |
| transfers_tracking | | | | | none |
| annual_plans | | | | | + 2026 plans (step 7); reference before: 8 (unverified) |
| users | | | | | none |
| role_has_permissions | | | | | per step 2 and T02 |
| activities | | | | | + step 8 `report_template.created` only; **step 11 adds none** |
| notifications | | | | | + step 8 `report_template.pending` only; **step 11 adds none** |
| jobs | | | | | + queued listeners (never run, no worker); **step 11 adds none** |
| failed_jobs | | | | | none |

## 4. Errors and re-runs

| Step | Error (no host, no personal data) | Owner task | Defect logged | Rerun from a fresh copy |
|---|---|---|---|---|
| | | | | |

## 5. Rollback tests (step 13)

| Item | Value |
|---|---|
| Restore proof (step-0 backup, DB + storage together): minutes | |
| Restore verify (PRE-20 counts, a download, a login) | |
| `migrate:rollback --step=<n>` from the pre-step-10 backup: minutes | |
| Documented outcomes seen (#13, #15 no-op; #50, #26 conditions) | |
| Anything else that failed | |
| Forward again (`migrate --force --step`): minutes; 3a–3d invariants | |

## 6. Copy deletion

| Item | Confirmed by operator (time) |
|---|---|
| Copy database deleted | |
| Copy `storage/app/private` deleted | |
| Rehearsal backups deleted | |
| Throwaway `FILE_ENCRYPTION_KEY` destroyed | |

## 7. Decisions and sign-off

| Decision | Recorded by (role) | Decision | Date |
|---|---|---|---|
| D1 baseline | Dev 1 | | |
| D2 PRE-01…10 vs `prechecks.md` | Dev 1 | | |
| D3 APP_KEY | Dev 1 + management | | |
| D4 encryption window | Dev 1 + management | | |
| D5 full / phased | management | | |
| G6 each center-conflict pair | management | | |

| Role | Initials or role only | Date |
|---|---|---|
| Operator (Dev 1) | | |
| Backup (Dev 2) | | |
| Management (blocking PRE decisions, D-30c, G6) | | |
| MEAL (T32 data, step 6 codes, step 8a list) | | |
