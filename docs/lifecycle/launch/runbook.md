# Launch runbook: rehearsal, production order and rollback

> **DRAFT (pre-CP5). NOT READY FOR A REHEARSAL.**
> - Written 2026-10-21 on `lifecycle/T27-rehearsal` from GitHub `main` `21b3187a` (`HopeITs/hc_tracking`, the canonical repository since the move from GitLab).
> - Dev 1 approved drafting before T27's start state passes.
> - CP5 (feature freeze, first rehearsal) is day 12, **Tue 27 Oct**; the deploy is day 14, Thu 29 Oct (README checkpoints).
> - At CP5: re-run the inventory, fill the PENDING rows, settle the decisions in §1b, then remove this banner.
> - Nothing here is a rehearsal result or evidence that production is ready. Every local result is marked "local".

The operator is **Dev 1** (backup Dev 2). T27 prepares this document and runs nothing on the copy or on production. T29 builds the production checklist from Part B.

Placeholders: `<db>` the tracking schema, `<hr_db>` the HR schema, `<app-path>` the application folder, `<backup-dir>` the backup folder, `<php>` the PHP binary of the host. Checks are in [`checks.sql`](checks.sql) (PRE-00…PRE-27, POST-01…POST-23). Times and counts go into [`rehearsal-report.md`](rehearsal-report.md).

## Contents
1. [Open gaps](#1-open-gaps) · [1b. Decisions pending](#1b-decisions-pending)
2. [Inventory](#2-inventory)
3. [Before you start](#3-before-you-start)
4. [Part A: rehearsal](#4-part-a-rehearsal)
5. [Part B: production order (draft for T29)](#5-part-b-production-order-draft-for-t29)
6. [Part C: rollback](#6-part-c-rollback)
7. [Human items before go-live](#7-human-items-before-go-live)
8. [Never](#8-never)

---

## 1. Open gaps

Each gap stops the rehearsal until its owner closes it.

| # | Gap | Owner | Effect on this runbook |
|---|---|---|---|
| G1 | CP5 (feature freeze) is not declared. On `main` `21b3187a`, every release task with a handoff says "done" except **T33** ("in progress, slices 1–2/4"); T33 slices 3–4 may still add migrations or commands. | T33 · B3 (slice 3), B4 (slice 4) | Re-run the inventory (§2) at CP5. |
| G2 | `annual-plans:ensure-2026` now exists (T33 slice 4, merged into `main` on 2026-10-27). It has tests but has **not been rehearsed** yet. | T33 slice 4 · B4 | Step 7 and POST-15 are not rehearsed. |
| G3 | `report-templates:prefill-active` now exists (T33 slice 4, merged into `main` on 2026-10-27). It has tests but has **not been rehearsed** yet. | T33 slice 4 · B4 | Steps 8 and 8a and POST-16 are not rehearsed. |
| G4 | `docs/lifecycle/prechecks.md` does not exist (T01 slice 1 not delivered). PRE-01…PRE-10 follow T01's specification; there is no T01 result to compare with. | T01 · Dev 2; decision D2 | Production results of PRE-01…PRE-10 must be pasted by a human. |
| G5 | `migrate --pretend` cannot preview the whole release. Locally it previews #19–#23, then #24 `2026_10_15_105819` throws "funding_agreement_installments must exist first.": `--pretend` executes nothing, so #23's table never exists. This says nothing about whether a real run is safe. | T27 / Dev 1 | Validation method for the rest: §2 "How the migrations were validated". |
| G6 | **One open project per (center, program) (D-30a): confirmed against the real HR data.** On 2026-10-22, `hr:map-project-centers --dry-run` against an HR dump imported locally reported **4 conflicts**: projects 9 and 25 ("DISC Aleppo", "Science Zone Aleppo") both map to center ADISC, and 11 and 26 ("DISC Homs", "Science Zone Homs") to HDISC. These are the same pairs as PRE-07 locally and T30's dev run (#17/#33, #19/#34). The command never applies a conflict. **Release blocker** until management decides each pair. Also 1 ambiguous project (19, several candidate centers in its city). | management (D-30c) via Dev 2 (T30) | Step 4 cannot pass. Evidence needed: the step-4 Conflicts list on the production copy, and a recorded decision per pair (another HR center for one project, close a finished one, or an accepted exception), plus the center for project 19. |
| G7 | HR prerequisites, confirmed against the real HR dump (2026-10-22): duplicated program codes **003 (programs 3, 4), 004 (5, 6), 009 (11, 12, 14)**. Programs 3, 4 and 6 have indicators, so PRE-09 blocks, and `meal:seed-indicator-library --dry-run` stops ("HR program code 003 is used by more than one program …"). **10 HR cities have no active center** (1, 7–15), against D-30's list 7, 8, 12, 13, 14, 17. | HR team via Dev 2 (T30) and B2 (T11) | Step 6 cannot run until the codes are fixed; step 4 leaves those cities' projects unmapped. |
| G8 | Dev 1 has not yet confirmed: the closed rehearsal environment and its operator; management's review of T30's matching (D-30c); MEAL's approval of T32's data (`handoffs/T32-data.md`); each program's `donor_attribution` (T13). | Dev 1 | The rehearsal cannot start. |
| G9 | Five decisions T27 must not make: §1b. | as listed in §1b | The rehearsal cannot start without D1, D3 and D4. |
| G10 | **`migrate:rollback` is refused under the rehearsal `.env`.** `AppServiceProvider` calls `DB::prohibitDestructiveCommands(app()->isProduction())`, so with `APP_ENV=production` the command prints "This command is prohibited from running in this environment." (local rehearsal, 2026-10-22). `migrate:fresh`, `migrate:reset` and `db:wipe` are refused the same way: a good guard for production. | Dev 1 | Step 13 part 2 runs that one command with `APP_ENV=local` set for the command only, on the copy only. T27 recommendation; Dev 1 confirms. Part C is unaffected: the production rollback is a restore. |
| G11 | **Defect: #24's `down()` fails on MariaDB.** `2026_10_15_105819_add_source_installment_and_arrival_to_transfers_tracking_table` drops `transfers_tracking_installment_id_unique` before `transfers_tracking_installment_id_foreign`. The foreign key still needs that index, so the drop fails with error 1553 "needed in a foreign key constraint". `migrate:rollback --step=57` therefore stops after 33 of 57 (local rehearsal, 2026-10-22, reproduced twice). The fix is to drop the foreign key first. | T09 · Dev 1 | **Fixed on `lifecycle/T27-rehearsal` (2026-10-22), not merged yet:** `down()` now drops the foreign key first, covered by `tests/Feature/InstallmentTransferMigrationTest.php` (fails with error 1553 without the fix). On a new copy, `migrate:rollback --step=57` ran 57 / 57 `DONE` in 3 s and `migrate --force --step` 57 / 57 in 6 s, with POST-01…POST-11 as after step 3. Closed once merged. Part C is unaffected. |

Informational (no stop), true on `main` `21b3187a`:
- `files:decrypt --help` names only `media|report_files|task_attachments`. The code accepts `StoredFiles::FileTables`, which includes `monthly_report_files`. Help text only (T17A / T22).
- `files:prune` scans only `media/`, `report-files/` and `task-attachments/`, against `Media`, `ReportFile` and `TaskAttachment` rows. Monthly report files live in `monthly-report-files/`, so `files:prune` never deletes them, and never removes an orphan there either (T17A / T22 follow-up).
- Two pre-baseline migrations were edited after the baseline: `2026_09_23_145553_read_user_and_program_details_from_hr` and `2026_09_24_111950_add_slug_columns_to_trackable_tables`. The diff is type hints and PHPDoc only (`object` → `stdClass`, `?->` → `->` before `??`). Production does not re-run them and loses nothing.
- `files:decrypt` has no `--dry-run`. It is the emergency tool of Part C.
- `files:encrypt-existing` has no `--force`. In production its real run always asks "Are you sure you want to run this command?" The operator types `yes`; an unattended script waits there forever (local rehearsal, 2026-10-22).

## 1b. Decisions pending

T27 does not make these. Each stays open until its owner records it in the report.

| # | Decision | Owner | Risk while open | Evidence / approval required |
|---|---|---|---|---|
| D1 | Is the baseline commit `0a21efc5` (the T27 prompt's default) right? | Dev 1 | A wrong baseline changes the inventory: migrations production never ran would be missed. PRE-00 catches it on the copy, but only then. | Dev 1 names the commit production runs today, confirmed by PRE-00 on a fresh production backup (`up_to_baseline` = 74, `release_already_applied` = 0). |
| D2 | Do PRE-01…PRE-10 legitimately replace T01's missing `prechecks.md`? | Dev 1, with T01's owner (Dev 2) | T01's checks have never run on production; their expected results are T01's spec, not measured values. | Dev 1 accepts them in writing, or T01 delivers `prechecks.md`; either way a human pastes the production results. |
| D3 | Does the rehearsal copy keep the production `APP_KEY`? | Dev 1 + management, per an approved security design | With it, the closed host holds a production secret. Without it, 2FA logins and the encrypted account columns cannot be checked (`users.two_factor_secret`, `users.two_factor_recovery_codes`, `accounts.account_number`, `accounts.iban`, `accounts.swift_code`, `accounts.authorized_person`). | An approved security design for the closed host, or a written acceptance of the unchecked list. |
| D4 | Does file encryption (`files:encrypt-existing`) run inside or after the maintenance window? | Dev 1 + management | Inside, the window grows with `storage/app/private`. After, legacy files stay plaintext at rest until it runs, and it must avoid the 03:00 `files:prune`. | Step 10's measured duration and the production folder size (PRE-17), then a recorded choice. |
| D5 | Full or phased launch? | management | Decides what is switched on and what T28/T29 verify. | A recorded decision before CP6. |

---

## 2. Inventory

Baseline `0a21efc5` (D1): 74 migrations, the last `2026_10_06_100000_restrict_project_creation_to_pr_manager_and_general_manager`.

Source: `git diff --name-only --diff-filter=A 0a21efc5..main -- database/migrations` on `main` `21b3187a`: **57 migrations**, in the order `migrate` runs them. Each row was checked against the file's `up()` and `down()`, and against its handoff (2026-10-20/21).

Columns:
- **Guard**: what `up()` checks before any DDL (MySQL does not roll back DDL). "skips" = returns when the object exists; "throws" = refuses.
- **Data**: what `up()` writes besides schema.
- **down()**: what a rollback really does.
- **Size**: the table it touches.

| # | Migration | Task · owner | Guard in `up()` | Data | `down()` | Size |
|---|---|---|---|---|---|---|
| 1 | `2026_10_13_124015_apply_lifecycle_permission_grant_changes` | T02 · Dev 3 | missing roles/permissions skipped | revokes `targets.create/update` from General Manager and Managers; grants `funding-agreements.view/create/update` to Public Relations Manager; prints counts | re-grants targets to GM + Managers (even if an admin had removed them); revokes the 3 PR Manager grants | small |
| 2 | `2026_10_13_130052_add_lifecycle_columns_to_proposals_table` | T03 · Dev 1 | throws with ids on duplicate agreements (PRE-01) or proposals without one (PRE-02) | backfills `proposals.donor_id` from `donor_proposal`; throws if any stays NULL; then NOT NULL | **lossy**: drops `donor_id` and the new columns | proposals |
| 3 | `2026_10_13_130054_make_donor_proposal_one_per_proposal` | T03 · Dev 1 | repeats the duplicate check; throws on a donor mismatch | — | drops the composite FK and the unique | small |
| 4 | `2026_10_13_130055_add_target_beneficiaries_to_project_proposal_table` | T03 · Dev 1 | — | — | drops the column (**lossy** once the PR Manager entered values) | small |
| 5 | `2026_10_13_130057_add_is_internal_to_donors_table` | T03 · Dev 1 | — | inserts the internal donor «تمويل ذاتي — Hope» if none (skipped under tests) | deletes it only if no proposal uses it; drops the column | small |
| 6 | `2026_10_13_130059_add_working_days_to_projects_table` | T03 · Dev 1 | — | — | drops the column | small |
| 7 | `2026_10_13_130101_add_proposal_link_to_milestones_table` | T03 · Dev 1 | — | — | drops the FK and the columns | small |
| 8 | `2026_10_13_130331_add_center_id_to_projects_table` | T30 · Dev 2 | skips if the column exists | — | drops the index and the column (**lossy** after step 4) | small |
| 9 | `2026_10_13_135459_create_library_indicators_table` | T11 · B2 | skips if the table exists | — | drops the table (**lossy** after steps 5–6) | new |
| 10 | `2026_10_13_135500_add_library_indicator_id_to_indicators_table` | T11 · B2 | skips if the column exists | — | drops the FK and the columns | indicators |
| 11 | `2026_10_13_140452_create_meal_coverages_table` | T15 · B1 | skips if the table exists | — | drops the table | new |
| 12 | `2026_10_13_162613_add_correction_and_actor_to_proposal_status_notes_table` | T04 · Dev 1 | throws if a column exists | — | drops the FK and the columns | notes |
| 13 | `2026_10_13_162615_move_final_reports_proposals_to_active` | T04 · Dev 1 | returns if no row | `final reports` → `active` plus one note each, with the translated text in `context`; prints counts (PRE-06, POST-05) | **no-op (documented)** | small |
| 14 | `2026_10_14_095945_add_encryption_key_id_to_file_tables` | T22 · B4 | skips per table if the column exists | — (no backfill) | **throws once any file row has `encryption_key_id`** | file tables |
| 15 | `2026_10_14_121002_retire_submitted_project_status` | T05 · Dev 1 | — | `submitted`/`approved`: both dates → `active`, else `draft`; prints counts and drafts holding dates | **no-op (documented)** | small |
| 16 | `2026_10_14_121610_create_supervisions_table` | T23 · B3 | skips if the table exists | — | drops the table | new |
| 17 | `2026_10_14_152029_create_sectors_tables` | T34 · B3 | skips per table if it exists | — | drops `project_sector`, then `sectors` | new |
| 18 | `2026_10_14_152030_seed_default_sectors` | T34 · B3 | — | inserts the 6 D-47 sectors if missing, by name (skipped under tests) | deletes the defaults no project uses | small |
| 19 | `2026_10_14_155709_create_proposal_reviewers_table` | T07 · Dev 2 | throws if the table exists | — | drops the table | new |
| 20 | `2026_10_14_155710_add_meeting_at_to_proposal_status_notes_table` | T07 · Dev 2 | throws if the column exists | — | drops the column | notes |
| 21 | `2026_10_15_093930_add_proposal_framework_columns_to_results_table` | T12 · B1 | throws if a column exists | — | drops 3 FKs and the columns | results |
| 22 | `2026_10_15_093931_add_result_id_to_milestones_table` | T12 · B1 | throws if the column exists | — | drops the FK and the column | milestones |
| 23 | `2026_10_15_105818_create_funding_agreement_installments_table` | T09 · Dev 1 | throws if the table exists | — | drops the table | new |
| 24 | `2026_10_15_105819_add_source_installment_and_arrival_to_transfers_tracking_table` | T09 · Dev 1 | throws if a column exists, or if #23's table is missing (G5) | existing rows get `source = manual` (default) | drops the unique, the FK and 3 columns (the FK first: fixed under G11) | transfers |
| 25 | `2026_10_15_114900_add_department_plan_columns_to_tasks_table` | T31 · B3 | skips if the column exists | — | drops the index and the columns | tasks |
| 26 | `2026_10_15_114901_make_milestone_project_nullable_and_track_edits` | T31 · B3 | none for the nullable change (always applied); `planned_dates_edited_at` added only if missing | `milestones.project_id` becomes nullable | **throws once proposal-only milestones exist** | milestones |
| 27 | `2026_10_15_114902_create_proposal_plan_shifts_table` | T31 · B3 | none: a re-run fails on "table exists" | — | drops the table | new |
| 28 | `2026_10_15_120732_add_activity_kind_to_results_table` | T12 · B1/B2 | throws if the column exists | existing `activity` rows → `continuous` | drops the column | results |
| 29 | `2026_10_15_120733_create_activity_schedules_table` | T12 · B1/B2 | throws if the table exists | — | drops the table | new |
| 30 | `2026_10_15_120734_create_activity_schedule_shifts_table` | T12 · B1/B2 | throws if the table exists | — | drops the table | new |
| 31 | `2026_10_15_125547_add_copied_from_id_to_milestones_and_tasks_tables` | T10 · B3 | skips per table if the column exists | — | drops the FKs and the columns | milestones, tasks |
| 32 | `2026_10_15_130213_add_unique_target_and_population_to_plan_indicators_table` | T13 · B2 | throws if the column exists; **throws with ids on duplicate targets** (PRE-11) | — | drops the unique and the column | plan_indicators |
| 33 | `2026_10_15_130214_add_donor_attribution_to_programs_table` | T13 · B2 | throws if the column exists | — (default `shared`) | drops the column (**lossy** once MEAL set values) | small |
| 34 | `2026_10_15_154906_create_strategic_pillars_table` | T32 · B4 | throws if the table exists | — | drops the table | new |
| 35 | `2026_10_15_154907_add_strategic_pillar_id_to_programs_table` | T32 · B4 | throws if the column exists | — | drops the FK and the column | small |
| 36 | `2026_10_15_154909_add_copied_from_id_to_indicators_table` | T32 · B4 | throws if the column exists | — | drops the FK and the column | indicators |
| 37 | `2026_10_15_163959_add_is_tracking_only_to_indicators_table` | T32 · B4 | throws if the column exists | — | drops the column | indicators |
| 38 | `2026_10_15_164000_add_imported_at_to_library_indicators_table` | T32 · B4 | throws if the column exists | — | drops the column | new |
| 39 | `2026_10_16_113042_create_narrative_questions_table` | T33 · B3 | skips if the table exists | — | drops the table | new |
| 40 | `2026_10_16_113043_seed_default_narrative_questions` | T33 · B3 | — | inserts the 6 default questions if missing (skipped under tests) | deletes the defaults no `report_template_questions` row uses | small |
| 41 | `2026_10_16_113238_create_evidence_types_table` | T33 · B3 | skips if the table exists | — | drops the table | new |
| 42 | `2026_10_16_113240_seed_default_evidence_types` | T33 · B3 | — | inserts the 5 default types if missing (skipped under tests) | deletes the defaults no `report_template_line_evidence_types` row uses | small |
| 43 | `2026_10_16_124000_create_monthly_reports_table` | T17A · B1 | throws if the table exists | — | drops the table (**lossy** after step 9) | new |
| 44 | `2026_10_16_124001_create_monthly_report_reopen_requests_table` | T17A · B1 | throws if the table exists | — | drops the table | new |
| 45 | `2026_10_16_124002_create_monthly_report_lines_table` | T17A · B1 | throws if the table exists | — | drops the table | new |
| 46 | `2026_10_16_124003_create_monthly_report_line_shares_table` | T17A · B1 | throws if the table exists | — | drops the table | new |
| 47 | `2026_10_16_124004_create_monthly_report_line_breakdowns_table` | T17A · B1 | throws if the table exists | — | drops the table | new |
| 48 | `2026_10_16_124005_create_monthly_report_activity_entries_table` | T17A · B1 | throws if the table exists | — | drops the table | new |
| 49 | `2026_10_16_124006_create_project_activity_statuses_table` | T17A · B1 | throws if the table exists | — | drops the table | new |
| 50 | `2026_10_16_124007_add_monthly_report_columns_to_reports_table` | T17A · B1 | throws if a column exists | `reports.value` becomes nullable | drops FKs, unique, index and columns, then makes `reports.value` NOT NULL again: **fails (or coerces) once any `reports.value` is NULL**, e.g. a mirror of a line marked not measured | reports |
| 51 | `2026_10_16_130736_create_report_templates_table` | T33 · B4 | skips if the table exists | — | drops the table (**lossy** after step 8) | new |
| 52 | `2026_10_16_130739_create_report_template_lines_table` | T33 · B4 | skips if the table exists | — | drops the table | new |
| 53 | `2026_10_16_130741_create_report_template_line_evidence_types_table` | T33 · B4 | skips if the table exists | — | drops the table | new |
| 54 | `2026_10_16_130744_create_report_template_questions_table` | T33 · B4 | skips if the table exists | — | drops the table | new |
| 55 | `2026_10_16_160129_create_monthly_report_answers_table` | T17A · B1 | throws if the table exists | — | drops the table | new |
| 56 | `2026_10_16_160130_create_monthly_report_files_table` | T17A · B1 | throws if the table exists | — | drops the table (**lossy**: uploaded evidence rows) | new |
| 57 | `2026_10_19_122940_add_snapshot_columns_to_donor_reports_table` | T19 · Dev 2 | throws if any new column exists | existing rows get the defaults (`audience` `donor`, `version` 1) | drops the unique, the index and the 8 columns (**lossy**: donor report snapshots) | donor_reports |

`migrate --step` gives each migration its own batch, so `migrate:rollback --step=<n>` undoes them one at a time (Laravel 13 migrations docs).

### Dependencies and existing-object behaviour
- **FK and order dependencies.** FK targets were read from each file's `constrained()` / `on()` calls; each migration runs after the ones it needs.
  - #3 needs #2's `proposals_id_donor_id_unique`; #24 needs #23 (and checks it).
  - #18 runs after #17, #40 after #39, #42 after #41.
  - #44, #45, #48, #49 and #50 need #43; #46 needs #45; #47 needs #45 and #46; #48 and #49 also need `results`; #50 also needs #46.
  - #52 needs #51; #53 needs #52 and #41; #54 needs #51 and #39; #55 needs #43 and #54; #56 needs #43, #45, #48 and #41.
- **Re-run on an object that exists:**
  - "skips" rows leave it as it is;
  - "throws" rows stop before any DDL;
  - #27 has no guard and fails on "table exists".

  PRE-00 makes sure none of the 57 has run on the copy before step 3.
- **Before the release:** every column a PRE check reads exists at the baseline. This was checked against the baseline's own migration files, plus `config/permission.php` for `model_has_roles.model_id` / `role_id`.

### How the migrations were validated, and what that does not prove

| Method | What it shows | What it does not show |
|---|---|---|
| `migrate --pretend` on the local DB (2026-10-21) | the DDL of #19–#23 | anything from #24 on (G5); nothing about data |
| The test suite migrates `hc_tracking_test` from empty on MariaDB 10.4; `migrate:status` there shows all 57 **Ran**, 0 pending (2026-10-21) | the whole chain runs in order on an empty schema of the target engine. The POST checks run on it with 0 errors | guards and data migrations against production data: an empty schema passes every guard |
| `migrate --step` on the **local** dev DB (seed data; backed up first), 2026-10-21: #19–#57 all `DONE`, exit 0, about 3.1 s in total; 0 pending after. Then POST-01…POST-10 = their after-step-3 values | #19–#57 run on a DB holding (seed) data, with their guards passing | production data and size: local seed data is small and clean |
| The rehearsal (Part A) on a closed copy of production, with the `migrate --force --step` output reviewed migration by migration | the only proof against production data and size | — |

So #24–#57 are validated on an empty schema and on the local seed data. They **are not proven safe on production data until the rehearsal passes.** A fuller check without touching production: restore a scrubbed production backup on a disposable local copy, run `migrate --step` there and review the output. That is still a local result, not rehearsal evidence.

### Commands

From `<php> artisan <command> --help` on `main` `21b3187a`.

| Step | Command and flags | Task · owner | Dry run | Writes |
|---|---|---|---|---|
| 2 | `permissions:sync {--dry-run}` | T02 · Dev 3 | yes | adds permissions and roles only; never removes |
| 4 | `hr:map-project-centers {--dry-run} {--apply} {--force}` | T30 · Dev 2 | yes (default; `--dry-run` wins over `--apply`) | `projects.center_id` of matched rows, via the query builder; never a conflict; skips rows already set unless `--force` |
| 5 | `strategy:import-pillars <file> {--strategy=} {--dry-run}` | T32 · B4 | yes | pillars, `programs.strategic_pillar_id` (under `Activity::withoutRecording`) |
| 5 | `meal:import-framework <file> {--program=} {--dry-run}` | T32 · B4 | yes | library entries (sets `imported_at`), program-framework results and indicators (under `withoutRecording`) |
| 6 | `meal:seed-indicator-library {--dry-run}` | T11 · B2 | yes | library entries, `indicators.library_indicator_id` (under `withoutRecording`) |
| 7 | `annual-plans:ensure-2026 {--dry-run}` | T33 · B4 | yes | 2026 annual plans for programs that have a manager (under `withoutRecording`); not rehearsed (G2) |
| 8, 8a | `report-templates:prefill-active {--dry-run}` | T33 · B4 | yes | templates and lines of `active` projects (under `withoutRecording`, so no activity rows); no template for a project with no lines to plan; on a re-run, `fixed_denominator` of pending fixed-basis lines that are still NULL, from `target_beneficiaries`; `report_template.pending` notifications to MEAL; not rehearsed (G3) |
| 9 | `monthly-reports:backfill {--from=YYYY-MM} {--dry-run}` (default `--from=2026-10`) | T17A · B1 | yes | `monthly_reports` rows (generator under `withoutRecording`) |
| — | `monthly-reports:prepare {--dry-run}` (scheduled daily 05:00, `withoutOverlapping()->onOneServer()`) | T17A · B1 | yes | current-month lines; **not run by hand here** |
| 10 | `files:encrypt-existing {--dry-run} {--chunk=100} {--isolated}` (resumable) | T22 · B4 | yes | encrypted copies, then `path` + `encryption_key_id` |
| Part C | `files:decrypt <table> {--id=} {--to=} {--isolated}` | T22 · B4 | **no** (emergency only) | one plaintext file at `--to` |
| rotation | `security:reencrypt {--files} {--dry-run} {--isolated}` | T22 · B4 | yes | re-keys files (`--files`) or columns |

Framework commands used, all registered in this app: `optimize:clear`, `optimize`, `migrate:status`, `migrate`, `migrate:rollback`, `down`, `up`, `queue:restart`, `reverb:restart`.

### Local dry runs (developer PC, 2026-10-21, local DB only)

The local DB held seed data, with 39 of 57 pending. It was then backed up and migrated with `migrate --step` (#19–#57 `DONE`; 0 pending), and the dry runs that had failed for missing schema were re-run.

HR was first a SQLite stand-in with no centers and invented program codes. On 2026-10-22 a real HR dump (`hr_db`, 91 tables: 14 programs, 26 centers, 16 cities) was imported into the local MySQL, and `HR_DB_*` was pointed at it. The app's session there is read-only (`tx_read_only` = 1; a probe write was refused with error 1792).

The local *tracking* data was seeded against the stand-in, so tracking ↔ HR links (city and program ids) are only indicative; for example, project city 17 does not exist in the real HR. On 2026-10-22 the local DB was rebuilt from real data against the real HR (see the end-to-end rehearsal below). HR-only results are real. Fill steps 4–10 were **not** run for real. These show that the commands run; **they are not rehearsal results.**

| Command | Exit | Result (counts / ids) |
|---|---|---|
| `migrate:status --pending` | 0 | 39 pending before; 0 after `migrate --step` |
| `migrate --pretend` | 1 | #19–#23 previewed; #24 throws "funding_agreement_installments must exist first." (G5). After migrating: "Nothing to migrate." |
| `migrate --step` (local only, after a `mysqldump` backup) | 0 | #19–#57 `DONE`, about 3.1 s in total |
| `permissions:sync --dry-run` | 0 | "Permissions are already up to date." |
| `hr:map-project-centers --dry-run` with the stand-in HR | 0 | Matched 0 · No center 28 (no centers in the stand-in) |
| `strategy:import-pillars database/seeders/data/strategy-2026-2030.json --dry-run` | 1, then **0 after migrating** | before: `strategic_pillars` missing (#34 pending). After: dry run printed the pillar/program plan; "Dry run: nothing was changed." |
| `meal:import-framework database/seeders/data/logframes/micro-urban.json --dry-run` | 1 with the stand-in HR, **0 with the real HR** | stand-in: refused ("Program 2 has the HR code sf1600 …"). Real HR (2026-10-22): runs; "Results already in the program framework, left as they are: 3, 4, 19, 20, 21."; "Dry run: nothing was changed." |
| `meal:import-framework database/seeders/data/logframes/micro-agri.json --dry-run` | 1, then **0 with the real HR** | same as urban |
| `meal:seed-indicator-library --dry-run` | 0 with the stand-in, **1 with the real HR** | stand-in: 35 proposed entries. Real HR: stops on duplicated codes 003 and 004 (G7): the expected step-6 behaviour while D-10a is open |
| `hr:map-project-centers --dry-run` with the **real HR** (2026-10-22) | 0 | Matched 10 · Ambiguous 1 (project 19) · No center 13 · **Conflicts 4 (9/25 at ADISC, 11/26 at HDISC; G6)** · Already mapped 0 · Duplicated program codes 3 |
| `monthly-reports:backfill --from=2026-10 --dry-run` | 1, then **0 after migrating** | before: `programs.donor_attribution` missing (#33 pending). After: 26 projects listed, 20 with months, **74 months**: exactly PRE-27a's 20 / 74; the 0-month projects 11, 20, 23, 25, 26, 27 = PRE-27b |
| `monthly-reports:prepare --dry-run` | 1, then **0 after migrating** | after: "Dry run: nothing was written." |
| `files:encrypt-existing --dry-run` | 1 | "File encryption is not configured. No file was changed." (no key on the PC, by design) |

**Local end-to-end rehearsal (2026-10-22).**
- Part A steps 0–13 were run on a throwaway local copy, made from the real-data local DB and rolled back to the baseline. HR was the real HR dump, read-only.
- Results are in `rehearsal-report.md` §0. It found G10, G11 and the `files:encrypt-existing` prompt.
- It is not a production rehearsal: local data, no files, and steps 7, 8 and 8a were not run (their commands did not exist yet).

---

## 3. Before you start

Do each item. Write "done" with the time in the report.

1. **Environment.** A closed host with no route from the internet and no outgoing mail, restored from a fresh production backup. Dev 1 names the operator (G8). Never give hosts, users or keys to T27.
2. **Safe rehearsal `.env`** (key names only; the operator sets the values):
   - `APP_ENV=production`, `APP_DEBUG=false`
   - `MAIL_MAILER=log`, `BROADCAST_CONNECTION=log`
   - `QUEUE_CONNECTION=database`, with **no queue worker and no scheduler** on the copy.

     If production uses `sync` (T29 topology b), queued listeners would run inline during the rehearsal, so the copy must still use `database`. T27 recommendation; Dev 1 confirms. Copied `jobs` never run.

     Record the `jobs` and `notifications` counts (PRE-20, PRE-20b).
   - `HR_DB_*` pointing at an HR snapshot or a read-only replica, never production HR read-write.
   - `FILE_ENCRYPTION_KEY` and `FILE_ENCRYPTION_KEY_ID`: a **throwaway** key made on the rehearsal host and destroyed with the copy. Never the production file key.
   - `APP_KEY`: decision **D3**.
3. **Database rights.** Migrations need ALTER, INDEX, REFERENCES and CREATE. The operator confirms the production user has them, or names a separate migration account. No credentials in any document.
4. **Disk.** Free disk ≥ 1.5 × `storage/app/private` (PRE-17 bytes plus the folder size), and decision **D4**.
5. **Backups, before each step marked ⛁:**
   - `mysqldump` (or `mariadb-dump`) `--single-transaction --routines --triggers <db> > <backup-dir>/<db>-<step>.sql`;
   - an archive of `<app-path>/storage/app/private`;
   - a copy of `.env` in secure storage.

   Every step that writes is marked ⛁; step 1 only reads. Write the backup point (file and time) in the report row of the step. **A backup counts only after one restore** into a scratch schema with matching row counts (PRE-20).

---

## 4. Part A: rehearsal

Rules for every step:
- One action per line, in this order.
- Every command with `--dry-run` runs **dry → real → again**, and the second real run must change nothing.
- Paste every output into the report (counts and ids only) and time every step.
- **Pass = end to end with no manual intervention.** A manual fix fails the rehearsal: stop, log a defect for the owner, rerun from a fresh copy.

### Step 0: copy, checks, backup ⛁
1. Restore the copy. Set the safe `.env` (§3).
2. Run every **PRE** check. Paste the results.
3. Back up (§3 item 5) and test-restore.
- **Expected:**
  - PRE-00 = 74 / 0;
  - every other BLOCKING PRE is 0, or has a recorded decision: PRE-01, PRE-02, PRE-03 (T03 aborts), PRE-05 (drafts with dates), PRE-09 (duplicated HR code on a program with indicators), PRE-11 (T13 aborts).
  - PRE-07 is a warning: compare it with step 4.
- **Stop if** PRE-00 differs, or a blocking PRE is non-zero without a decision.

### Step 1: clear and list
1. `<php> artisan optimize:clear`
2. `<php> artisan migrate:status`
- **Expected:** the pending list equals the 57 migrations of §2, in order (or the CP5 inventory).
- **Stop if** a migration is missing or extra: name its owner.

### Step 2: permissions (T02; D-09a, D-23, D-31b) ⛁
1. `<php> artisan permissions:sync --dry-run`
2. `<php> artisan permissions:sync`
3. `<php> artisan permissions:sync` again
- **Expected:** only "Permission added" / "Role added" lines; the third run prints "Permissions are already up to date."
- **Invariant:** no PRE role→permission pair removed (diff PRE-18b).
- **Stop if** a pair was removed.

### Step 3: migrations ⛁
1. `<php> artisan migrate --pretend`. Review the DDL. It stops at #24 (G5); that is expected.
2. `<php> artisan migrate --force --step`. Capture the full output. Each migration prints `DONE`; #13 and #15 print counts.
3. Review #24–#57 from this output (they could not be previewed).
- **Expected:** every migration `DONE`; POST-10 = 0 / 0.
- **Stop if** any migration fails. A T03 or T13 guard throw on dirty data: record the ids, stop, defect for the owner.

Invariants after step 3:
- **3a** T03 (#2–#7): POST-01 = 0, POST-02 = 0, POST-03 = 4, POST-04 = 1. **Stop if** different.
- **3b** T04 (#13): POST-05 `final_reports_left` = 0 and `retire_notes` = PRE-06. **Stop if** different.
- **3c** T05 (#15): POST-06 = 0 / 0 / 0. Re-run PRE-04 and PRE-20; these are the "after 3c" counts POST-16 compares with. **Stop if** different.
- **3d** the rest: POST-07 = 0, POST-08 = 6 / 5 / 6, POST-09 = 0 / 0 / 0, POST-11 diff as expected. **Stop if** any differs.

### Step 4: HR centers (T30) ⛁. The authoritative D-30a check
1. `<php> artisan hr:map-project-centers --dry-run`
2. Compare it with the matching management approved (D-30c). Compare its **Conflicts** section with PRE-07.
3. `<php> artisan hr:map-project-centers --apply`
4. `<php> artisan hr:map-project-centers --apply` again
- **Expected:**
  - every conflict has a recorded decision (G6);
  - the second apply changes nothing;
  - POST-12a = the accepted unmapped list, conflicts included; POST-12b = 0.
- **Stop if** the dry run differs from the approval, or a conflict has no decision.

### Step 5: strategy pillars and the Micro framework (T32) ⛁, **before step 6**
Run only once MEAL has approved `handoffs/T32-data.md` (G8). Urban before agri, so the agri serials continue after urban:
1. `<php> artisan strategy:import-pillars database/seeders/data/strategy-2026-2030.json --strategy=<id> --dry-run`, then real, then again
2. `<php> artisan meal:import-framework database/seeders/data/logframes/micro-urban.json --program=<hr id> --dry-run`, then real, then again
3. `<php> artisan meal:import-framework database/seeders/data/logframes/micro-agri.json --program=<hr id> --dry-run`, then real, then again
4. Run POST-13 now.
- **Expected:** created / updated / conflicts as printed; POST-13 matches them; codes `002.<level_ref>.<serial>`.
- **Stop if** the conflicts differ from `handoffs/T32.md`.

### Step 6: Indicator Library (T11) ⛁
1. `<php> artisan meal:seed-indicator-library --dry-run`. MEAL reviews the proposed codes. It exits non-zero while an HR program code is duplicated (PRE-09).
2. `<php> artisan meal:seed-indicator-library`
3. again: it must print "Every indicator is already in the library."
- **Expected:** POST-14 = 0; `activities` unchanged (PRE-20).
- **Stop if** it stops on a duplicated code.

### Step 7: 2026 annual plans (T33) ⛁: not rehearsed (G2)
1. `<php> artisan annual-plans:ensure-2026 --dry-run`, then real, then again
- **Expected:** POST-15 = 0 / 0; other plans unchanged.

### Step 8: report templates for active projects (T33) ⛁: not rehearsed (G3)
1. `<php> artisan report-templates:prefill-active --dry-run`, then real, then again
2. Re-run PRE-20 and PRE-20b.
- **Expected:**
  - POST-16 = 0 / 0; projects by status = the counts after 3c; unlinked targets listed.
  - A project with no lines to plan (no library-linked targets of donor-approved proposals and no `HC.*` annual-plan targets) gets no template and is not in the `/report-templates` queue. Its unlinked targets are still printed.
  - `activities` unchanged (PRE-20): the command runs under `Activity::withoutRecording()`.
  - The first real run adds `report_template.pending` notifications by design: record their count. The second run adds none.
  - The command ends with the list of lines missing `fixed_denominator`. Step 8a works through it.
- **Stop if** a project status changed.

### Step 8a: `target_beneficiaries` (D-25) ⛁: a human data-entry step, not rehearsed (G3)
1. The PR Manager enters the missing `target_beneficiaries` through T06's post-approval edit. This is not a command.
2. `<php> artisan report-templates:prefill-active --dry-run`. Lines that the real run would now fill are counted as "would be refreshed" and leave the missing list.
3. `<php> artisan report-templates:prefill-active`. It sets `fixed_denominator` on each still-`pending` fixed-basis line whose value is NULL and whose `target_beneficiaries` is now filled. Confirmed and retired lines are never touched.
4. Repeat 1–3 until the list says "none", or only accepted lines remain.
- A **confirmed** line with no `fixed_denominator` stays in the list. Only MEAL can fix it, by editing the line on the project's template tab.
- **Expected:** the list is empty, or Dev 1 and MEAL accepted each remaining line in the report. POST-21 is the wider D-25 work list (informational).
- **No-Go if** the list is non-empty and not accepted.

### Step 9: monthly report backfill (T17A) ⛁
1. `<php> artisan monthly-reports:backfill --from=2026-10 --dry-run`
2. the same without `--dry-run`, then again
- **Expected:** POST-17 = a 0, b 0, c = PRE-27a `expected_months`, d 0.
- **Stop if** a, b or d is non-zero.

### Step 10: encrypt existing files (T22) ⛁, or the recorded deferral (D4)
1. Take the **pre-step-10 backup** (§3 item 5). Step 13's rollback test starts from it.
2. Free disk ≥ 1.5 × `storage/app/private`.
3. `<php> artisan files:encrypt-existing --dry-run`
4. `<php> artisan files:encrypt-existing`, then again (it resumes; nothing left). Each real run asks for confirmation in production: type `yes`.
- **Expected:** POST-18 = 0 except the rows the command reported missing; no `*.enc.tmp` left in `storage/app/private`; reference-only rows reported, never encrypted.
- **Stop if** decryption errors are systematic.

### Step 11: everything again ⛁
1. Run PRE-20 and PRE-20b.
2. Re-run steps 2 and 4–10, real runs only.
3. Run PRE-20 and PRE-20b again (POST-19).
- **Expected:** zero changes; every count identical before and after.
- **Stop if** a second run writes.

### Step 12: verify ⛁ (the test approval writes)
1. Run every **POST** check. Paste the results.
2. Open the main pages: dashboard, projects, proposals, results, indicator library, admin sectors.
3. Download one file of each type.
4. Approve one test proposal end to end. Then re-run PRE-20b: a queued `PrefillReportTemplates` job is expected and never runs (no worker).
- **Expected:** untouched tables equal PRE-20 (POST-19 list).
- **Stop if** the approval path breaks.

### Step 12a: self-funded relinking (D-27a) ⛁
1. Run POST-20 (= PRE-26; ids only).
2. Dev 1 decides per id: link it to «تمويل ذاتي — Hope» through a manual **Create Proposal**, or leave it. No new command, no DML.
- **Expected:** every id has a recorded decision.
- **Stop if** an id is undecided.

### Step 13: rollback tests, then delete the copy ⛁
After step 10, `migrate:rollback` **cannot** reach the baseline: #14's `down()` throws once any file row has `encryption_key_id`. So the rollback is proven in two parts:
1. **Restore proof** (the production method, Part C): restore the step-0 backup, database **and** `storage/app/private` together, into the copy. Time it, and verify PRE-20 counts, one download and one login.
2. **`migrate:rollback` proof**: restore the **pre-step-10 backup** (no file encrypted yet), then `APP_ENV=local <php> artisan migrate:rollback --step=57 --force` (or the CP5 count). `APP_ENV=local` applies to this one command on the copy only (G10). Needs the G11 fix (on this branch).
   - Expected: #15 and #13 are no-ops (statuses stay).
   - #50 succeeds only while no `reports.value` is NULL (none before go-live).
   - #26 succeeds only while no proposal-only milestone exists.
   - Record anything else that fails.
3. Forward again: `<php> artisan migrate --force --step`; re-run the 3a–3d invariants.
4. Record all three timings.
5. The operator deletes the copy, its backups and the throwaway file key, and confirms in the report.

---

## 5. Part B: production order (draft for T29)

Same order as Part A, with these differences. T29 turns this into the final checklist with the rehearsal timings.
1. Announce the window. Back up (§3 item 5) and test-restore.
2. `<php> artisan down --render="errors::503"` (the app's own 503 page). In maintenance mode, queued jobs and scheduled tasks do not run.
3. Deploy the release code; composer and npm per T29.
4. Steps 1–12 of Part A, with the real `.env`: the production `FILE_ENCRYPTION_KEY`, backed up off the server before step 10 (D-19, CP6 gate), and real `MAIL_MAILER` and `BROADCAST_CONNECTION`. No `--pretend` review; that was done in the rehearsal.
5. `<php> artisan optimize`
6. `<php> artisan queue:restart`; `<php> artisan reverb:restart` (or restart the Reverb program in Supervisor, per the "After every deploy" note in `docs/superpowers/plans/2026-09-23-notifications.md`).
7. `<php> artisan up`
8. T29's smoke tests and 48-hour monitoring.

Production rollback is a **restore** (Part C), never `migrate:rollback`.

---

## 6. Part C: rollback

### Triggers
Decision owner: Dev 1 with management. Thresholds are filled in after the rehearsal.

| Trigger | Threshold |
|---|---|
| A migration fails | any failure that is not a recorded, decided PRE case |
| Systematic decryption errors (`UnreadableStoredFile` logs: table, row id, key id) | > `<n>` files, or any whole file table |
| The approval path breaks (`ApproveProposal`) | the test approval in step 12 fails |

### What `migrate:rollback` cannot undo (copy only)
- **#13 (T04) and #15 (T05):** documented no-op `down()`s; status changes stay.
- **#2 (T03):** drops `proposals.donor_id`; the backfill is lost (recomputed on the next `up`).
- **#14 (T22):** throws once any file row has `encryption_key_id`, so a rollback after step 10 stops there.
- **#26 (T31):** throws once proposal-only milestones exist.
- **#50 (T17A):** fails (or coerces) once any `reports.value` is NULL.
- **Lossy drops:** #4, #8, #9, #33, #43, #51, #56 and #57 drop data entered after the release.
- **`transfers_tracking`** (`2026_09_14_111851`, pre-baseline): its `down()` fails once NULL received amounts exist. It is only reached past the baseline.
- `permissions:sync` and the fill commands (steps 4–10) are not undone by `migrate:rollback`.

### Production rollback steps
1. `<php> artisan down --render="errors::503"`
2. Restore the database **and** `storage/app/private` **together**, from the same backup point. Rows carry `encryption_key_id`; the old code would serve ciphertext if only one were restored.
3. Deploy the previous release; composer / npm per that release.
4. `<php> artisan optimize`
5. `<php> artisan queue:restart`; `<php> artisan reverb:restart`
6. `<php> artisan up`
7. Verify: the PRE-20 counts match the backup; one file downloads; one login works.

Record the restore duration measured in step 13.1.

### Data-loss window and message
Writes made after the final backup are lost. Message to users:
- en: "We restored the system to its state before the update. Changes made after <time> need to be re-entered."
- ar: «أعدنا النظام إلى حالته قبل التحديث. يلزم إعادة إدخال التغييرات التي تمت بعد <time>.»

---

## 7. Human items before go-live
- The MEAL Manager enters the governorate and project officers (D-12a).
- An admin sets `proposal_default_reviewers` to HR positions.
- MEAL confirms the pending report templates; their lines appear only after confirmation (D-45).

## 8. Never
On the copy as on production:
- Never run `RolesAndPermissionsSeeder`, `db:seed`, `migrate:fresh`, `migrate:refresh`, `migrate --graceful` or `key:generate`.
- Never point the test suite at the copy: `RefreshDatabase` wipes it.
- Never write DML or DDL by hand. A data fix no task owns is listed, never written.
- Never call a manual fix "passed".
- Never put secrets, personal data or amounts in any document.

Superseded, do not build: `report-templates:seed-existing` and `donor_attribution_confirmed_at` (replaced by `report-templates:prefill-active` and T13's setting); importing the Syria Cross / Children University workbooks (D-54).
