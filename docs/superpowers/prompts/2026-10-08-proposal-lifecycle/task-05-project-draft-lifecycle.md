# Task 05 — Project as a dateless draft and project statuses v2

> **المهمة:** المشروع كمسودة بلا تواريخ وحالات المشروع v2
> **المطوّر:** Dev 1 · **الفريق:** A · **الأيام:** 7–8 (الثلاثاء 2026-10-20 – الأربعاء 2026-10-21) · **الجهد:** يومان · **يعتمد على:** Task 04، Task 30 (المشروع = المركز)
> **متى تستخدمه:** بعد دمج Task 04 (CP2، نهاية اليوم 6، الاثنين 2026-10-19) ودمج Task 30 (`projects.center_id` و`HrCenter`، مجمّدة في CP1)، وحسم D-06 وD-27 وD-30 في سجل القرارات أو تطبيق افتراضيها.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 05 of the HC Tracking proposal-lifecycle correction plan: "Project → Draft (No Begin-End Dates)". The outcome: a project is created as a `draft` with no start or end date; nobody can move it out of `draft` by hand (donor approval + owner acknowledgement makes it `approved` in Task 08, and MEAL's confirmation of its monthly report template makes it `active` in Task 33); `submitted` disappears from the UI, while `approved` stays as a **system-only** status meaning "funded, awaiting the monthly report template" (Revision 4); an active project is closed through a dedicated `CloseProject` action; and restoring from the archive respects "drafts have no dates". In the corrected story the project is the starting point (draft, no dates) → concept note → proposal → donor approval fixes the dates (`approved`) → template confirmed (`active`) → one agreement; this task makes the project side of that story true and tamper-proof. Since Revision 6 a project sits inside an HR centre: a centre may host several projects, with one open project per **centre and program**, and a new grant for an existing project is a new proposal on that project.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`, section `### Task 05` — Arabic and authoritative; status values and UI names (**Projects**, **Plan**, **Executive Summary**) are exact. Also its «تعديلات المراجعة 2» block, `### Task 30` (the contract you consume), sections 1.0 and 1.1, and section 9 rows D-04, D-05, D-06, D-27, D-30.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` (if it exists; a recorded decision overrides the plan's default for D-xx).
4. Rules: `.ai/rules/enums.md` (lower-case values; exhaustive `match` without `default`; how `blocked` was retired from `TaskStatus`), `.ai/rules/views-enums.md`, `.ai/rules/models.md`, `.ai/rules/providers.md` (strict mode, lazy loading), `.ai/rules/database-migrations.md`, `.ai/rules/migrations.md`, `.ai/rules/factories.md`, `.ai/rules/seeders.md`, `.ai/rules/tests.md`, `.ai/rules/pages.md` (`wire:model` modals, page header, "Hide money, keep names"), `.ai/rules/ui.md`, `.ai/rules/views.md`, `.ai/rules/views-pages.md` (slug routes), `.ai/rules/executive-summary.md`, `.ai/rules/alerts.md`, `.ai/rules/livewire-concerns.md`, `.ai/rules/components-ui.md`.
5. Source: `app/Enums/ProjectStatus.php`, `app/Models/Project.php` (`open()` scope, `isOwnerRejected()`, `ownerAssignment()`), `app/Concerns/HasAssignments.php`, `app/Policies/ProjectPolicy.php`, `app/Actions/Projects/ArchiveProject.php`, `app/Actions/Projects/RestoreProject.php`, `resources/views/pages/projects/⚡index.blade.php` (`saveProject()`, `statusLocked()`, `ensureOwnerAcknowledgedToLeaveDraft()`, `applyLens()`, `restoreProject()`, status filter and form select over `ProjectStatus::cases()`), `resources/views/pages/projects/⚡show.blade.php` (Plan tab), `resources/views/components/ui/project-timeline.blade.php` (lifecycle bar over `ProjectStatus::cases()`), `resources/views/pages/dashboard/⚡index.blade.php`, `resources/views/pages/programs/⚡show.blade.php`, `resources/views/pages/timeline/⚡index.blade.php`, `app/Livewire/Concerns/BuildsPortfolioGantt.php`, `app/Notifications/Alerts/DeadlineCatalog.php`, `app/Notifications/Alerts/DataGapScanner.php`, `app/Notifications/Recipients/ProjectRecipients.php` (treats a change to `approved` as a key event), `database/factories/ProjectFactory.php`, `database/migrations/2026_09_18_154724_remove_blocked_status_from_tasks.php` (precedent).
6. Skills: `laravel-best-practices`, `laravel-security`, `livewire-development`, `fluxui-development`, `database-migrations`, `testing-best-practices`. Use Boost `search-docs` (`prohibited` rule, Livewire validation) and `database-schema` on `projects` before the migration.

## Preconditions to verify
- Task 04 is merged: `ProposalPhase::Ideation` and `ProposalStatus::OnHold` exist and `app/Actions/Proposals/TransitionProposal.php` exists.
- Task 30 is merged: `projects.center_id` exists and `App\Models\HrCenter` / `App\Support\EmployeeProfile` exist.
- D-06, D-27 and D-30 are recorded (or defaults applied). If a precondition fails, stop and report.
- On the local DB, count projects by status and drafts that carry dates (Boost `database-query`); report the numbers before changing anything.

## Revision 2 changes (2026-10-08)
- Timeline: Team A, days 7–8 (2026-10-20 → 2026-10-21), after Task 04 is merged (CP2) and Task 30 is merged; Task 08 starts on day 9 on top of this task.
- Project inside a centre (D-30, section 1.0 fact 1, as amended in Revision 6): the HR centre and the program are REQUIRED when creating a project (picker over `HrCenter` from Task 30). The city is derived from the centre and written by the server (`city_id` from `HrCenter.city_id`), never accepted from the request. The default project name is «{Program} — {Center}» (editable).
- One open project (`draft`, `approved` or `active`) per centre **and program**: creating a project, restoring one from the archive and any re-open are refused when the same centre already has another open project of that program; another program in the same centre is fine. Reuse Task 30's check. Enforce it server-side inside the transaction (lock the competing rows); propose in plan mode whether a database-level guard is feasible (HR has no FK, `center_id` is nullable until Task 27 fills it).
- The "renew project" option is removed from the project form: renewal = a new proposal on the same project (Task 10, Team B / B3).
- A `closed` project that receives a new proposal returns to `draft` without dates; its historical dates stay readable in **Activity Log** (`activities`), and new dates are written at approval (Task 08, D-05). The move itself already exists when you start: Task 06 (merged on day 6) does it inside `SaveProposal` / `LinkProposalProject` through a small step (see its report, e.g. `App\Actions\Projects\ReopenProjectAsDraft`) built on Task 30's one-open-project-per-centre check; `closed` projects are linkable, `archived` ones are not. Keep that step working with the new status rules, and reuse the same check for create and restore here; do not write a second one.
- D-27 (updated): restore returns `closed` when the project has dates, otherwise `draft`, and only when the centre has no other open project.
- Team B consumers: Task 10 (B3, duplicate detection by centre), Task 15 (B1, MEAL officer per city/centre), Task 17 (B1, centre manager = project owner) read `projects.center_id` and the one-open-project rule.

## Revision 4 changes (2026-10-12)
Source: plan header paragraph «المراجعة 4», section 1.3 (project status line), D-39, D-45 and the «تعديلات المراجعة 4» subsection of this task. Where this block conflicts with the Revision 2 block or the base sections, this block wins.
- **`approved` returns as a project status**, system-only: remove `@deprecated` from `ProjectStatus::Approved` only (`Submitted` stays deprecated). Its label is "Funded, awaiting report template" / «ممول، بانتظار نموذج التقرير», shown with `x-ui.enum-badge`.
- **Transitions:** `draft → approved` happens only in Task 08 (approval + owner acknowledgement); `approved → active` happens only in Task 33 (`ConfirmMonthlyReportTemplate`). Both are refused from the project form, tampered requests included.
- The live statuses become `draft`, `approved`, `active`, `closed`, `archived`; `approved` is never a manual option in a picker, but it appears in filters, lenses, counts, charts and the lifecycle bar. `isOpen()` and `next()` follow the enum order, which now matches the flow; `approved` counts as open.
- **Defensive migration:** `submitted` → `draft` (or `active` when it has dates); `approved` with dates **stays `approved`** and enters the Task 33 template queue; `approved` without dates → `draft`. Name it accordingly (e.g. `retire_submitted_project_status`).
- `ProjectFactory` gains `approved()` (dated, no confirmed template). Scanners and the "open projects" lens treat `approved` as open; monthly-report alerts concern `active` only.
- Tests: neither `approved` nor `active` is accepted from the project form; the badge shows for `approved`; an `approved` project blocks a second open project for the same centre and program.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-06, D-22, D-27, D-30, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- Center and program are required; uniqueness is on the pair (D-30). Default name «{Program} — {Center}».
- D-06 confirmed: only the rejected proposal is archived; the project stays a draft with a «no active proposal» badge; archiving is manual. No auto-archive listener.
- D-27 confirmed: restoring from the archive → `closed` if the project has dates, else `draft`, provided no other open project exists for the same center and program («للمركز مشروع مفتوح لهذا البرنامج»).
- Optional per-project working days (D-22) and a sectors multi-select (Task 34; at least one sector required for new projects, S-2). **Ownership:** Task 21 (Dev 3) migrates `projects.working_days` and `WorkingCalendar::forProject()`; Task 34 (B3) builds the sectors module, `SyncProjectSectors` and a reusable sectors field. You own the project form: render the working-days field (ISO weekday checkboxes, "use the organisation's week" when empty) and leave one clearly marked slot where Task 34 drops its field after your merge. If a column does not exist yet when you start, list it as a follow-up rather than creating it.

## Scope
### In scope
- `ProjectStatus`: keep `Submitted` in the enum with `@deprecated` (old `activities` rows must still render) and keep `Approved` as a live system-only status (Revision 4); add a helper listing the live statuses (`draft`, `approved`, `active`, `closed`, `archived`) and another listing the manually selectable ones, and use them everywhere a picker, filter, chart or lifecycle bar iterates `cases()`; keep `next()` an explicit, exhaustive `match`.
- `Project`: a display-only `phase()` (Ideation/Planning/Implementation/Monitoring/Closure/Archived, using `ProposalPhase`) derived from the project status and its current proposal (latest non-rejected), computed from eager-loaded data.
- Create the defensive data migration for `submitted` (and dateless `approved`) rows as described in Revision 4.
- Create `app/Actions/Projects/CloseProject.php` (active → closed). Update `RestoreProject` (D-27).
- `projects/⚡index`: form hides and rejects dates while draft, never offers `approved` or `active` as a choice, never offers `submitted`; filters, lenses, counts (with `approved` as its own lens/badge). `projects/⚡show`: status display, Plan tab message for drafts, **Close Project** entry, "No active proposal" badge (D-06). `x-ui.project-timeline`, dashboard, programs/show, timeline page, portfolio Gantt.
- `ProjectFactory`: default draft without dates; `active()` (and `closed()`) with dates.
- Labels and messages in `lang/{en,ar}/projects.php` (and `enums.php` only if a label changes).
- Revision 2: required centre field on create (`HrCenter` picker), server-derived `city_id`, default name from the centre, removal of the "renew project" option, the one-open-project-per-centre guard on create/restore/re-open, and closed → draft (dateless) when a new proposal arrives.
### Out of scope
- Funding from an approved proposal and on owner acknowledgement (`FundProjectFromApprovedProposal`, Task 08) and activation on template confirmation (`ActivateProjectFromConfirmedTemplate`, Task 33).
- Renewal as a new proposal and duplicate detection (Task 10); start/end notifications (Task 21); `projects.center_id`, `HrCenter`, `EmployeeProfile` and the `hr:map-project-centers` command themselves (Task 30).
- Changing `ProjectRecipients` key events (note it as a follow-up for Task 08/21: `approved` now means "funded, awaiting template" and maps to the `project.funded` catalog event).

## Business rules
- Creating a project: status `draft`, `start_date` and `end_date` NULL, date inputs not rendered.
- `draft → approved`: only automatically when a linked proposal is approved and the owner has acknowledged (Task 08). `approved → active`: only automatically when MEAL confirms the monthly report template (Task 33). No manual option anywhere.
- `active → closed`: manual, by a holder of `projects.update`, the project owner or the program manager (exactly `ProjectPolicy::update`).
- Archive and restore stay on `projects.delete`. Restore returns `closed` when the project has dates, otherwise `draft`, and is refused when the centre already has another open project of the same program (D-27, D-30).
- Create: centre and program required; city derived from the centre; one open project per centre and program (D-30); default name «{Program} — {Center}».
- After activation, dates may be edited by whoever passes `ProjectPolicy::update`; the existing "project runs past the grant term" warning stays.
- The owner-rejection lock (ASG-03) is unchanged.
- Recommended in plan mode: drop the status select from the edit form (status moves only through Close, Archive/Restore, Task 08 and Task 33). If the team keeps it, restrict its options server-side: draft → draft only; approved → approved only; active → active or closed (closing runs `CloseProject`).
- Open question for plan mode: the exact `phase()` mapping (recommended: archived → Archived; closed → Closure; draft → current proposal's phase, or Ideation when none; approved → Implementation (waiting for the template); active → current proposal's phase when it is implementation/monitoring/closure, else Implementation).

## Security and authorization
- `ProjectPolicy::update` is unchanged. Add a server-side rule: dates are never accepted while the project is draft, even for a holder of `projects.update`.
- A tampered Livewire request that sets `startDate`/`endDate` on a draft, or `projectStatus = active` on a draft, fails validation; hiding the input is not protection.
- `CloseProject` calls `Gate::authorize('update', $project)` inside the action and reloads the project with `lockForUpdate()`.
- Status changes are logged in **Activity Log** (`activities`) by `RecordsActivity` (`project.status_changed`); do not bypass Eloquent for user-facing writes.

## Validation
Messages in `lang/en/projects.php` and `lang/ar/projects.php`:
- Draft or new project: `startDate`, `endDate` → `prohibited` — "Project dates are set when the proposal is approved." / «تُحدَّد تواريخ المشروع عند اعتماد المقترح».
- Active/closed: `endDate` ≥ `startDate` (existing rule and message).
- Status: `approved` and `active` are refused from the form — "A project is funded when its proposal is approved, and becomes active when MEAL confirms its monthly report template." / «يُموَّل المشروع باعتماد مقترحه، ويصبح نشطًا عند تأكيد MEAL لنموذج تقريره الشهري».
- Close: only from `active` — "Only an active project can be closed." / «لا يُغلق إلا مشروع نشط».
- `centerId`: required, integer, exists in `HrCenter` (active centres) — "Choose the centre." / «اختر المركز».
- Centre already has an open project of this program (create, restore, re-open) — reuse Task 30's message and check: «لهذا المركز مشروع مفتوح لهذا البرنامج: {name}. أضِف مقترحًا جديدًا عليه بدل إنشاء مشروع».
- A tampered `cityId` is ignored; the saved city is the centre's.

## Edge cases and concurrency
- Draft with dated milestones (early planning): allowed; the **Plan** tab shows "The schedule is activated once the proposal is approved" / «تُفعَّل الخطة الزمنية بعد اعتماد المقترح» and still lists milestones (the Gantt already tolerates NULL project dates).
- Draft whose only proposal was rejected: stays draft with a "No active proposal" / «لا يوجد مقترح فعّال» badge and the archive option (D-06).
- Scanners: "active project without milestones/indicators" stays on `active` only (`DataGapScanner` already filters `active`); drafts produce no date-based alerts (make sure `DeadlineCatalog` skips NULL dates).
- **Executive Summary** and portfolio: a draft still counts as open (`isOpen()`).
- Two users close the same project, or close while an archive runs: lock the row and refuse unless it is still `active`; the second call writes nothing.
- Existing drafts that carry dates (none locally, unknown on production): the migration reports their ids and counts and does NOT null them silently — ask in plan mode (recommended: leave them, list them for the Task 27 rehearsal).

## Data integrity
- The defensive migration runs inside a transaction: count by status before, move `submitted` to `draft` when both dates are NULL, otherwise to `active`; keep dated `approved` rows as `approved` and move dateless ones to `draft` (Revision 4); count after, and print both (zero expected locally). Query builder only (no activity rows). `down()` is a documented no-op, as in the `blocked` precedent.
- Drop no column and no enum case. Old `activities` rows mentioning `submitted`/`approved` must still render their labels.
- `next()`, `isOpen()` and every `match` stay exhaustive without `default`.
- Production check for Task 27 (do not run it): `SELECT COUNT(*) FROM projects WHERE status = 'draft' AND (start_date IS NOT NULL OR end_date IS NOT NULL);` must be 0 before the release. After release, Task 08 lets a draft carry the grant dates only while it waits for its owner's acknowledgement on a donor-approved proposal.

## Implementation guidance
- `declare(strict_types=1);`, explicit types, CarbonImmutable dates, `#[Fillable]` unchanged.
- `CloseProject::handle(Project $project): void` mirrors `ArchiveProject` (Gate inside, then a locked re-read and state check, in `DB::transaction`).
- `Project::phase()` must not lazy-load under strict mode: callers eager-load `proposals:id,status` (or pass them in); add the eager load wherever the phase is shown.
- `ProjectFactory`: remove `start_date`/`end_date` from `definition()`; `approved()`, `active()` and `closed()` set status plus dates, with `end_date` as a per-attribute closure derived from `start_date` (add the case to `tests/Feature/Models/FactoryStateTest.php`). Expect many tests that assumed dated default projects to need `->active()`; fix them by intent, never by giving drafts dates.
- Modals bind `wire:model` booleans; status chips use `x-ui.enum-badge`; follow the `x-ui.*` and Flux components already on these pages.

## Tests (Pest)
- Create `tests/Feature/ProjectDraftLifecycleTest.php`: creating a project saves a dateless draft; a draft rejects tampered dates with the message; a draft cannot be set to `active` from the form; the date inputs are not rendered for a draft; an active project's dates can be edited; restore returns `closed` with dates and `draft` without; the defensive migration maps `submitted` and dated/dateless `approved` rows as in Revision 4 (insert raw rows with `DB::table()`, run the migration's `up()`, read back); neither `approved` nor `active` can be chosen from the form; the `approved` badge renders in both languages.
- Revision 2 (in `ProjectDraftLifecycleTest` or a new `tests/Feature/ProjectCenterTest.php`): creating without a centre fails with the message; the city is derived from the centre and a tampered city is ignored; a second open project for the same centre and program is refused on create and on restore while another program in the same centre is accepted; a closed project may become a new draft only when no other open project of that centre and program exists; the "renew project" option is gone.
- Create `tests/Feature/CloseProjectTest.php`: owner and `projects.update` holder close an active project; another user gets 403 (fresh Livewire instance per forbidden call); a draft or closed project cannot be closed; a double close writes one change; an owner-rejected project cannot be closed.
- Update: `tests/Feature/Models/ProjectLifecycleTest.php` (`next()`, `open()`), `tests/Feature/Assignments/ProjectAssignmentTest.php` ("a draft cannot leave draft until its owner acknowledged" becomes "a draft never leaves draft from the form"), `tests/Feature/Models/FactoryStateTest.php`.
- Must stay green: `ProjectDirectoryTest`, `ProjectDetailTest`, `ExecutiveSummaryTest`, `ProgramDetailTest`, `DashboardTest`, `GanttTimelineTest`, `UiComponentsTest`, `AccessControl/ProjectAuthorizationTest`, `Notifications/DeadlineCatalogTest`, `Notifications/IndicatorAndGapScannerTest`, `LocalizationTest`.
- Run: `php artisan test --compact tests/Feature/ProjectDraftLifecycleTest.php tests/Feature/CloseProjectTest.php`, then the files above.

## Acceptance criteria
- [ ] No form or action of this task gives a `draft` project `start_date` or `end_date` (test, plus the production query handed to Task 27). The only exception, added by Task 08, is a draft linked to a donor-approved proposal that waits for its owner's acknowledgement; write the invariant test so Task 08 can extend it to "no draft holds dates unless it has a donor-approved proposal" without rewriting it.
- [ ] No "Submitted" option appears anywhere; "Approved" (funded, awaiting template) appears in filters, badges and the lifecycle bar but is never selectable in a form.
- [ ] `CloseProject` works for the allowed people only; restore follows D-27.
- [ ] Every page that shows a project status works in Arabic and English.
- [ ] `ProjectDirectoryTest`, `ProjectDetailTest`, `ExecutiveSummaryTest` pass.
- [ ] Every new project has a centre and a program, its city comes from the centre, and no centre has two open projects of the same program (test + a read-only check query for Task 27).

## Do not
- Delete `Submitted` from the enum (history in `activities` would stop rendering), or deprecate `Approved` (Revision 4 made it live again).
- Fill default dates (today) on drafts to please the Gantt.
- Offer a manual `draft → approved`, `approved → active` or `draft → active` path, or rely on hiding the date inputs.
- Change `ProjectPolicy::update` or the owner-rejection lock.

## Quality bar (this task)
- A draft project shows no date inputs at all (not disabled ones) and one line explaining when dates are set; `approved` shows the «ممول، بانتظار نموذج التقرير» badge.
- **Close Project** goes through a confirm modal; lens counts and status filters show only live statuses, with `approved` as its own lens.
- The working-days field lists weekdays in the locale's week order with "use the organisation's week" when empty; the project form groups center, program, name and sectors in one section.
- Everything in `quality-bar.md` applies. Before reporting, open every new or changed screen in the browser (Arabic RTL and English, light and dark, desktop and 375 px), run `vendor/bin/phpstan analyse` on the touched paths, and list both in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions; wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside this task's scope; note anything else as a follow-up.

## Report back
Your final message must contain: summary; files changed; migrations added (with the before/after counts on local data); tests run with pass/fail output; decisions assumed (D-04, D-05, D-06, D-27, D-30 and the plan-mode answers on the status select, `phase()` mapping, the DB-level one-open-project guard and how Task 06's closed → draft step fits the new status rules); follow-ups (`ProjectRecipients` key event on `approved`, drafts with dates found anywhere, projects still without a centre); and the handoff facts: the live-status and selectable-status helper names, `CloseProject` signature, the factory states (`approved()`/`active()`/`closed()` dated, default draft dateless), the rules "only Task 08 writes draft → approved" and "only Task 33 writes approved → active", where the one-open-project-per-centre-and-program check runs (create, restore, and Task 06's closed → draft step), and the form slots left for working days and sectors — Task 08 (`FundProjectFromApprovedProposal` sets dates and `approved`), Task 33 (activation) and Team B rely on them: Task 10 (B3: renewal = new proposal on the same project, duplicate detection by centre), Task 15 and Task 17 (B1: centre → city and centre manager).
````
