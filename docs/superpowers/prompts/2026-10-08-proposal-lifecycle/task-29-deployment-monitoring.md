# Task 29 — Deployment and post-launch monitoring

> **المهمة:** النشر والمراقبة بعد الإطلاق
> **المطوّر:** Dev 1 (Dev 2 احتياطي؛ الفريق B مساندة بلا وصول للإنتاج) · **الفريق:** A · **الأيام:** 14–15 (الخميس 2026-10-29: النشر ← الجمعة 2026-10-30: المراقبة؛ ومناوب مسمّى من الفريق A يومي السبت 10-31 والأحد 11-01) · **الجهد:** 1.5 يوم · **يعتمد على:** Task 27، Task 28
> **متى تستخدمه:** بعد CP6 (نهاية اليوم 13، 2026-10-28: UAT موقّع، الحزمة الكاملة خضراء، الـ runbook جاهز، ومفتاح تشفير الملفات محفوظ، وقرار «نشر كامل» أو «إطلاق مرحلي» R9)، لتجهيز يوم النشر (اليوم 14، الخميس 2026-10-29) ومراقبة 48 ساعة بعده عبر عطلة نهاية الشهر.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are preparing Task 29 of the HC Tracking proposal-lifecycle correction plan: a safe production deployment with a known maintenance window, 48 hours of monitoring, and clear rollback criteria. You are a preparer and reviewer:
- you turn Task 27's runbook into a final deployment checklist with exact commands and the timings measured in the rehearsal;
- you write the production smoke tests, the monitoring plan, the deploy log and monitoring report templates, and the user announcement;
- you check readiness locally.

You never connect to production, never read or print secrets, and never execute a deployment step. Dev 1 runs every step, with Dev 2 as backup. This is the moment the corrected story goes live: draft projects without dates, the server-enforced proposal stages, approval funding projects and MEAL's template confirmation activating them, one agreement per proposal with one auto-generated transfer record per tranche, projects inside HR centers (one open project per center and program), MEAL library, logframe, monthly reports generated at activation, aggregated donor reports, department tasks in the proposal plan, notifications to MEAL and General Management for every movement, and encrypted files. A missed step can lose files (the encryption key) or wipe role edits (the wrong seeder).

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md` — Arabic and authoritative; English command and UI names are exact. Read:
   - `### Task 29`, `### Task 27`, `### Task 28`;
   - the "Exceptional and edge cases" of Tasks 17, 21, 22 and 30 (including the `#### تعديلات المراجعة 2 (2026-10-08)` block of Task 17);
   - section 0 risks R9 (phased release), R11 (notification flood) and R12 (on-call over the weekend), and «قواعد العمل بين الفريقين» (Team B has no production access);
   - section 5 (scheduled commands use `withoutOverlapping()` + `onOneServer()`; keys only in `.env`);
   - section 6 (day 14 = Thursday 2026-10-29 deploy, day 15 = Friday 2026-10-30 monitoring; Saturday 2026-10-31 is the month-end closing day, on-call only);
   - section 7 (CP6) and section 8 "جاهزية النشر";
   - section 9: D-19, D-21, D-22, D-29, D-30.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` (if it exists; a recorded decision overrides the plan's default for D-xx). Also read Task 27's runbook and rehearsal report, and Task 28's QA report (`docs/superpowers/specs/2026-10-27-proposal-lifecycle-*`).
4. Rules:
   - `.ai/rules/console-commands.md`: `permissions:sync` only, never `RolesAndPermissionsSeeder`, and `optimize:clear` first.
   - `.ai/rules/env-example-gitignore-htaccess-gitleaks-toml-gitleaksignore-gitlab-ci-yml.md`: no secrets committed; gitleaks; `REVERB_ALLOWED_ORIGINS` is never `*`.
   - `.ai/rules/config.md`: least-privilege DB user; never root in production.
   - `.ai/rules/config-2.md`: `SESSION_EXPIRE_ON_CLOSE=true`.
   - `.ai/rules/providers.md`: strict mode is off in production by design.
   - `.ai/rules/alerts.md`: scheduled alerts, working days, ledger.
   - `.ai/rules/components-notifications.md`: `public/sw.js` never caches.
   - `.ai/rules/database-migrations.md`: lossy `down()` methods.
   - `.ai/rules/commands.md`: login checks the HR password.
   - `.ai/rules/tests.md`.
5. Source (read only):
   - `routes/console.php`. Current schedule: `notifications:wake-snoozed`, `notifications:scan frequent|daily`, `notifications:digests`, `model:prune`, `files:prune` at 03:00, `cache:prune-expired`.
   - `app/Console/Commands/`; `.env.example`; `config/queue.php` (no `null` connection; `.env.example` uses `database`); `config/logging.php`; `bootstrap/app.php` (`SyncProgramsFromHr`, `RedirectToHttps`); `resources/views/errors/503.blade.php`.
   - Tests: `tests/Feature/ProductionDebugModeTest.php`, `HttpsOnlyTest.php`, `SecurityHeadersTest.php`, `SecretHygieneTest.php`, `TrustedProxyLockdownTest.php`, `PrivateStorageRoutesTest.php`, `tests/Feature/Notifications/ScannerSchedulingTest.php`.
   - The Supervisor/cron/"After every deploy" section near the end of `docs/superpowers/plans/2026-09-23-notifications.md`.
6. Skills: `laravel-security`, `laravel-best-practices`. Use Boost `search-docs` for maintenance mode (`down --render/--retry/--with-secret`), `optimize`, `queue:restart`, `reverb:restart`, and scheduling with `onOneServer`, `withoutOverlapping` and `evenInMaintenanceMode`.

## Preconditions to verify
- **Task 27.** The runbook and rehearsal report exist. The rehearsal passed without manual steps, the durations are recorded, the rollback was tried, and every blocking PRE check is 0 or decided.
- **Task 28.** The QA report records Go at CP6 (end of day 13), full or phased (R9): 0 open Critical/High defects, UAT signed by MEAL, Funding and Finance, and the full suite green. If any item is missing, stop and report No-Go.
- **Commands.** `php artisan list` locally shows `hr:map-project-centers` (Task 30), `meal:seed-indicator-library` (Task 11), `monthly-reports:backfill` and `monthly-reports:prepare` (Task 17), `files:encrypt-existing` and `files:decrypt` (Task 22), and `reports:monthly-digest` (Task 21). `php artisan schedule:list` locally shows `reports:monthly-digest`, Task 21's daily scanner (project start/end, on-hold reminder, monthly report due/overdue) and `monthly-reports:prepare`. Tasks 17 and 21 own those entries; if one is missing, see Scope.
- **Production topology**, confirmed by the developer:
  - (a) VPS with Supervisor running `queue:work` and `reverb:start`, and a cron for `schedule:run` (the notifications design); or
  - (b) Pusher with `QUEUE_CONNECTION=sync` (the option documented in `.env.example`).

  The restart steps, and Task 21's retries / `failed_jobs`, depend on it. If it is unknown, stop and ask.
- **Day and coverage.** The deploy day is day 14, Thursday 2026-10-29, not a Friday (D-22; if the organisation's week is Sunday–Thursday, the plan shifts dates one day earlier). Two people are named as on-call for the full 48 hours, and a named Team A on-call covers Saturday 2026-10-31 (month-end closing day) and Sunday 2026-11-01 (R12). Monitoring spans Friday 2026-10-30 and the weekend.

## Revision 2 changes (2026-10-08)
- Owner is Dev 1 with Dev 2 as backup (Team A); days 14–15 (Thu 2026-10-29 deploy → Fri 2026-10-30 monitoring). Team B supports fixes on its own tasks without production access. Go/No-Go is CP6 at the end of day 13.
- **On-call:** name a Team A on-call for Saturday 2026-10-31 and Sunday 2026-11-01 (R12) in the checklist, with the rollback criteria and contact path; the 48-hour monitoring crosses month-end, when the first `monthly-reports:prepare` run (first working day of November) and the monthly digest fire.
- **Phased release (R9):** if CP6 decides a phased release, deploy the full code and run every migration and command, but grant only the permissions of the ready modules (`permissions:sync` adds names; grant MEAL/reporting modules later from **Admin > Users** without a second deploy). Document both variants and which smoke tests are skipped in each.
- **New deploy steps** after `permissions:sync`: `hr:map-project-centers --dry-run`, then `--apply` with the matching management approved in Task 27; after `meal:seed-indicator-library`: `monthly-reports:backfill --from=2026-10 --dry-run`, then real (D-29).
- **Scheduler:** confirm `monthly-reports:prepare` (daily, `withoutOverlapping()`, `onOneServer()`), Task 21's daily scanner and `reports:monthly-digest` are in `routes/console.php`.
- **Monitoring adds** the number of notifications sent per event key of the Task 21 catalog (flood detection, R11) and MEAL/General Management digest volume.
- Task 20 (media deliverables) is merged into Task 31 (**Department Tasks**), and Task 19 has no **Reporting Schedule**: smoke tests and the announcement follow the new names.

## Revisions 3–9 changes (2026-10-09 → 2026-10-12)
Source: plan header paragraphs «المراجعة 3» to «المراجعة 9», `### Task 27` (its Revision 3/4/6/8/9 subsections set the order) and `### Task 33`. Where this block conflicts with the Revision 2 block or the base sections, it wins. Use the exact order and commands from Task 27's runbook; this list only says what must appear in it.
- **Extra deploy steps, in Task 27's order:** after `permissions:sync`, the one-off D-23 revoke (`--revoke … --dry-run`, then real); after the Task 11/12/32/33 migrations, `strategy:import-pillars` and `meal:import-framework` with the MEAL-approved JSON files (each `--dry-run` first), then `meal:seed-indicator-library` (stops on a duplicated HR program code, D-10a); MEAL confirms every program's `donor_attribution` (D-46); `report-templates:seed-existing --dry-run`, then real (D-45); then `monthly-reports:backfill --from=2026-10`.
- **Reference data on production comes from idempotent migrations or commands, never from `db:seed`:** the six sectors (S-1), the evidence types and narrative questions (Task 33), the internal donor «تمويل ذاتي — Hope» (D-27a). Confirm in the runbook which migration or command creates each, and that a second run changes nothing.
- **Go-live checklist items owned by people:** the MEAL Manager enters governorate and project MEAL officers (D-12a, no existing data); PR completes `project_proposal.target_beneficiaries` for proposals Task 27 listed (D-25); the **Default Proposal Reviewers** slots (CEO, MEAL Manager, Finance Manager, Projects & Fundraising Manager) are configured; no operational employee holds Super Admin (D-20a).
- **Smoke tests add:** the **Template Queue** (existing active projects listed with `pending` items), a project's **Monthly Report Template** tab, a monthly report showing «بانتظار تأكيد نموذج التقرير من MEAL», `/admin/sectors`, the MEAL coverage settings page (instead of the city column), the **Strategy** page with its three pillars, an agreement's tranche schedule and its generated transfer rows, and a proposal's **Record Final Report Acceptance** modal (open and cancel).
- **Scheduler adds:** the template-confirmation reminder/escalation source (Task 21, `report_template_due_working_days`).
- **Rollback triggers add:** a broken template confirmation path (`ConfirmMonthlyReportTemplate` / `ActivateProjectFromConfirmedTemplate`), since no project can become active without it.
- **Announcement adds:** "a funded project becomes active once MEAL confirms its monthly report template", sectors, strategic pillars, and the final-report rule for closing a proposal.

## Scope
### In scope
- Create `docs/superpowers/specs/2026-10-29-proposal-lifecycle-deployment.md` (confirm the name in plan mode). Write it in Arabic like the plan, with commands and names in English. Sections:
  1. **Go/No-Go gate.** A checklist built from the preconditions.
  2. **T-2 days.** Announce the window (the drafts below); confirm on-call (48 hours plus the Team A on-call for Saturday 2026-10-31 and Sunday 2026-11-01); confirm `FILE_ENCRYPTION_KEY` and `FILE_ENCRYPTION_KEY_ID` exist in production `.env` and in two off-server places, verified by a second person without displaying the value; check disk space (≥ 1.5× `storage/app/private`); confirm the DB account can run DDL.
  3. **Deploy sequence.** Each step has the command, the owner, the expected output, the rehearsal duration, and "on failure → rollback step N":
     1. `php artisan down --render="errors::503" --retry=60 --with-secret` (keep the generated secret only in the session).
     2. Final backups after `down`: DB dump, `storage/app/private`, `.env`. Verify the dump restores.
     3. Pull the release tag.
     4. `composer install --no-dev --optimize-autoloader`.
     5. `npm run build`, or upload a `public/build` built from the same tag.
     6. `php artisan optimize:clear`.
     7. `php artisan migrate --force` (with `--step` if Task 27 chose it).
     8. `php artisan permissions:sync --dry-run`, then `php artisan permissions:sync`.
     9. `php artisan hr:map-project-centers --dry-run`, compare with the matching management approved in Task 27, then `--apply`.
     10. `php artisan meal:seed-indicator-library --dry-run`, then for real.
     11. `php artisan monthly-reports:backfill --from=2026-10 --dry-run`, then for real (existing active projects, from October 2026 only).
     12. `php artisan files:encrypt-existing --dry-run`, then for real (inside or after the window, as Task 27 decided).
     13. Task 27's POST checks.
     14. `php artisan optimize`.
     15. `php artisan queue:restart` and `php artisan reverb:restart` (or `supervisorctl restart`), only for topology (a).
     16. `php artisan schedule:list` (shows `monthly-reports:prepare`, Task 21's daily scanner and `reports:monthly-digest`).
     17. Phased release only: confirm the withheld module permissions are not granted.
     18. Smoke tests through the bypass secret.
     19. `php artisan up`.
  4. **Smoke tests on production.** These are read-only, plus one test notification:
     - `php artisan about` (environment production, debug OFF, caches on) and `migrate:status` (all Ran);
     - login with the deployer's own HR account (and their 2FA, if they use it);
     - in Arabic and English, light and dark, open: Home, Projects, a Proposal page with the **Reviewers**, **Logical Framework** and **Indicators & Targets** tabs, and the **Change Status** modal (open and cancel); the proposal **Plan** tab; `/indicator-library`, `/monthly-reports` (an active project shows its generated months), `/reports/monthly-matrix`, `/department-tasks`, `/donor-reports/compose`, **My Reporting Duties**; a **Funding Agreement** page and its generated transfer record on **Transfers**; a project page showing its HR center; `/admin/cities` (MEAL owner column), `/admin/users` (**Supervision** tab with HR direct managers), `/admin/notification-settings` (**Default Proposal Reviewers**, **Event Notifications**); Executive Summary, Transfers, Results, Indicators, Targets, Reports;
     - download and preview old files, which are now encrypted, from a project, a proposal, a task and a report;
     - test a new upload only on a record management agrees on, then delete it; otherwise watch the first real upload;
     - `php artisan notifications:test <deployer-email>`, then check that the toast and popup arrive and `failed_jobs` is unchanged.
  5. **Monitoring plan for 48 hours.** Checkpoints at T+15m, T+1h, T+4h, end of day 14, morning of day 15 (Friday 2026-10-30) and T+48h (Saturday 2026-10-31, by the on-call), plus a Sunday 2026-11-01 on-call check and a Monday 2026-11-02 check of the first-working-day jobs. At each, check:
     - `storage/logs/laravel*.log` for ERROR/CRITICAL;
     - the decryption-failure log message that Task 22 writes (quote it from the code);
     - `php artisan queue:failed`;
     - the backlog in `jobs`;
     - scheduler activity (new rows in `notification_alert_ledger`, the 06:00 daily scan, the first `monthly-reports:prepare` run);
     - notifications sent per Task 21 event key and per oversight recipient (MEAL, General Management), to catch a flood (R11);
     - the Reverb process;
     - disk usage;
     - manual timing of the key pages;
     - the user-report channel.

     `php artisan pail` is a dev dependency and is gone after `--no-dev`; use `tail`.
  6. **Rollback.** The triggers from the plan: a migration failure, any systematic decryption error, or a broken approval path (`ApproveProposal`). Each trigger gets a threshold and a decision owner, and the procedure is Task 27's Part C: restore the DB and storage together, since post-deploy rows carry `encryption_key_id` and the old code would serve ciphertext. State the data-loss window, and when a forward fix is preferred.
  7. **Templates.** A deploy log (step, start, end, output summary, operator) and a 48-hour monitoring report (checkpoint, findings, actions, severity).
  8. **Close-out.** Move minor defects to the maintenance list. Keep the backup until monitoring ends and management signs off. Confirm that the rehearsal copy was deleted (Task 27) and that the key copies exist.
- Drafts of the user announcement in Arabic and English: the maintenance window, the main changes (project = HR center; draft projects without dates; concept note → proposal stages with the plan inside the proposal; the review loop; Indicator Library; monthly reports generated automatically and the Monthly Matrix; aggregated donor reports; department tasks for Media and Finance; notifications to MEAL and General Management; encrypted files), a link to the updated guide (Task 26: `docs/user-guide.ar.md` / `docs/user-guide.md`; ask where users read it), and a contact. Add a heads-up that the first daily scan may send several new reminders.
- The only code change allowed is a schedule entry that Task 21 forgot, and only with the developer's approval. Add `Schedule::command('reports:monthly-digest')…->withoutOverlapping()->onOneServer()` in `routes/console.php`, plus a test in the style of `tests/Feature/Notifications/ScannerSchedulingTest.php`. Do not modify existing entries.

### Out of scope
- Running any deployment, backup, SQL or artisan command on production.
- Fixing defects (they go to the maintenance list or the owning task), rehearsal (Task 27), UAT (Task 28).
- Editing `.env` files.
- Generating keys.

## Business rules
- Do not deploy without a backup whose restore has been verified, without the key saved in two off-server places, or with Go missing.
- Rollback criteria (plan): a migration failure, a systematic decryption error, or a broken approval path. Roll back with the backup, following the runbook.
- Acceptance: the system works, there are 0 critical errors in 48 hours, and every file is encrypted (Task 27's POST check on stored file rows with `encryption_key_id IS NULL` returns 0).
- `APP_DEBUG=false` (enforced by `ProductionDebugModeTest`), HTTPS only (`HttpsOnlyTest`), and keys in `.env` only.
- Never deploy on a Friday without monitoring. Never delete the backup before monitoring ends.

## Security and authorization
- Never `cat .env`, never run `config:show` on a key, and never paste secrets into the log, the documents or chat. The developer generates `FILE_ENCRYPTION_KEY` on the server before the first encrypted write, never twice. A second key after encryption means data loss.
- Never run `key:generate` on production. Rotating APP_KEY breaks `users.two_factor_*`, the `accounts` banking columns and sessions; the supported rotation is `security:reencrypt` with `APP_PREVIOUS_KEYS`.
- Check the production `.env` values by name only, with the developer reading them out:
  - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` over https;
  - `SESSION_EXPIRE_ON_CLOSE=true`;
  - `REVERB_ALLOWED_ORIGINS` not `*`;
  - `AUTH_TWO_FACTOR_ENFORCED` unchanged;
  - `TRUSTED_PROXIES` unchanged;
  - a non-root DB user;
  - mail configured.
- Smoke tests create no business records and expose no amounts in screenshots that are shared.

## Validation
No user input. Validate the checklist:
- every command exists in `php artisan list` with the flags shown (check `php artisan down --help` and `migrate --help` locally);
- every step has an owner, an expected output, a duration and a rollback pointer;
- every page in the smoke list exists in `php artisan route:list`.

## Edge cases and concurrency
- **HR unreachable during the deploy.** Migrations are unaffected. `SyncProgramsFromHr` reports the error and login fails, so postpone the login smoke tests and keep the site in maintenance or announce the delay.
- **A migration fails midway.** Stop. Do not re-run blindly. Restore and roll back.
- **`files:encrypt-existing` stops.** Re-run it; it is idempotent. Systematic failures are a rollback trigger.
- **Encryption runs past the window.** Use Task 27's decision.
- **Users in the middle of a session.** They see the 503 page; after `up`, a stale Livewire snapshot asks them to refresh. Say so in the announcement.
- **The first scheduled scans after `up`** may send many new alerts (monthly report due, department tasks, donor report owners, logframe missing, project start/end, the grouped `monthly_reports.generated` from the backfill). The ledger prevents duplicates. Warn MEAL and the managers.
- **Mail fails.** In-app notifications are still delivered; retries depend on the topology.
- **Scheduled tasks during `down`.** They skip, because they do not use `evenInMaintenanceMode`. Check that the 03:00 `files:prune` does not overlap the encryption run.
- **Rollback after users have entered data.** That data is lost. Define the cut-off after which a forward fix is the default.

## Data integrity
- Back up after `down`, so the final backup holds every write.
- Run Task 27's POST checks after migration and after encryption (the operator runs them; you interpret the pasted counts).
- Compare the counts of tables the release must not change, before and after.
- Keep the backups and the key copies through monitoring and sign-off.

## Implementation guidance
- Use placeholders (`<app-path>`, `<backup-dir>`, `<release-tag>`, `<deployer-email>`) and never real hosts, users or paths.
- Format the checklist with `- [ ]` items and tables, so the operator ticks steps live. Put the rehearsal durations next to the matching steps, and the total window at the top.
- Find the decryption-error log message and the new command flags in the code (`grep` in `app/Support/StoredFiles.php` and `app/Console/Commands/`); never guess them.
- If you add the schedule entry, keep the style of the existing `routes/console.php` lines.

## Tests (Pest)
- Run locally:
  - `php artisan test --compact tests/Feature/ProductionDebugModeTest.php tests/Feature/HttpsOnlyTest.php tests/Feature/SecurityHeadersTest.php tests/Feature/SecretHygieneTest.php tests/Feature/TrustedProxyLockdownTest.php tests/Feature/PrivateStorageRoutesTest.php tests/Feature/Notifications/ScannerSchedulingTest.php`;
  - the command tests of Tasks 11, 17, 21, 22 and 30 (find them with `grep -rl`);
  - `php artisan schedule:list`.
- If you add the schedule entry, add its test and run that file. The developer runs the full suite on the release tag (`php artisan test --compact`).
- Production smoke tests are manual, done by the operator from your checklist.

## Acceptance criteria
- [ ] The deployment document exists with all eight sections, the announcements in Arabic and English, and the templates.
- [ ] Every command and flag is verified locally, and every step carries the rehearsal duration and a rollback pointer.
- [ ] `schedule:list` locally includes `reports:monthly-digest`, Task 21's daily scanner and `monthly-reports:prepare`, with passing schedule tests.
- [ ] The checklist names the Team A on-call for Saturday 2026-10-31 and Sunday 2026-11-01, and documents both the full and the phased (R9) release.
- [ ] The rollback triggers have thresholds and an owner, and the procedure restores the DB and storage together.
- [ ] The local readiness tests pass, and the precondition gate records Go or No-Go explicitly.

## Do not
- Run anything against production, or ask for access, credentials or `.env` contents.
- Run `RolesAndPermissionsSeeder`, `db:seed`, `migrate:fresh`, `key:generate`, or `migrate --graceful`.
- Skip `--dry-run`, deploy on a Friday without monitoring, or delete backups or key copies before sign-off.
- Change application code beyond the approved schedule entry, or schedule `files:encrypt-existing`.
- Put secrets, personal data or amounts in documents or chat.

## Quality bar (this task)
- The document is read by non-developers under time pressure: numbered steps, one action per step, the expected result next to it, and an explicit stop condition. Arabic text, English identifiers exact.
- Every step has an owner, the exact command, the expected output, the rehearsal duration and "on failure → rollback step N".

## Working method
- Start in plan mode: restate the task in 5 bullets, list the files you will touch, list open questions; wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside this task's scope; note anything else as a follow-up.

## Report back
Your final message must contain:
- a summary and the files created or changed;
- migrations added (none expected);
- the local tests and checks run, with pass/fail output;
- the Go/No-Go precondition status;
- the topology assumed;
- the decisions assumed (D-19, D-21, D-22, D-29, D-30, encryption inside or after the window, full or phased release);
- open risks and follow-ups (to the maintenance list);
- the close-out handoff: the deploy-log and monitoring-report paths, the backup retention date, the key-custody confirmation still needed, and the defects moved to maintenance. Nothing depends on this task after it; it closes the project.
````
