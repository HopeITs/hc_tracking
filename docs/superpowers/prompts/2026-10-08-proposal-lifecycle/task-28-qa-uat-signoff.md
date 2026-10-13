# Task 28 — Quality assurance, full regression and user acceptance testing (UAT)

> **المهمة:** ضمان الجودة والاختبار الشامل وقبول المستخدمين (UAT)
> **المطوّر:** الجميع (B4 يجهّز السيناريوهات) · **الفريق:** الفريقان (A يقود، وB مساندة في الإصلاحات) · **الأيام:** 12–13 (الثلاثاء 2026-10-27 ← الأربعاء 2026-10-28؛ B4 يكتب سيناريوهات UAT في اليوم 10 والانحدار في اليوم 12) · **الجهد:** يومان لكل مطوّر من الفريق A، ومساندة الفريق B في الإصلاحات · **يعتمد على:** كل مهام الميزات
> **متى تستخدمه:** تجهيز السيناريوهات من اليوم 10، ثم الانحدار بعد تجميد الميزات (CP5، نهاية اليوم 12) والبروفة الأولى لـ Task 27، وUAT في اليوم 13 بعد البروفة النهائية، وقبل نقطة Go/No-Go (CP6، نهاية اليوم 13، 2026-10-28).
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are preparing and supporting Task 28 of the HC Tracking proposal-lifecycle correction plan: QA, full regression and UAT for the corrected story. You prepare the evidence for the Go/No-Go decision at CP6:
- the results of the automated quality gates;
- a test-gap audit against the Definition of Done;
- UAT scripts for each persona;
- concurrency, penetration, accessibility and RTL checklists;
- a defect log;
- a QA report with a recommendation.

You do not fix application code in this session. A failing check becomes a defect for the task that owns it. Human testers run UAT on a staging environment with their own HR accounts. You never log in as anyone, never handle passwords, and never run anything against production. The goal is proof that the whole story works for every role, not only for Super Admin and not only on the happy path:
- a project starts as a draft with no dates;
- concept note → internal review → submitted to the donor;
- proposal → review → submitted;
- donor approval fixes the dates and funds the projects (`approved`); MEAL's confirmation of the monthly report template activates them;
- one agreement with its tranche schedule → one auto-generated transfer record per tranche (amount, `fees`, exchange rate, arrival and receive dates completed by Finance) → aggregated donor reports for a chosen period;
- MEAL: library → logframe and activity calendar → per-centre targets → monthly report template → monthly reports generated at activation → monthly report → confirmation → Monthly Matrix;
- every movement on a project, proposal or report reaches MEAL and General Management (Task 21 event catalog).

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md` — Arabic and authoritative; English UI, status, permission and table names are exact. Read:
   - `### Task 28`;
   - section 0 «قواعد العمل بين الفريقين», sections 1.0–1.3 (domain facts, story and state diagram), 5 (security matrix), 6 (days 12–13), 7 (CP4, CP5, CP6) and 8 (Definition of Done);
   - the "Business rules", "Security and permissions", "Validation rules", "Exceptional and edge cases" and "Acceptance criteria" of Tasks 02–19, 21–23, 26, 30 and 31, including each `#### تعديلات المراجعة 2 (2026-10-08)` block. Task 20 is merged into Task 31; Tasks 24 and 25 are out of the October window. UAT verifies exactly these.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` (if it exists; a recorded decision overrides the plan's default for D-xx). Use the signed permission matrix and D-07, D-08, D-09, D-14, D-15, D-16, D-21, D-23, D-26, D-28, D-29, D-30, D-31, D-32, D-33 and D-34.
4. Task 27's rehearsal report and runbook in `docs/superpowers/specs/` (if they exist).
5. Rules:
   - `.ai/rules/tests.md`: insertX helpers return ids; build a fresh Livewire instance after `assertForbidden()`; use a roleless user for "no task access".
   - `.ai/rules/pages.md`: "Hide money, keep names"; `wire:model` modals.
   - `.ai/rules/views-enums.md`, `.ai/rules/views.md` (Graphite dark tokens, `text-status-critical`) and `.ai/rules/views-pages.md` (show routes bind by slug).
   - `.ai/rules/policies.md` (the nine built-in roles) and `.ai/rules/resultsindicatorstargetsreports.md` (achievement has one definition).
   - `.ai/rules/reports.md` and `.ai/rules/alerts.md` (working days; ISO weeks).
   - `.ai/rules/console-commands.md` and `.ai/rules/database-seeders.md`.
   - `.ai/rules/commands.md`: login checks the HR password, so every tester needs a real HR account.
6. Source (read only):
   - `config/access-control.php`: role baselines. Before Task 02, only Financial, General Manager and Super Admin hold the money views; after Task 02 (D-31b) Public Relations Manager also holds `funding-agreements.view/create/update`, so it is **not** a no-money persona. `proposals.view` is restricted to Financial, Public Relations Manager, General Manager and Super Admin. `Team Member` has no `reports.create`. `two_factor.required_roles` is empty, so 2FA is optional for every role (user decision 2026-09-28); the `.env.example` comment about enforced roles is stale.
   - `routes/web.php`.
   - `tests/Pest.php` (`userWithRole()`, the insertX helpers).
   - `tests/Feature/AccessControl/`: `AuthorizationCoverageTest`, `BrowserCallableMethodsTest`, `FundraisingPagesMoneyTest`, `MoneyLinksVisibilityTest`, `RoleMatrixTest`, `PolicyCoverageTest`.
   - `tests/Feature/IndicatorDirectoryTest.php` (the constant-query-count pattern with `DB::listen`).
   - `database/seeders/DatabaseSeeder.php`. It calls `RolesAndPermissionsSeeder`, then `DemoDeliverySeeder` and `NotificationDemoSeeder`.
   - `lang/ar/*.php` and `docs/user-guide.ar.md` (updated by Task 26).
7. Skills: `testing-best-practices`, `laravel-security`, `livewire-development`. Use Boost `search-docs` for Pest arch tests and the Livewire 4 `$wire` API, and `database-query` on the LOCAL database only.

## Preconditions to verify
- CP5 is declared (end of day 12), Tasks 02–19, 21–23, 26, 30 and 31 are merged (note which R9 cuts were applied), and Task 27's report says the rehearsal passed. If not, record the gap in the QA report.
- `php artisan route:list --except-vendor` shows `/indicator-library`, `/monthly-reports`, `/monthly-reports/{…}`, `/reports/monthly-matrix`, `/department-tasks` (Task 31), `/donor-reports/compose` and the **My Reporting Duties** page (Task 19). `php artisan list` shows `hr:map-project-centers`, `meal:seed-indicator-library`, `monthly-reports:prepare`, `monthly-reports:backfill`, `files:encrypt-existing`, `files:decrypt` and `reports:monthly-digest`. If a feature is missing, stop and name the owning task.
- The developer confirms that a staging environment exists. It must have:
  - HR read access;
  - `MAIL_MAILER=log` or a test mailbox;
  - the same queue, broadcast and scheduler topology as production.
- The developer confirms one real tester per persona, each with an HR account, the role from the signed matrix, and their own 2FA method if they use one. Roles are assigned by an admin, either in **Admin > Users** or with `php artisan user:create --email= --role=`. You never assign them.

## Revision 2 changes (2026-10-08)
- Owners: everyone; **B4** prepares the UAT scenarios (day 10) and runs the regression (day 12) and UAT support (day 13); Team A leads; Team B fixes defects in its own tasks. Days 12–13 (Tue 2026-10-27 → Wed 2026-10-28); Go/No-Go at **CP6, end of day 13**; deploy day 14 (Task 29). The CP4 end-to-end integration test (end of day 11) is the reference journey.
- Story changes to verify: project = HR center (one open project per center; renewal = new proposal on the same project); the proposal carries its plan from `concept note` (logframe, targets per center, milestones, department tasks); approval generates the monthly reports for every month and rebases the plan; the agreement generates the transfer record; donor reports are aggregated on demand (no `report_schedules`, no **Reporting Schedule**); media deliverables are department tasks (no `media-deliverables`, no **Media Workspace** — use **Department Tasks**); HR data is read-only.
- **New UAT scenarios 6–9** (plan numbering; the old media scenario is now 5): 6 aggregated donor report; 7 MEAL and General Management notified of every movement without duplicates; 8 project = center; 9 no-money user (the former persona 6).
- Team B has no production access; staging access for Team B testers follows the inter-team rules (seed or masked data). Defects are logged against the owning task and team.
- If CP6 decides a **phased release** (R9), mark which scenarios are deferred with their modules' permissions.

## Revision 3 changes (2026-10-09)
Source: `Stratigy.pptx` (strategy 2026–2030) and `micro logic.xlsx` (the real Micro program logframe, urban and agricultural tracks). Read plan section 1.0 facts 10–12 and the «تعديلات المراجعة 3» subsection of this task. Where this block conflicts with text further down this prompt, this block wins.
Add these checks to the existing scenarios, keeping the 1–9 numbering:
- MEAL: import the Micro urban framework into a proposal with **Start from Program Framework**, then see default targets suggested in the grid.
- Center manager: the monthly report hides MEAL-collected indicators and follow-up indicators before their month; a percentage line is entered as numerator/denominator.
- Projects & Fundraising manager: the aggregated donor report shows a pooled percentage for two centers that matches a hand calculation.
- Management: the **Strategy** page shows the three pillars and their programs.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-02, D-20, D-50, D-51, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- UAT scenario 10: a Syria Cross project manager fills a month with disaggregated indicators (male, female, PwD) and a ratio by numerator/denominator, sets activity statuses (Ongoing, Conducted); MEAL confirms. Accept when totals and ratios match the corrected workbook and the Ongoing activity appears next month automatically.
- UAT scenario 11: closing a proposal is rejected before donor acceptance of the final report and succeeds after it (D-02b).
- No UAT run with a Super Admin account (D-20a).

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
- **Revision 4–8 alignment of scenarios 1–9 (keep their numbers; the plan's Task 28 text predates these revisions):**
  - Scenario 1: **Approve Proposal** needs donor-approval evidence (file or note, D-26); linked projects become `approved` ("funded, awaiting report template"), or wait for the owner; MEAL then confirms the template and the projects become `active`, and only then are the monthly reports generated; the plan, milestones and activity calendar shift at approval.
  - Scenario 2 (MEAL): add the **Template Queue** → **Monthly Report Template** (items only from the library, approved target, schedule, mandatory evidence types, `entered_by`, donor mode) → **Confirm Template** activates the project; a new proposal on an active project adds `pending` items that appear only in its own months (D-41). Targets and selection are MEAL-only (D-23: General Manager and Managers get 403).
  - Scenario 3 (Finance with PR): the tranche schedule is entered by PR and Finance (D-31b); one expected transfer per tranche; arrival vs receive dates with their hints (D-31a).
  - Scenario 4 (project manager): acknowledging the owner assignment makes the project `approved`, not active; the **Monthly Report** has narrative questions, the indicator grid with mandatory evidence types, the files summary and the activities section; MEAL-entered lines are read-only.
  - Scenario 6: percentages pool as Σnumerator ÷ Σdenominator (not "never aggregated"); shared items carry the shared-funding footnote.
  - Scenario 8: one open project per center **and program**; another program in the same center is accepted.
  - Scenario 9 (no-money personas): `Public Relations`, `MEAL`, `Managers`, `Media Team` — **not** `Public Relations Manager`, which holds the agreement money views since D-31b.
  - Short scripts: **Correct Status** by General Manager and Public Relations Manager (required reason, marked as a correction) and refused for Financial and MEAL (D-20); no UAT script uses Super Admin (D-20a). Add a **Sectors** script (MEAL adds a sector and links it to a project; a new project needs at least one sector, S-2) and a **Pillars** check on **Strategy** (the Revision 9 mapping, D-35a).
- **Scenario 12 (D-25):** a manager enters 36 for a percentage indicator whose project has 180 beneficiaries in the proposal → 20% in the report and matrix; donor report pools Σcounts ÷ Σbeneficiaries over two centers.
- **Scenario 13 (D-09a):** MEAL Manager slot empty; reopen request decided by PR Manager; a MEAL officer gets 403.
- **Scenario 14 (D-12a):** an Aleppo MEAL officer assigned to an Idlib project sees and confirms its reports; the Idlib officer does not see them in their queue.
- **Scenario 10 after D-54:** run with seeded data of the same shape (disaggregated indicators, a percentage, activity statuses), not the Syria Cross file; accept against a prepared manual calculation.
- **Scenario 11 after D-02c:** closing is rejected until the final report file is uploaded and PR records donor approval; it succeeds without the acceptance email.
- **Scenario 15 (D-10a):** MEAL adds a Micro indicator and gets the next code (e.g. `002.OP1.2.4`); the proposal picker lists only its program's indicators.

## Scope
### In scope
- **Automated gates.** These run locally or in CI, never on production:
  - `php artisan test --compact` (the developer runs it, per CLAUDE.md; you run it only when asked);
  - `vendor/bin/phpstan analyse` (level 7, no baseline). Classify each error as new (its file changed since the plan baseline `0a21efc5`) or pre-existing;
  - `vendor/bin/pint --dirty --format agent`.
- **DoD test-gap audit.** Produce a matrix of new Action / page / command / model → test. It must show:
  - a success, validation, 403 and BOLA test for every new Action that takes a child id;
  - a constant query-count test for **Logical Framework**, **Indicator Library**, **Monthly Matrix** and the targets grid;
  - idempotency and `--dry-run` tests for each new command;
  - a TracksActivity test for each new model;
  - Task 04's arch test forbidding direct `status` writes on `Proposal`;
  - idempotency tests for the event listeners (template drafting on `ProjectFunded` / `ProposalStatusChanged` (Task 33), monthly report generation on `ProjectActivated` / `ProjectPeriodChanged`, transfer generation per tranche, Task 31 / Task 12 rebase at approval) and `EventCatalogCoverageTest` (Task 21);
  - localized messages for every validation rule.

  Also review every entry added to `authorizationCoverageExemptions()` since the baseline, and check that new money helpers are covered by `BrowserCallableMethodsTest`.
- **Closing gaps.** With approval, you may add tests only, to close DoD gaps. One example: an end-to-end journey test, `tests/Feature/ProposalLifecycleJourneyTest.php`, if no CP4 end-to-end integration test exists (search first). Never change `app/`, `resources/`, `config/`, `database/`, `routes/` or `lang/`.
- **Documents** in `docs/superpowers/specs/` (confirm the names in plan mode):
  - `2026-10-27-proposal-lifecycle-uat-scripts.md`;
  - `2026-10-27-proposal-lifecycle-qa-report.md`. It contains the gate outputs, the DoD matrix, the checklists, the defect log, a read-only data-integrity SQL appendix and the Go/No-Go summary.
- **UAT scripts.** Each persona gets preconditions (role, data, settings), numbered steps with the expected result, negative steps, money checks, an Arabic UI check, the evidence to capture, Pass/Fail and a defect id. The default roles below must be confirmed against the signed matrix:
  1. **Projects & Fundraising Manager** (default `Public Relations Manager`, with a `Financial` user for the budget and agreement steps):
     - create a draft project by picking an HR center (city derived, no date fields), the **Similar Projects** warning, and a renewal as a new proposal on the same project;
     - **New Concept Note** with donor and project, plus the skip-with-reason path; add its plan (**Plan** tab: milestones and media/finance department tasks) from the concept note stage;
     - concept note review: reviewers are invited, then submitted, with the warning listing reviewers who have not seen it (D-15);
     - proposal draft → Finance enters `requested_budget` → proposal review → submitted;
     - `on hold` with a reminder date; a reject with a reason of at least 10 characters;
     - **Approve Proposal** with the dates and the donor-approval evidence; the linked projects become `approved` (funded), or wait for the owner; the plan's milestones, tasks and activity calendar shift to the actual dates; after MEAL confirms the template (scenario 2) the projects become `active` and the monthly reports are generated for every month of the period;
     - **Change Status** offers only the allowed transitions.
  2. **MEAL** (`MEAL`, configured as the MEAL Manager slot in **Default Proposal Reviewers** and as the MEAL owner of the test city):
     - **Indicator Library**: unique code, measure lock, deactivate;
     - **Logical Framework**: four levels, and the root contributes to a program result;
     - **Indicators & Targets**: the per-centre grid filled before approval (empty vs 0; percentage ≤ 100; the whole batch is refused on any bad cell; columns show the HR center and city);
     - the logframe-missing alert;
     - validate or return a monthly report, without editing it, and never one they prepared (four eyes);
     - the reopen decision;
     - **Monthly Matrix**: filters, aggregation, CSV;
     - the full chain view.
  3. **Finance** (`Financial`):
     - **Record Agreement** only for an approved proposal; the donor is read-only; a second agreement is refused;
     - the tranche schedule (entered with PR) generates one expected transfer record per tranche (`transfers_tracking`); Finance completes the received amount, `fees`, exchange rate and the arrival/receive dates (D-31, D-31a); re-running creates no second record;
     - the finance section of an aggregated donor report is visible to Finance only.
  4. **Centre manager** (the project owner, holding `reports.create/update`, e.g. `Managers`):
     - acknowledge the owner assignment, after which the project is funded (`approved`) and waits for MEAL's template confirmation;
     - **Monthly Reports**: every month of the project period is already generated; months that have not started are "upcoming", never overdue;
     - **Monthly Report**: values (0 allowed), notes and files; submit refused before the month's last working day and while the previous month is unsubmitted (sequencing on submit);
     - submit, after which editing is refused;
     - **Request Reopen** with a reason;
     - **My Reporting Duties** and the **My Team** card, if applicable.
  5. **Media and Finance departments** (staff whose current HR position is in the department; `Public Relations Manager` as requester):
     - a department task in the proposal's plan (**Plan** tab) reaches the department on creation and in **Department Tasks**;
     - the reminder before the due date (working days; UI and settings only on staging);
     - deliver a media task with a file, a link (video) or a text; accept, or return with a reason;
     - a finance task is visible to the finance department only; a member of another department does not see it;
     - the proposal shows as name and description only, no money.
  6. **Aggregated donor report** (Projects & Fundraising Manager, `reports.aggregate`):
     - in the **Donor Report Builder**, pick a proposal with several centers and a quarterly, then a semi-annual period;
     - only validated figures count; the completeness section lists unsent or unvalidated months; percentages pool as Σnumerator ÷ Σdenominator across centers (never an average of percentages);
     - **Save as Donor Report** freezes a snapshot; **Mark as Submitted** requires the final file; a submitted report cannot be regenerated;
     - a responsible without money permissions sees it in **My Reporting Duties** without amounts.
  7. **MEAL and General Management oversight**:
     - every movement in scenarios 1–6 (project, proposal, monthly report, donor report, department task) reaches MEAL and General Management, immediately or in the daily digest per the **Event Notifications** settings;
     - a person who is both a direct and an oversight recipient gets one notification; the actor is not notified of their own action; activation sends one grouped `monthly_reports.generated` notification, not one per month.
  8. **Project = center**:
     - a second open project for the same HR center and program is refused with the message pointing to the existing project, while another program in the same center is accepted;
     - renewal is a new proposal on the same project; linking it to a closed project returns that project to a dateless draft; a closed or archived project does not count as open, so it never blocks the center;
     - an inactive HR center shows its badge and cannot be picked for a new project.
  9. **No-money user** (`Public Relations`, `MEAL`, `Managers`, `Media Team` — see the Revision 9 alignment: Public Relations Manager now sees agreement money): walk every screen of 1–8 and confirm no amount appears in pages, modals, the activity timeline, notifications, emails (monthly digest), CSV exports, **My Reporting Duties**, **Department Tasks**, the **Donor Report Builder** (no finance section), **Similar Projects** or the logframe. Proposal, donor and agreement links render only with that page's view permission.

  Add short scripts for:
  - a reviewer without `proposals.view` (sees the page, but no money);
  - **Correct Status** by General Manager and by Public Relations Manager (required reason, marked as a correction), and the same action refused for Financial and MEAL (D-20; never run with Super Admin, D-20a);
  - General Manager **Supervision** (HR direct managers shown read-only, local exceptions added);
  - the monthly digest triggered on staging with `php artisan reports:monthly-digest` (the developer runs it).
- **Concurrency scripts (two windows):**
  - approve the same proposal (the second gets "already approved");
  - submit a monthly report while a line is edited ("submitted and locked");
  - record two agreements for one proposal (one agreement, one transfer record);
  - save the targets grid twice (the stale row is refused);
  - double-click **Mark as Seen**;
  - a stale **Change Status** dialog.
- **Manual penetration checklist** (staging only):
  - Tamper with ids in Livewire calls using `$wire` in the devtools console: another reviewer's record, a line from another monthly report, a grid cell outside the proposal's projects or logframe, another proposal's donor report snapshot, another department's task, an unapproved proposal or a forged donor in **Record Agreement**, `startDate` on a draft project, a disallowed or `approved` status, `requested_budget` from a no-money user, a previous project the user cannot see.
  - Swap the file id across records on every download and preview route, old and new.
  - Open slugs of out-of-scope records.
- **Accessibility and RTL checklist:**
  - keyboard navigation in the targets grid, the **Monthly Report** grid and **Monthly Matrix**;
  - `aria-label` on icon buttons and grid inputs;
  - contrast in light and dark;
  - status never shown by colour alone;
  - Arabic and English layouts, and 375px width;
  - every validation message appears in Arabic at its field. Take the messages from `lang/ar`.

### Out of scope
- Fixing defects in application code (the owning task, in a new session).
- Rehearsal (Task 27) and deployment (Task 29).
- Changing the permission matrix or the plan.
- Any production access.

## Business rules
- No deployment while a defect of severity Critical or High is open. Define the severities in the report:
  - Critical: data loss or corruption, money exposure, auth bypass, the core path blocked;
  - High: a story step fails for a role, or a server rule is missing;
  - Medium: a workaround exists or the UI is wrong;
  - Low: cosmetic.
- Go requires all of the following: the full suite is 100% green, PHPStan shows no new errors, Pint is clean, 0 Critical/High defects are open, and MEAL, Funding and Finance representatives have signed UAT. You cannot sign for anyone.
- A scenario is Pass only with tester evidence. Each scenario is run with the persona's role, never with Super Admin.

## Security and authorization
- Testers use their own HR accounts. You never type, store or request passwords or 2FA codes.
- Documents contain record slugs or ids only: no personal data and no amounts. Screenshot evidence lives outside the repository, in storage the testers choose.
- Penetration tests run on staging only, and their findings go to the defect log, not to chat or tickets that non-finance staff can read.
- Check permissions by exact name: `proposals.manage-reviewers`, `proposals.correct-status` (General Manager and Public Relations Manager), `report-templates.view/manage/confirm`, `evidence-types.manage`, `narrative-questions.manage`, `sectors.view/manage`, `reports.approve`, `reports.reopen`, `indicator-library.*`, `reports.aggregate`, `tasks.*` for department tasks (no `media-deliverables.*` exists), `meal-coverage.manage`, `supervisions.manage`, `funding-agreements.create/update`, `targets.create/update`.

## Validation
- Each new form gets negative steps that trigger each server rule and check the Arabic message at its field. Examples: donor required, at least one project, the reason of at least 10 characters, a reminder date in the future, grant end ≥ start, donor report period inside the grant, milestone inside the proposal period, task due date not before its request date, submitted-on ≤ today, a center required on a new project, a period that is not in the future, a value ≥ 0 and ≤ 100 for percentages, a unique library code.

## Edge cases and concurrency
- A tester's role was edited by an admin in the UI. `permissions:sync` adds and never removes, so record each tester's actual permissions next to the matrix.
- Department-scoped task visibility applies to `task_department_scoped_roles`.
- An inactive HR employee as a reviewer, assignee or MEAL owner shows "inactive" and is not notified.
- Alerts based on working days cannot be time-travelled on staging. UAT checks the UI and the settings; the frozen-time Pest tests prove the timing. List those tests in the DoD matrix.
- On staging, email goes to the log or the test mailbox; the queue worker must run for notifications.

## Data integrity
- Write a read-only SQL appendix to run on the staging DB before and after UAT:
  - orphan checks: `media` rows whose polymorphic owner is missing (no FK exists); `plan_indicators` with a dangling source (known; see `.ai/rules/database-migrations.md`);
  - unique pairs that must hold: one agreement per proposal, one generated transfer record per agreement, `(project_id, period)` on `monthly_reports`, and at most one open project per `center_id`;
  - per-table counts.
- `php artisan files:prune --dry-run` on staging lists orphaned files without deleting anything.
- Staging data:
  - Option (a) is a fresh `migrate:fresh --seed` on a staging-only database. This is destructive; the developer runs it, and never on the rehearsal copy or on any database with real role edits, because `DatabaseSeeder` runs `RolesAndPermissionsSeeder`.
  - Option (b) is the Task 27 copy with no seeding (real data; management decides on privacy).
  - The plan names `DemoDeliverySeeder` as the new-path seed. Check whether it now creates new-path data (project centers, proposal donor, reviewers, logframe, proposal milestones and department tasks, generated monthly reports, transfer records). If not, record it as a gap; do not write a seeder without approval.

## Implementation guidance
- Write the UAT scripts in Arabic for staff testers. Name each UI element by its Arabic label from `lang/ar/*.php` followed by the English name in bold, e.g. «التقرير الشهري» (**Monthly Report**). Never invent labels.
- IDs:
  - scenarios: `UAT-P1-01`…;
  - concurrency: `UAT-CC-01`;
  - penetration: `PEN-01`;
  - accessibility: `A11Y-01`;
  - defects: `DEF-001`, each with severity, owning task, steps, expected/actual, status and fix reference.
- The QA report is in Arabic like the plan. Commands, test names and code identifiers stay in English. Paste the gate outputs as summaries: counts and failing test names.
- The sign-off sheet has lines for the MEAL, Funding and Finance representatives: role, date, result, signature. No names until they sign.

## Tests (Pest)
- Gate commands: `php artisan test --compact`, `vendor/bin/phpstan analyse`, `vendor/bin/pint --dirty --format agent`.
- Write only the approved gap-closing tests, in the existing style:
  - `userWithRole()`;
  - a fresh `Livewire::actingAs(...)->test(...)` for each forbidden call;
  - `insertX()` helpers that return ids, read back with `DB::table()`;
  - `DB::listen` for query counts.
- Run each new file with `php artisan test --compact tests/Feature/<File>.php`. A test that fails because the application is wrong stays and is logged as a defect: mark it with `->todo()` only if the developer asks, and never weaken an assertion to make it pass.

## Acceptance criteria
- [ ] The gate results are recorded: the full suite passes 100% (or the failures are logged as defects), PHPStan shows no new errors, Pint is clean.
- [ ] The DoD matrix covers every new Action, page, command and model, and every gap is either closed by a test or logged.
- [ ] The UAT scripts cover scenarios 1–5 and the new 6–9 (aggregated donor report, MEAL and management oversight, project = center, no-money user) plus the reviewer, **Correct Status** (General Manager / Public Relations Manager), General Manager supervision, sectors, pillars and digest scripts, and scenarios 10–15 from Revisions 8–9, with negative and money checks in each.
- [ ] Go/No-Go evidence is ready by the end of day 13 (CP6).
- [ ] The concurrency, penetration, accessibility/RTL and data-integrity checklists exist and are executable by non-developers.
- [ ] The defect log and severity rules exist, and the Go/No-Go summary lists the open Critical/High defects (target 0) and the signature status.

## Do not
- Test only the happy path, or test only with Super Admin.
- Fix application code, relax a test, or add an exemption to make a gate pass.
- Run anything on production, run seeders on a database with real role edits, or run `migrate:fresh` without the developer's explicit request.
- Put personal data, amounts or credentials in documents, tests or chat.
- Mark a scenario passed without evidence, or sign on behalf of a stakeholder.

## Quality bar (this task)
- Each UAT script lists persona, preconditions, numbered steps, expected result and an evidence column; negative and no-money checks sit next to the happy path.
- Accessibility and UI checks follow `quality-bar.md` section 3 (keyboard on grids, labels, contrast in both themes, 375 px, RTL); record failures as defects with severity.
- The document is read by non-developers under time pressure: numbered steps, one action per step, the expected result next to it, and an explicit stop condition. Arabic text, English identifiers exact.

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
- the gate commands run, with pass/fail counts and the failing test names;
- the DoD gaps found, with which ones you closed and which you logged;
- the defect count by severity and owning task;
- the decisions assumed (persona roles vs the signed matrix, D-15, D-16, D-23, D-29, D-30, D-31, D-32, D-33);
- follow-ups;
- the handoff for Task 29: the Go/No-Go status at CP6 (full or phased release under R9, with the modules whose permissions stay withheld), the open defects with severity, the UAT signature status, the staging topology tested (queue, broadcast, scheduler), and any smoke checks Task 29 must repeat on production.
````
