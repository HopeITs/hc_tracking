# Task 27 — Data migration rehearsal and rollback plan

> **المهمة:** بروفة ترحيل البيانات وخطة التراجع
> **المطوّر:** Dev 1 (الفريق B مساندة بالإصلاحات فقط، بلا وصول للإنتاج) · **الفريق:** A · **الأيام:** 12–13 (الثلاثاء 2026-10-27: البروفة الأولى ← الأربعاء 2026-10-28 صباحًا: البروفة النهائية) · **الجهد:** يوم واحد · **يعتمد على:** كل مهام الترحيل (03، 04، 05، 09، 10، 11، 12، 17، 19، 22، 30، 31)، وأي مهمة أخرى أضافت ترحيلًا أو أمرًا (07، 15، 23). Task 24 و25 خارج نافذة أكتوبر.
> **متى تستخدمه:** يوم البروفة الأولى (اليوم 12، 2026-10-27) مع تجميد الميزات CP5 (نهاية اليوم 12) بعد دمج كل المهام التي تضيف ترحيلات أو أوامر، وبعد أن يجهّز شخص مخوّل نسخة من الإنتاج (القاعدة + التخزين) على بيئة معزولة؛ ثم البروفة النهائية صباح اليوم 13 قبل نقطة Go/No-Go (CP6، نهاية اليوم 13).
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are preparing Task 27 of the HC Tracking proposal-lifecycle correction plan: the data-migration rehearsal on a copy of production, and the rollback plan. You are a preparer, not an operator. You write read-only check SQL, the rehearsal procedure, the runbook and the rollback plan, and you fill the rehearsal report from outputs a human pastes to you. You never connect to production or to the rehearsal copy, and you never execute anything there; an authorised person runs every step. This matters because the release rewrites the core of the corrected story on live data (project = HR center, one donor and at most one agreement per proposal, draft projects without dates, the proposal state machine, Indicator Library, the four-level logframe, proposal milestones and department tasks, auto-generated monthly reports, aggregated donor reports, auto-generated transfer records, encrypted files). Task 29 deploys from your runbook, so it needs measured durations, verified before/after counts, and a rollback that was actually tried at least once.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md` — Arabic and authoritative; English command, table, column, status and permission names are exact. Read `### Task 27` and `### Task 29`. Read "Data integrity", "Exceptional and edge cases" and "Output and handoff" of Tasks 03, 04, 05, 07, 09, 10, 11, 12, 15, 17, 19, 22, 23, 30 and 31, including each `#### تعديلات المراجعة 2 (2026-10-08)` block (Tasks 24 and 25 are out of the October window; Task 20 is merged into Task 31). Also read section 0 assumption 4 (local counts: 38 proposals, 36 projects, 61 indicators, 61 targets, 33 reports; 26 HR centers in 6 cities, no center for cities 7, 8, 12, 13, 14, 17), risks R9, R10 and R12, «قواعد العمل بين الفريقين» (Team B has no production access), section 5, section 6 (days 12–13), section 7 (CP5, CP6), section 8 "جاهزية النشر", and section 9 D-19, D-24, D-27, D-29, D-30, D-31.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` (if it exists; a recorded decision overrides the plan's default for D-xx). Its pre-check appendix holds Task 01's production results. Reuse those check ids and compare against them.
4. Rules:
   - `.ai/rules/console-commands.md`: never run `RolesAndPermissionsSeeder` on a live database, and run `php artisan optimize:clear` before `permissions:sync`.
   - `.ai/rules/database-migrations.md`: `donor_proposal.proposal_id` is RESTRICT, and the `transfers_tracking` `down()` fails once NULL rows exist.
   - `.ai/rules/migrations.md` and `.ai/rules/feature.md`: utf8mb4_unicode_ci on InnoDB DYNAMIC. MariaDB json columns are utf8mb4_bin by design.
   - `.ai/rules/config.md`: the production DB user is least-privilege and never root.
   - `.ai/rules/database-seeders.md`: bulk imports run under `Activity::withoutRecording`.
   - `.ai/rules/env-example-gitignore-htaccess-gitleaks-toml-gitleaksignore-gitlab-ci-yml.md`: no secrets in committed files.
   - `.ai/rules/commands-seeders.md` and `.ai/rules/tests.md`.
5. Source (read only):
   - Every migration added since the plan baseline: `git diff --name-only <baseline>..HEAD -- database/migrations`. The baseline is `main` at the plan date (`0a21efc5`) unless the developer names another.
   - `app/Console/Commands/`. Existing commands: `permissions:sync {--dry-run}` (`SyncPermissions`), `security:reencrypt {--dry-run}` (`ReencryptSecrets`), `files:prune {--dry-run}` (`PruneStoredFiles`), `hr:sync-users`, `notifications:test`. New commands: `hr:map-project-centers {--dry-run} {--apply} {--force}` from Task 30; `meal:seed-indicator-library {--dry-run}` from Task 11; `monthly-reports:backfill {--from=YYYY-MM} {--dry-run}` and the scheduled `monthly-reports:prepare` from Task 17; `files:encrypt-existing {--dry-run} {--chunk=100}`, `files:decrypt {--id=}` and `security:reencrypt --files` from Task 22.
   - `routes/console.php` (`files:prune` runs daily at 03:00), `config/filesystems.php` (the private `local` disk lives at `storage/app/private`), `config/queue.php`, `.env.example`, `phpunit.xml`, `app/Support/StoredFiles.php`, `resources/views/errors/503.blade.php`.
   - The Supervisor/cron/"After every deploy" notes near the end of `docs/superpowers/plans/2026-09-23-notifications.md`.
6. Skills: `database-migrations`, `laravel-security`. Use Boost `database-schema` and `database-query` on the LOCAL database only. Use `search-docs` for `migrate --pretend` / `--step`, maintenance mode, `queue:restart` and `reverb:restart`.

## Preconditions to verify
- CP5 (feature freeze, end of day 12) is declared, and every task that adds migrations or commands is merged. Build the migration inventory from git and compare it with the plan list. If a task's migration is missing, or exists only on an unmerged branch, stop and report.
- Run `php artisan list` locally. It must show `hr:map-project-centers` (Task 30), `meal:seed-indicator-library` (Task 11), `monthly-reports:backfill` and `monthly-reports:prepare` (Task 17), `files:encrypt-existing` and `files:decrypt` (Task 22). `php artisan security:reencrypt --help` must show `--files`. Every command the plan runs with `--dry-run` must accept it. If one is missing, stop and name the owning task.
- `.env.example` lists `FILE_ENCRYPTION_KEY` and `FILE_ENCRYPTION_KEY_ID` with empty values (Task 22).
- The developer confirms that a closed rehearsal environment exists, and who operates it. You have no access to it. Never ask for hostnames, credentials or keys.
- Task 30's matching report (`hr:map-project-centers --dry-run`, sent to management and the HR team from day 3) has a management review; the HR team has added the missing centers or a manual mapping is agreed (R10, D-30).
- Task 01's production pre-checks are recorded. If a blocking check was non-zero and is still unresolved, say so in your plan: the rehearsal cannot pass until management decides.

## Revision 2 changes (2026-10-08)
- Days 12–13 of the 15-day window: first rehearsal on day 12 (Tue 2026-10-27, CP5 feature freeze at its end), final rehearsal on the morning of day 13 (Wed 2026-10-28), then CP6 Go/No-Go at the end of day 13 and deployment on day 14 (Thu 2026-10-29, Task 29). Dependencies now include Tasks 09, 10, 30 and 31; Task 20 is merged into 31; Tasks 24/25 are out of the window.
- New run steps, in this order: `migrate` → `permissions:sync --dry-run`, then real → **`hr:map-project-centers --dry-run`, then `--apply` only after management reviews the matching** → `meal:seed-indicator-library --dry-run`, then real → **`monthly-reports:backfill --from=2026-10 --dry-run`, then real** (existing active projects, from the deploy month only, D-29) → `files:encrypt-existing --dry-run`, then real.
- New PRE checks: projects without an HR center (`center_id IS NULL`), centers with more than one open (`draft`/`active`) project, and project cities with no HR center (cities 7, 8, 12, 13, 14, 17). New POST checks: open projects without a center equal the list management accepted; no center with two open projects; every project `active` at deploy has one `monthly_reports` row per month from 2026-10 to its end month and no duplicates; a second backfill run creates nothing.
- Team B (B1–B4) has no production or rehearsal access: they fix failures in their own tasks on masked data; the rehearsal is then re-run from a fresh copy. The R9 phased release (new modules hidden by permissions) and the deferral of the existing-file migration (R9 item 5) are valid fallbacks to document in Part B.
- Notifications: the rehearsal must not reach MEAL or General Management — the copy has no queue worker and no scheduler, so `monthly_reports.generated` from the backfill and any Task 21 catalog event stay in `jobs`/`notifications` only; record the counts.

## Revision 3 changes (2026-10-09)
Source: `Stratigy.pptx` (strategy 2026–2030) and `micro logic.xlsx` (the real Micro program logframe, urban and agricultural tracks). Read plan section 1.0 facts 10–12 and the «تعديلات المراجعة 3» subsection of this task. Where this block conflicts with text further down this prompt, this block wins.
- **Results-chain integrity check on the production copy:** count results that break "parent exactly one level up" — the dev database has an impact under an output (results 32 and 40) and an output under an output (31 and 43). Do not auto-fix (observers rule); list them for MEAL before go-live to fix by hand or keep with an «out of chain» badge. Task 12 guards will reject edits to these nodes.
- **Rehearsal order:** run `strategy:import-pillars` and `meal:import-framework` (with the MEAL-approved JSON files) after the Task 11, 12 and 32 migrations and **before** `meal:seed-indicator-library`, so legacy entries are not created for indicators that have reference entries.
- Existing percentage reports have no numerator/denominator. No backfill: they are excluded from pooling and counted (Tasks 18 and 19).

## Revision 4 changes (2026-10-12)
Source: plan header paragraph «المراجعة 4», D-45, the «تعديلات المراجعة 4» subsection of this task and `### Task 33`. Where this block conflicts with the Revision 2/3 blocks or the base sections, it wins.
- **Existing active projects (D-45) stay `active`.** Add the step `report-templates:seed-existing --dry-run` then the real run (Task 33): it creates one template per open dated project with `pending` items from its library-linked targets and puts them in MEAL's **Template Queue**. Their monthly reports are generated from the deploy month (D-29), and their lines appear only after MEAL confirms, showing «بانتظار تأكيد نموذج التقرير من MEAL» until then.
- **Order:** after the Task 11/12/32/33 migrations and the library commands, and after MEAL has confirmed every program's `donor_attribution` (Revision 5 below); before `monthly-reports:backfill`.
- **Seeds reviewed by MEAL before the rehearsal:** the evidence types (the five examples plus the Syria Cross list from Revision 8) and the default narrative questions (monthly: summary, key achievements, challenges, next steps; quarterly: «ما الذي تغيّر في المجتمع المستهدف هذا الربع؟»).
- **POST checks:** templates = open projects with dates; no template item without `library_indicator_id`; no `plan_indicator` linked to two items; no `active` project created after deploy without a confirmed template.
- **Project `approved` is legitimate again (Revision 4):** the defensive Task 05 migration keeps dated `approved` projects as `approved` (they enter the template queue) and moves `submitted` (and dateless `approved`) rows. So the blocking PRE check on projects becomes "`submitted` projects, and `approved` projects without dates", not "any `approved`"; the POST check becomes "no `submitted` project; every `approved` project has dates and a draft template".

## Revision 5 changes (2026-10-12)
Source: management note on co-funded indicators, recorded as **D-46** (amends D-40). Read plan section 1.0 facts 14 and 18, decision row D-46, and the «تعديلات المراجعة 4» and «تعديلات المراجعة 5» subsections of this task in the plan. Where this block conflicts with text further down this prompt, this block wins.
- An indicator shared by several donors in one center follows its program's mode, `programs.donor_attribution` (`App\Enums\DonorAttribution`: `shared`, `split`; default `shared`). `shared` (e.g. DESC: 30 students, one report, 30 for each donor) = D-40. `split` (e.g. Micro: 10 beneficiaries = 6 for donor 1 + 4 for donor 2, a separate report per donor): the center manager enters each donor's share monthly, each donor's mirror carries only its share, and the center's achievement is the sum. A beneficiary has exactly one funder, so there is no separate total field and no reconciliation check.
- Before running `report-templates:seed-existing`, MEAL sets each program's mode (default `shared`, Micro `split`), stored with `programs.donor_attribution_confirmed_at`. The real run (not `--dry-run`) refuses while any program with a multi-donor active project is unconfirmed, and lists those programs.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-02, D-12, D-20, D-23, D-27, D-30, D-31, D-43, D-48, D-49, D-50, D-51, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- Earlier revisions: `final reports` → `active` (D-02); revoke `targets.*` from GM/Managers (D-23); resolve centers with more than one open project per program (D-30); no tranche schedule for existing agreements (D-31); seed MEAL coverage from the MEAL Manager's list (D-12); seed sectors (Task 34); `entered_by` from the library (D-48); existing proposals start without an activity calendar (D-49).
- D-20a: list every account with Super Admin and confirm with management that none is an operational staff account.
- D-31b: `permissions:sync` for the new PR Manager agreement permissions.
- D-50: existing indicators `is_disaggregated = false` until MEAL sets them; no retroactive breakdown. D-51: no historical activity statuses.
- D-43a: copy bank questions into each template as it is created (D-45). D-27a: create the internal donor once; link existing self-funded proposals from a PR list.
- ~~Rehearsal: import the Children University and Syria Cross workbooks as two test proposals~~ — superseded by D-54 in Revision 9 below (seeded data only).

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
- **D-10a:** before seeding the library, re-check `programs.code` uniqueness in production HR (Task 30 report); stop if a program with indicators still has a duplicated code. Run `meal:seed-indicator-library --dry-run`, have MEAL review the proposed codes, then run it.
- **D-25:** backfill `project_proposal.target_beneficiaries` from `projects.target_beneficiaries` only where the project has a single proposal; otherwise output a list for PR to complete before templates are confirmed (D-45). Old reports are untouched.
- **D-09a:** `permissions:sync` adds `reports.reopen` to Public Relations Manager.
- **D-35a:** map the four new programs (Wells, Alternative Energy → pillar 1; PSS & Case Management, Parenting → pillar 3) if HR has added them; otherwise list them in the mapping report.
- **D-54:** do not import the Syria Cross / Children University files in the rehearsal (replaces the Revision 8 rehearsal item); test disaggregation and activity statuses with seeded data.
- **S-1:** seed the six sectors. **D-12a:** no existing coverage data; the MEAL Manager enters governorate and project officers before go-live (runbook item).

## Scope
### In scope (documents under `docs/superpowers/specs/`; confirm the names in plan mode)
- Create `2026-10-27-proposal-lifecycle-prechecks.sql`. It holds read-only `PRE-xx` checks (run before the first step) and `POST-xx` checks (run after the last step).
- Create `2026-10-27-proposal-lifecycle-runbook.md` with:
  - Part A: the rehearsal procedure.
  - Part B: the production sequence, as a draft that Task 29 finalises.
  - Part C: the rollback plan.
  - The migration and command inventory: run order, owning task, pre-check guard inside `up()`, backfill, lossy or failing `down()`, and size risk.
- Create `2026-10-27-proposal-lifecycle-rehearsal-report.md`. It is a template that you fill from the operator's pasted outputs: timings per step, counts before/after, errors, the idempotency re-runs, the rollback test, the copy-deletion confirmation, and sign-off.
- If Task 22 already documented the backup of `FILE_ENCRYPTION_KEY` (search `docs/` for it), link to that instead of repeating it.
### Out of scope
- Executing anything on production or on the copy. Humans do that; Task 29 deploys.
- Fixing a migration, command or seeder. That goes to the owning task in a new session, and then the whole rehearsal is repeated.
- Writing UPDATE/DELETE/DDL for any shared database. A data correction that management decides on becomes a reviewed migration or command in Task 03 or Task 05.
- The deployment checklist, production smoke tests and 48-hour monitoring (Task 29), and UAT (Task 28). Editing the plan.

## Business rules
- Any non-zero blocking pre-check stops the release until management decides. The blocking checks, from the plan:
  - proposals with more than one agreement;
  - proposals with no agreement;
  - projects in `submitted`, and `approved` projects without dates (Revision 4);
  - `draft` projects carrying `start_date` or `end_date`;
  - centers with more than one open project **of the same program** (D-30 as amended in Revision 6), unless management records a decision.
- `hr:map-project-centers --apply` runs only after management has reviewed the dry-run matching (ambiguous and unmatched projects listed).
- Every command that has `--dry-run` runs dry first and then for real. Both outputs go into the report.
- Take a full backup (database + storage) before each step, and check integrity after it.
- Record the start and end time of each step. These times size the maintenance window.
- The rehearsal passes only if it runs end to end without manual intervention. A manual fix means it failed: the fix goes to the owning task, then the rehearsal runs again from a fresh copy.
- The rollback is tried at least once. The copy is deleted after the rehearsal, and the operator confirms this in the report.
- On the copy, as on production: no `RolesAndPermissionsSeeder`, no `db:seed`, no `migrate:fresh`, no `migrate:refresh`.

## Security and authorization
- The SQL is `SELECT` only (including `information_schema`): no DDL, no DML, no `INTO OUTFILE`, no procedures. Recommend a read-only DB user.
- Results record counts and record ids only. No names, emails, amounts, account numbers or file names.
- The environment is closed, and production keys never reach developer machines.
  - The rehearsal `FILE_ENCRYPTION_KEY` is a throwaway key generated on the rehearsal host. It is never the production key, and it is destroyed with the copy.
  - The copy holds APP_KEY-encrypted columns: `users.two_factor_secret` and `two_factor_recovery_codes`; `accounts.account_number`, `iban`, `swift_code` and `authorized_person`. The operator chooses between two options and records the choice: keep the production APP_KEY on the closed host only, or rehearse without it and list which pages and logins cannot be checked. Never run `key:generate` on the copy.
- The rehearsal `.env` neutralises every outbound effect, so the copy never emails, notifies or pushes to real staff:
  - `MAIL_MAILER=log` and `BROADCAST_CONNECTION=log`;
  - no queue worker and no scheduler cron (the `jobs` rows copied from production must never run);
  - `HR_DB_*` pointing at an HR snapshot or a read-only replica (needed by `hr:map-project-centers` and `EmployeeProfile`; never writable);
  - `APP_ENV=production` and `APP_DEBUG=false`.
- Migrations need ALTER, INDEX, REFERENCES and CREATE on the schema. Ask the operator to confirm the production DB user has them. If it does not, the runbook uses a separate migration account that the operator supplies. Never write credentials into any document.

## Validation
No user input. Validate the deliverables:
- Every SQL check has an id, a purpose, an owning task, a blocking/informational flag and an expected result, and it runs on both MariaDB and MySQL.
- Every runbook step has the command (with `<placeholders>`), the expected output, the measured duration, the backup point and the action to take on failure.
- Every command in the runbook exists in `php artisan list`, with the flags shown.

## Edge cases and concurrency
- **A migration fails midway.** MySQL/MariaDB do not roll back DDL, so the migration may be half applied. Stop, record the migration and the failing statement, and restore from the backup. Never hand-patch the schema.
- **Data guards stop a migration.** Task 03's migrations throw before any DDL and print offender ids. That is the expected outcome on dirty data: record it and stop.
- **Rollback proof.** Prove each `down()` on the copy with `migrate:rollback --step=<n>` back to the baseline, then migrate forward again. Flag lossy reversals: Task 05's status conversion, backfilled columns, and `transfers_tracking`. On production, rollback means restoring the backup. Evaluate `migrate --force --step` so that each migration is its own batch.
- **`files:encrypt-existing` is interrupted.** A re-run skips rows that have `encryption_key_id`. Check that no `*.enc.tmp` file is left behind. Report the counts of skipped rows: reference-only `report_files` / `task_attachments` rows (`original_name IS NULL`) and rows whose file is missing on disk.
- **Disk space.** Encrypted files are about a third larger, and temp copies need room as well. Free space must be at least 1.5× the size of `storage/app/private`.
- **Encryption takes longer than the window.** Document the alternative: run it after `up`, because legacy files stay readable while `encryption_key_id IS NULL`. Mind the daily `files:prune` at 03:00. Record the decision of Dev 1 and management.
- **Idempotency.** Run every fill command a second time. The second run must report zero changes.
- **`permissions:sync` only adds.** Diff the role→permission snapshot: no existing pair may be removed, and the new pairs must match the signed matrix (Task 02).
- **The fill commands create no `activities` or `notifications` rows** (database-seeders rule). Compare the counts.
- **HR unreachable.** Migrations are unaffected; record it.
- **`migrate --pretend`.** Use it only to review the DDL. It does not prove that backfills are correct.

## Data integrity
- **Backups:**
  - a DB dump (`mysqldump`/`mariadb-dump --single-transaction --routines --triggers`);
  - an archive of `storage/app/private`;
  - `.env` kept separately in secure storage.
  A backup counts only after it has been restored once into a scratch schema and its counts match.
- **PRE checks, blocking.** Use these four exactly: `SELECT proposal_id, COUNT(*) FROM donor_proposal GROUP BY proposal_id HAVING COUNT(*) > 1;`, proposals with no `donor_proposal` row, projects with `status = 'submitted'` or (`status = 'approved'` and a NULL date) (Revision 4), and `draft` projects with dates.
- **PRE checks, informational** (each needs a management note):
  - active projects without a donor-approved proposal (`approved`, `active`, `completed`, plus legacy `final reports` rows before Task 04's migration; this is Task 08's acceptance query);
  - proposals still in `final reports` (D-02: Task 04's migration moves them to `active` with an activity entry; record the count);
  - proposals with no row in `project_proposal`;
  - donor-approved proposals missing `actual_date_begin` / `actual_date_end`;
  - `internal_project_number LIKE 'TEMP-%'`;
  - status distributions of `proposals` and `projects`;
  - indicator names that exist with more than one `measure` (the expected conflict count for Task 11's command);
  - file rows per table: total, stored, and `SUM(size)`. For `media`, every row is stored; for `report_files` / `task_attachments`, stored means `original_name IS NOT NULL`;
  - the role→permission snapshot, and users per role (counts only);
  - row counts of every touched table, `activities`, `notifications`, `jobs` and `failed_jobs`;
  - `annual_plans` (expected 8; unchanged, Task 24 is out of the window);
  - projects without `center_id`, centers with more than one open project, project cities without any HR center (R10, D-30);
  - active projects at deploy and the number of months `monthly-reports:backfill --from=2026-10` should create.
- **POST checks:**
  - `proposals.donor_id IS NULL` = 0;
  - agreements whose `donor_id` differs from their proposal's = 0;
  - the unique index on `donor_proposal.proposal_id` and the composite FK are present by name in `information_schema` (names from Task 03's handoff);
  - no `submitted` project; every `approved` project has dates and a draft template (Revision 4); no `draft` project with dates; no proposal left in `final reports`;
  - every new table and column exists with utf8mb4_unicode_ci;
  - every new permission exists, and no PRE pair is missing;
  - `indicators.library_indicator_id IS NULL` equals the conflict count the command reported;
  - stored file rows with `encryption_key_id IS NULL` = 0, apart from the rows the command reported as missing;
  - counts of tables the release must not change equal their PRE values;
  - `activities`, `notifications`, `jobs` and `failed_jobs` are unchanged by the fill commands (record separately the grouped `monthly_reports.generated` jobs, if the backfill emits them);
  - projects without `center_id` equal the list management accepted; no center has two open projects of the same program;
  - every project `active` at deploy has exactly one `monthly_reports` row per month from 2026-10 to its end month (none earlier, no duplicates).

  Build the expected tables and columns from the actual migrations, cross-checked against the plan.
- Shape for the existence checks:
  ```sql
  -- PRE-05 (informational, Task 08): active projects without a donor-approved proposal
  SELECT p.id FROM projects p
  WHERE p.status = 'active' AND NOT EXISTS (
    SELECT 1 FROM project_proposal pp JOIN proposals pr ON pr.id = pp.proposal_id
    WHERE pp.project_id = p.id AND pr.status IN ('approved','active','final reports','completed')); -- 'final reports' only matters before Task 04's migration
  ```

## Implementation guidance
- Write the documents in Arabic, like the plan. Keep every command, SQL statement, table, column, status, permission and UI name in English. Use placeholders (`<db>`, `<app-path>`, `<backup-dir>`); never real hosts, users or paths.
- Part A steps:
  1. Restore the copy.
  2. Set up the safe `.env`.
  3. Run the PRE checks.
  4. Back up.
  5. `php artisan optimize:clear`.
  6. `migrate:status`.
  7. `migrate --pretend` (review).
  8. `migrate --force`.
  9. `migrate:status`.
  10. `permissions:sync --dry-run`, then `permissions:sync`.
  11. `hr:map-project-centers --dry-run`; management reviews the matching; then `--apply`.
  12. `meal:seed-indicator-library --dry-run`, then for real.
  13. `monthly-reports:backfill --from=2026-10 --dry-run`, then for real.
  14. `files:encrypt-existing --dry-run`, then for real.
  15. Re-run each command to prove idempotency.
  16. Run the POST checks.
  17. Open the main pages and download a sample of files of every type.
  18. Run the rollback test.
  19. Run forward again.
  20. Delete the copy.
- Part B follows Task 29's order, adapted to the rules:
  1. `php artisan down --render="errors::503" --retry=60`.
  2. Take the final backup (after `down`, so no write is lost).
  3. Pull the code.
  4. `composer install --no-dev --optimize-autoloader`.
  5. `npm run build`.
  6. `php artisan optimize:clear`.
  7. `migrate --force`.
  8. The dry-run/real pairs, in the Part A order (`permissions:sync`, `hr:map-project-centers` with the approved matching, `meal:seed-indicator-library`, `monthly-reports:backfill --from=2026-10`, `files:encrypt-existing` or its documented deferral).
  9. The POST checks.
  10. `optimize`.
  11. `queue:restart` and `reverb:restart` (or Supervisor).
  12. `up`.
  Put the rehearsal duration next to each step.
- Part C covers:
  - the triggers (a migration failure, systematic decryption errors, a broken approval path);
  - the decision owner;
  - the steps: `down` → restore the DB and storage TOGETHER (rows carry `encryption_key_id`, and old code would serve ciphertext) → the previous release → composer/npm → `optimize` → restart → `up`;
  - verification;
  - the data-loss window, and a user message in Arabic and English.

## Tests (Pest)
- Write no application tests.
- With the developer's agreement, run locally only:
  - `php artisan migrate:status`;
  - `php artisan migrate --pretend`;
  - the `--dry-run` commands (`permissions:sync`, `hr:map-project-centers`, `meal:seed-indicator-library`, `monthly-reports:backfill --from=2026-10`, `files:encrypt-existing`);
  - the command tests: Task 11's, Task 17's, Task 22's and Task 30's files (`grep -rl "meal:seed-indicator-library\|files:encrypt-existing\|monthly-reports:backfill\|hr:map-project-centers" tests`), plus `tests/Feature/SyncPermissionsCommandTest.php`, `tests/Feature/ReencryptSecretsCommandTest.php`, `tests/Feature/PruneStoredFilesTest.php` and `tests/Feature/DatabaseEncodingTest.php`, via `php artisan test --compact <file>`.
- Run every SQL check on the LOCAL database with Boost `database-query`, and label the results "local".
- The developer runs the full suite (`php artisan test --compact`) on the release commit. `phpunit.xml` targets `hc_tracking_test` with `RefreshDatabase`, so never point the suite at the copy: it would wipe it.

## Acceptance criteria
- [ ] The SQL file is `SELECT`-only, covers every check listed above, and every check ran locally with the results labelled.
- [ ] The runbook covers Parts A, B and C and the inventory, including `hr:map-project-centers` (after management review) and `monthly-reports:backfill --from=2026-10`; every command exists with the flags shown.
- [ ] The final rehearsal ran on the morning of day 13, before CP6.
- [ ] The report template has rows for each step's time, the counts before and after, the idempotency re-runs, the rollback test and the deletion of the copy.
- [ ] Once the operator's outputs are in, the report shows: the rehearsal passed without manual steps, the total duration is known, the rollback was tried once, and every blocking PRE check is 0 or has a management decision.

## Do not
- Run, or ask for access to run, anything on production or the copy.
- Skip `--dry-run`, run `RolesAndPermissionsSeeder` / `db:seed`, or use `migrate --graceful`.
- Write DML or DDL for shared databases.
- Put keys, credentials, personal data or amounts in any file or message.
- Treat a manual intervention as "passed".

## Quality bar (this task)
- The document is read by non-developers under time pressure: numbered steps, one action per step, the expected result next to it, and an explicit stop condition. Arabic text, English identifiers exact.
- Every check query is read-only and labelled `PRE-xx` / `POST-xx` with its owning task and expected result.

## Working method
- Start in plan mode: restate the task in 5 bullets, list the files you will touch, list open questions; wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes. This task changes no PHP.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside this task's scope; note anything else as a follow-up.

## Report back
Your final message must contain:
- a summary and the files created;
- migrations added (none expected);
- the local runs, with the result of each SQL check and each dry run;
- the migration inventory, with its lossy `down()` list;
- the PRE results compared with Task 01;
- the decisions assumed (D-19, D-24, D-27, D-29, D-30, D-31, the APP_KEY option, encryption inside or after the window, full vs phased release under R9);
- follow-ups per owning task;
- the handoff for Task 29: the runbook path, the measured duration per step and in total, the proposed maintenance window, the validated rollback method and how long it takes, the open blocking checks (including unmapped centers), the backfill counts, and any required production `.env` keys (names only).
````
