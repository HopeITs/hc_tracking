# Task 31 — Proposal as Planning Container (milestones and department main tasks for Media and Finance)

> **المهمة:** المقترح حاوية التخطيط منذ أول مسودة: **المراحل (Milestones)** و**المهام الرئيسية** المسندة حسب القسم لفريقَي الإعلام والمالية، وإزاحتها إلى التواريخ الفعلية عند الاعتماد، وصفحة **Department Tasks** بلا مال. تحلّ محل Task 20 (مخرجات الإعلام).
> **المطوّر:** B3 (مراجعة Dev 2) · **الفريق:** B · **الأيام:** 4–6 · **الجهد:** 3 أيام · **يعتمد على:** Task 03 (CP1)، Task 30 (الأقسام من HR)، Task 02 (الصلاحيات)؛ يتكامل مع Task 12 (Activities) دون انتظارها
> **متى تستخدمه:** بعد CP1 (مخطط `proposals` و`EmployeeProfile` مجمّدان) — التصميم في اليوم 3، الترحيلات في اليوم 4، تبويب **Plan** في اليوم 5، و**Department Tasks** ومستمع الإزاحة في اليوم 6 بعد أن يكتب Dev 1 أصناف الأحداث (CP2).
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 31 of the HC Tracking proposal-lifecycle correction plan. Management's clarification: from the moment a proposal is created it holds its whole plan — logframe and indicators (Task 12/13), **milestones**, and the **main tasks of the Media and Finance teams, assigned by department**. You add milestones and department tasks to the proposal from its first draft (`concept note`), show them on a **Plan** tab, give each department a **Department Tasks** page with no money on it, and when the donor approves (D-28) shift the planned dates to the actual grant period once. Media deliverables (old Task 20) become `tasks.kind = media_deliverable`; there is no `media_deliverables` table and no `media-deliverables.*` permission. You are on Team B: you do not change Phase-1 tables (`proposals`, `projects`, `donor_proposal`, `proposal_status_notes`) or the proposal state machine.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins) (Laravel 13, PHP 8.5, Livewire 4 + Flux UI 2, Pest, Pint, Boost tools, `php artisan make:* --no-interaction`, no new dependencies or base folders without approval).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md` (Arabic, authoritative; English names are exact): section 0 ("rules between teams" 1–3, phase 2), section 1.0 (fact 4), `### Task 31` in full, `### Task 20` (merged note), Task 08 "تعديلات المراجعة 2" (event contract), section 5 (rows "department tasks", BOLA, files, concurrency), section 7 (CP1, CP2, CP4), section 9 entries D-16, D-28, D-33, D-34.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists — a recorded decision overrides the plan's default (D-16 what Media sees, D-33 department-then-person, D-34 shared milestones and one-time shift).
4. Rules (read all; confirm against `.ai/rules/index.md` for every file you add): `database-migrations.md`, `migrations.md`, `feature.md`, `pages-funding-agreements.md`, `models.md`, `views-pages.md`, `enums.md`, `views-enums.md`, `factories.md`, `seeders.md`, `observers.md`, `providers.md`, `alerts.md`, `views-components-notifications.md`, `proposals.md`, `admin.md`, `proposalsfunding-agreementstransfersdonors.md`, `milestones.md`, `milestonestasks.md`, `tasks.md`, `livewire-concerns.md` (gantt), `components-ui.md`, `funding.md` (matches `app/Livewire/Concerns/**`), `concerns-factories.md`, `pages.md`, `ui.md`, `views.md`, `routes.md`, `app.md`, `components-navigation.md`, `navigation.md`, `navigation-css.md`, `tests.md`. Then run `grep -rin 'source_type\|task_department\|department\|milestone\|hide money\|working day' .ai/rules`.
5. Study first (verify each exists with Glob/Grep): `app/Models/{Task,TaskAssignee,TaskAttachment,Milestone,Proposal,Media}.php` (`Task::source()` is a morph with no FK; morph keys `proposal`, `project`, `milestone`, `program`, `task` in `AppServiceProvider`); `app/Models/Scopes/TaskVisibilityScope.php` (`constrain()`, `allows()`; already scopes by `User::departmentEmployeeIds()` via assignees/creator); `app/Models/User.php` (`isTaskDepartmentScoped()`, `departmentId()`); `config/access-control.php` (`task_department_scoped_roles`, `task_department_scope_bypass_roles`); `app/Policies/{TaskPolicy,MilestonePolicy}.php`; `app/Livewire/Concerns/ManagesTaskVisibility.php`; `app/Actions/Tasks/*`; `app/Observers/MilestoneObserver.php`; `app/Concerns/HasTree.php`; `HrDepartment::employeeIdsInDepartment()` and Task 30's `App\Support\EmployeeProfile`; `resources/views/pages/proposals/⚡show.blade.php` (it already has a `tasks` tab — decide in plan mode whether **Plan** replaces or extends it), `_task-row.blade.php`; `resources/views/pages/tasks/_list-group.blade.php`; `resources/views/components/ui/gantt.blade.php`, `BuildsProjectGantt.php`; `app/Notifications/Alerts/{DeadlineCatalog,DeadlineItem,AlertDispatcher,DueSoonScanner,OverdueScanner,WorkingCalendar,NotificationSettings,AlertLedger}.php`; `app/Support/{StoredFiles,UploadName}.php`; `app/Rules/SecureUpload.php`; `tests/Pest.php` (`insertTask`, `insertMilestone`, HR helpers).
6. Activate skills: `laravel-best-practices`, `livewire-development`, `fluxui-development`, `database-migrations`, `laravel-security`, `testing-best-practices`.
7. Use Boost `database-schema` on `tasks`, `task_assignees`, `milestones`, `proposals`, `media` before writing migrations; `search-docs` for event listeners (`ShouldQueue`, `afterCommit`), Livewire file uploads, Flux modals.

## Preconditions to verify
- CP1: Task 03 merged (`proposals.planned_date_begin/end`, `actual_date_begin/end` present; `approved_at` or the approval record used as the shift key — confirm the column name with Dev 1).
- Task 30 merged: `EmployeeProfile::employeeIdsInDepartment()` (or `HrDepartment::employeeIdsInDepartment()`) and a department lookup exist.
- Task 02: permission names for `milestones.*`, `tasks.*`, `proposals.update` are frozen; no new `media-deliverables.*` names.
- **Approval event (Revision 4):** `App\Events\ProposalStatusChanged(proposalId, from, to, actorId)` (written by Dev 1 on day 6, CP2, dispatched by Task 08's `ApproveProposal` for the `→ approved` move). If the class does not exist when you reach the shift listener, **stop and report** — do not create the event class yourself. Only if the developer explicitly approves may you write the listener against the documented signature and leave it unregistered until the class lands.
- Task 12 (`milestones.result_id`) may not be merged: make the activity link optional and skip it if the column is absent.
If a hard precondition fails, stop and report instead of improvising.

## Revision 4 changes (2026-10-12)
Source: plan header paragraph «المراجعة 4» and `### Task 08` «تعديلات المراجعة 4». Where this block conflicts with the base sections, this block wins.
- Since Revision 4, `ProjectActivated` fires when MEAL confirms the monthly report template (Task 33), which can be days after the donor's approval, and it never fires for a project that was already `active` when a new proposal was approved. The plan's base text ("listener of `ProjectActivated`") predates that change, while D-34 and the shift key (`proposal_id` + approval) tie the shift to **approval**. So: trigger `RebaseProposalPlan` from `ProposalStatusChanged` where `to = approved` (once per proposal, after commit, queued, idempotent), not from `ProjectActivated`. Raise it in plan mode and record the choice in your report so the plan can be corrected.
- Task 12 shifts the activity calendar from the same event with the same delta; agree with B1 that both use one delta helper so milestones, department tasks and activity months never drift apart.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-02, D-05, D-34, D-49, D-51, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- D-05: Gantt per proposal on **Proposal**; overall Gantt on **Project** with an «all proposals / one proposal» filter and a colour per proposal, reusing `x-ui.gantt`.
- D-02a: the «Final Report» milestone template (with a PR task) in **Add Milestone** is the only way to create it; it carries `is_final_report`, read by the completion gate (Task 04, D-02b).
- D-34 confirmed: milestones are shared at proposal level (a center-specific one carries `project_id`) and shift once at approval.
- D-49/D-51: the overall Gantt shows activity rows coloured by the Excel statuses (Not Started, Started, Ongoing, Suspended, Cancelled, Completed, Conducted); milestones are not changed by activity confirmations.

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
- **D-49b:** the overall and per-proposal Gantt show activity weeks when `activity_schedules.weeks` is set, otherwise the whole month. Week zoom is optional UI; the data contract does not change.

## Scope
### In scope
- Migrations: `milestones.proposal_id` (FK `proposals`, nullable; the plan says `cascadeOnDelete` "for drafts only, via the Action" — propose in plan mode whether the FK cascades or restricts while the delete Action removes children of drafts only), `milestones.project_id` → nullable; `tasks.department_id` (HR id, no FK, indexed), `tasks.kind` (default `general`), `tasks.media_kinds` (JSON), `requested_on`, `delivered_on`, `delivered_link`, `delivered_text`, `accepted_at`, `returned_reason`, `plan_shifted_for` (or a separate shift ledger — propose in plan mode).
- Enums `App\Enums\TaskKind` (`general`, `media_deliverable`, `financial`) and `DeliverableKind` (`video`, `photos`, `file`, `text`) with `HasTranslatedLabel` and `values()`; a delivery state (`requested`, `delivered`, `accepted`, `returned`) — reuse `TaskStatus` only if it fits, otherwise propose an enum.
- Actions in `app/Actions/Proposals/` and `app/Actions/Tasks/`: `SaveProposalMilestone`, `SaveDepartmentTask`, `DeliverDepartmentTask`, `AcceptDepartmentTask`, `ReturnDepartmentTask`, `RebaseProposalPlan`; draft deletion cleanup.
- Listener `RebaseProposalPlanOnApproval` (queued, idempotent) on `ProposalStatusChanged` with `to = approved` (Revision 4).
- The «Final Report» milestone template in **Add Milestone** (D-02a: a milestone flagged `is_final_report` plus a PR task) — add the `milestones.is_final_report` boolean if Task 04 did not.
- Gantt on **Proposal** (its milestones and tasks within its period) and the overall Gantt on **Project** with the per-proposal filter and colours, plus activity rows by status and weeks (Revisions 6–9).
- `TaskPolicy`/`MilestonePolicy` updates; `TaskVisibilityScope` gains "`tasks.department_id` = my current department".
- **Plan** tab on **Proposal** (milestones tree; department tasks grouped Media / Finance / Other; suggested assignee from the department). Gantt of each linked project shows proposal-level milestones (D-34).
- Page **Department Tasks**: `resources/views/pages/department-tasks/⚡index.blade.php`, `Route::livewire('department-tasks', 'pages::department-tasks.index')->name('department-tasks.index')` in the existing `auth` group + sidebar item.
- Alerts: on create (department head and members), due-soon in working days (setting, default 5), overdue; entries in `DeadlineCatalog`.
- Register the plan's event keys in the Task 21 catalog `App\Notifications\EventCatalog`: `department_task.created`, `.due`, `.overdue`, `.delivered`, `.returned` (direct recipients: the department, the assignee, the requester; MEAL + General Management on overdue only, plan section 3 Task 21 table). If the catalog is not merged yet, add the keys in your PR and coordinate with Dev 3 so `EventCatalogCoverageTest` stays green.
- Lang keys in `lang/{en,ar}/` (`tasks.php`, `milestones.php`, `proposals.php`, new `department_tasks.php`, `enums.php`, `navigation.php`, `titles.php`).
### Out of scope
- Logframe/Activities and indicators (Task 12/13); renewal cloning (Task 10 — leave a reusable copy method only if trivial); notification matrix and MEAL/management fan-out (Task 21); full results chain (Task 16); tours (Task 26); encryption mechanism (Task 22).
- Any change to `proposals`/`projects` columns or `ProposalStatus`/`TransitionProposal`.

## Business rules
- Milestones and tasks can be added from `concept note` and edited until `submitted` with plan rights; after `approved` they follow normal execution rights (`milestones.update` / `tasks.update`).
- A department task is assigned to a **department** first (required); the department head or a `tasks.update` holder may set a person (optional; due date within 7 days and no person → alert).
- `media_deliverable`: `requested → delivered` requires `delivered_on` ≤ today and, per `media_kinds`, a file, a link (video is link-only) or text; `delivered → accepted | returned` (reason required); `returned → delivered` again. Tasks of a rejected proposal stop alerting.
- `financial` tasks (e.g. "Donor financial report", "Payment request") are visible to Finance only; titles carry no amounts.
- Milestones are shared at proposal level, not copied per center (D-34); a center-specific milestone carries `project_id` as well. At least one of `proposal_id` / `project_id` is set (checked in the Action).
- Shift on approval: delta = `actual_date_begin − planned_date_begin`; shift planned dates of milestones and tasks by delta, clip to `actual_date_end`, record it. Applied once per approval (key = `proposal_id` + approval timestamp); dates edited by a user after the shift are never shifted again; a retried or duplicated approval event shifts once.

## Security and authorization
- Adding milestones/tasks to a proposal: `proposals.update` or `milestones.create`/`tasks.create`, plus visibility of the proposal — `Gate::authorize` inside each Action.
- Department visibility: a `tasks.view` holder whose current position (Task 30) is in the task's department, or the assignee, or a proposal-rights holder. Add it to `TaskVisibilityScope::constrain()` carefully and prove with a test that no one else gains visibility; `allows()` must follow the same rule.
- Media team sees proposal name, description, its tasks and milestones only — no money, no general `proposals.view` (D-16). **Department Tasks** never queries budget, agreements, tranches or currency, and links to `proposals.show` only for users who can view it. Skip the queries server-side; `@can` alone is not enough.
- Object-level: load through the parent (`$proposal->milestones()->whereKey($id)`, department tasks via the scoped query); a task id from another proposal or department gets 403/404 with no write.
- Files: `SecureUpload`, 5 MB, `FileType` list, `UploadName`, `StoredFiles` (use Task 22's encrypted write/stream if merged); downloads through an authorized route with `throttle:files`.
- Every public Livewire method that writes authorizes itself (`AuthorizationCoverageTest`); helpers stay `protected` (`BrowserCallableMethodsTest`). Audit via `TracksActivity` / **Activity Log**.

## Validation
Messages in `lang/en` and `lang/ar`:
- Milestone: `name` required, max:255; `plannedStartDate` ≤ `plannedEndDate`; both inside the proposal's planned period — «المرحلة خارج مدة المقترح».
- Task: `title` required, max:255; `departmentId` required and an existing HR department; `kind` Enum(`TaskKind`); `dueDate` after_or_equal `requestedOn` — «الموعد النهائي لا يسبق تاريخ الطلب»; `mediaKinds` array min:1, each Enum(`DeliverableKind`) for media tasks.
- Delivery: `deliveredOn` before_or_equal:today; `deliveredLink` url, max:2048; `deliveredText` max:5000; `files.*` as above; `returnReason` required on return, max:2000.

## Edge cases and concurrency
- Proposal without planned dates: milestones accepted without dates, with «حدّد مدة المقترح لجدولة المراحل».
- Approval shorter than planned: milestones/tasks past the end are clipped and the proposal owner is notified with the list.
- Department removed/renamed in HR: task shows «قسم غير متاح», alerts go to the proposal owner.
- Draft proposal deleted: its milestones and tasks are deleted in the same transaction; after `submitted` deletion stays blocked (existing).
- Double deliver/accept, or two people acting at once: transaction + `lockForUpdate` on the task, re-check the state; the second call writes nothing.
- Duplicate or retried approval event (`ProposalStatusChanged` to `approved`): the listener locks the proposal and checks the shift key; a failing listener never rolls back the approval (queued, retried, `failed_jobs`).

## Data integrity
- Shift runs in one transaction with the proposal row locked; Activity Log entry with actor "system" and the approval reference.
- Migration order: add columns, then make `milestones.project_id` nullable; `down()` refuses (or documents) restoring NOT NULL while proposal-only milestones exist.
- Ledger keys for alerts include `due_date` (alerts.md). All tables/columns `utf8mb4_unicode_ci`, InnoDB DYNAMIC (`DatabaseEncodingTest`).
- Mirror the `kind` default in `Task::$attributes` (strict-mode trap); factories omit default-bearing columns and expose named states (`mediaDeliverable()`, `financial()`, `forProposal()`).

## Implementation guidance
- `declare(strict_types=1);`, `#[Fillable([...])]`, `casts()`, CarbonImmutable, lower-case enum values.
- Extend `tasks` and `milestones`; reuse `x-ui.gantt`, `_list-group`, `x-ui.employee-chip`, the cached employee picker, `x-ui.page-header`, `x-ui.header-filter-select`; Flux modals bound with `wire:model` booleans.
- Working days via `WorkingCalendar`, thresholds via `NotificationSettings`, never calendar days or hard-coded numbers.
- Skeleton:
```php
final class RebaseProposalPlan
{
    /** @return array{shifted: int, clipped: list<int>, skipped: bool} */
    public function handle(Proposal $proposal, CarbonImmutable $actualBegin, CarbonImmutable $actualEnd, string $shiftKey): array {}
}
```
- After merging, update the milestones/tasks rule with Boost `record-rule` (glob `resources/views/pages/{milestones,tasks}/**`: "a milestone may belong to a proposal; `project_id` is nullable").

## Tests (Pest)
- `tests/Feature/ProposalPlanTest.php`: add a milestone and a department task to a `concept note` proposal; outside-period refused; edit allowed until `submitted`; 403 without plan rights (fresh Livewire instance per forbidden call); BOLA on a milestone/task id from another proposal.
- `tests/Feature/RebaseProposalPlanTest.php`: shift once by the begin delta; clip to the end; second event or re-run shifts nothing; user-edited dates kept; dispatch the real `ProposalStatusChanged` with `to = approved` (when the class exists) and assert one shift; a `ProjectActivated` event alone shifts nothing.
- `tests/Feature/DepartmentTasksPageTest.php`: Media employee sees own department's tasks; another department's employee without rights sees none (absent / 403); financial tasks hidden from Media; no amounts or currency in the HTML; no `proposals.show` link without `proposals.view`.
- `tests/Feature/DepartmentTaskDeliveryTest.php`: deliver without file/link/text refused; future `deliveredOn` refused; return requires reason; double delivery writes nothing; file rules.
- `tests/Feature/Notifications/DeadlineCatalogTest.php` (+ `DueSoonScannerTest.php`): due-soon in working days with frozen time (`$this->travelTo()`); rejected proposal stops alerts.
- Update `tests/Feature/Models/TaskTest.php`, `MilestoneTest.php`, `AccessControl/PolicyCoverageTest.php`; run `TaskVisibility`-related tests, `ProposalDetailTest`, `ProjectGanttTest`, `LocalizationTest`, `DatabaseEncodingTest`, `AccessControl/AuthorizationCoverageTest`, `NavigationTest`.
- Run: `php artisan test --compact tests/Feature/ProposalPlanTest.php`, then `--filter=...` per change.

## Acceptance criteria
- [ ] From the first draft, a proposal shows its milestones and Media/Finance tasks on **Plan** (logframe from Task 12 when merged).
- [ ] On approval, the plan moves to actual dates once, automatically, and each department sees its tasks on **Department Tasks**.
- [ ] No one outside the department, assignee or proposal-rights holders gains task visibility (test); the page shows no money.
- [ ] Media deliverables work end-to-end (request → deliver → accept/return) with alerts in working days.
- [ ] All listed tests pass; Pint is clean; schema for CP2 (`milestones.proposal_id`, `tasks.department_id/kind`) demonstrated.

## Do not
- Create a new tasks or media-deliverables table, or `media-deliverables.*` permissions.
- Assign department tasks to people by name instead of department.
- Show money to the Media team, or grant `proposals.view` as a shortcut.
- Create or edit `ProposalStatusChanged`, `ProjectActivated` or any Phase-1 table/state machine yourself.
- Copy milestones per center, or shift the plan more than once per approval.
- Duplicate the task forms or gantt component.

## Quality bar (this task)
- **Plan** tab: milestones tree and department tasks grouped Media / Finance / Other with due badges; the proposal Gantt reuses `x-ui.gantt`.
- **Department Tasks** page: grouped by status and due date with header filters; the delivery modal adapts its fields to the deliverable kinds (file, link, text); accept or return (with reason) through confirm modals; no money anywhere.
- Everything in `quality-bar.md` applies. Before reporting, open every new or changed screen in the browser (Arabic RTL and English, light and dark, desktop and 375 px), run `vendor/bin/phpstan analyse` on the touched paths, and list both in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions (Plan vs existing Tasks tab, delivery-state enum, shift-key storage, event availability); wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production (Team B has no production access).
- Stay inside Task 31's scope; note anything else as a follow-up.

## Report back
Your final message must contain: a short summary; files created/changed; migrations (names, up/down); tests run with pass/fail output; decisions assumed (D-16, D-33, D-34, shift key, Plan tab placement); whether the listener is registered or waiting for `ProposalStatusChanged`, and the Revision 4 trigger choice (approval, not activation) so the plan text can be corrected. Handoff facts: Task 16 reads proposal-level milestones (`milestones.proposal_id`, optional `result_id`) for the full chain; Task 21 needs the department-task event keys you registered and their recipients; Task 12 (B1) needs the final `milestones` column order so its `result_id` migration does not conflict; Task 10 clones milestones and tasks without actual dates, deliveries or files (name the method if you added one); Task 26 needs the **Plan** tab and **Department Tasks** names for tours and the guide.
````
