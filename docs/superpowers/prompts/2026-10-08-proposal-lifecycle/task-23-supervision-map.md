# Task 23 — Supervision map ("who follows whom")

> **المهمة:** خريطة «من يتابع من»
> **المطوّر:** B3 (مراجعة Dev 1) · **الفريق:** B · **الأيام:** 2–3 (الثلاثاء 2026-10-13 ← الأربعاء 2026-10-14؛ اليوم 3 نصف يوم) · **الجهد:** يوم ونصف (المصدر HR، فالجدول المحلي للاستثناءات فقط) · **يعتمد على:** Task 01، Task 02، Task 30
> **متى تستخدمه:** في اليوم 2 بعد CP0 (نهاية اليوم 2: الصلاحية `supervisions.manage` ومزامنتها، وتسجيل القرار D-13: المصدر HR). يتوازى مع Task 30 (Dev 2، اليومان 2–3)؛ اربط المدير المباشر بـ `EmployeeProfile::managerOf()` عند دمجه في CP1 (نهاية اليوم 3). يجب دمجه قبل Task 17 (يبدأ اليوم 7) لأن «الإعداد نيابة» في التقرير الشهري يعتمد عليه.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 23 of the HC Tracking proposal-lifecycle correction plan in this Laravel 13 / Livewire 4 / Flux UI 2 repository. The outcome: a supervision map whose default source is HR (the direct manager of the employee's current position, `positions.direct_manager_id`, read through Task 30's `EmployeeProfile`), plus a local `supervisions` table (supervisor HR employee → supervisee HR employee) for exceptions and additions only, maintained in a **Supervision** tab of **Users & Roles** (`/admin/users`) that also shows the HR relations read-only; and three uses of it: (1) overdue-item escalation also reaches the supervisor, next to the program manager; (2) a **My Team** card on **Home** (`/home`) shows each supervisor what their people have outstanding; (3) a read helper that Task 17 uses to let a supervisor prepare a monthly report on behalf of a supervisee. In the corrected story center managers own projects and send monthly reports, and "every manager must check" their team; supervision is a routing fact, never an extra permission.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins) (Boost tools, `php artisan make:* --no-interaction`, Pest, Pint, no new dependencies or base folders).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`, section `### Task 23` — Arabic and authoritative; English names are exact. Also Task 02 (`supervisions.manage`), Task 30 (`EmployeeProfile::managerOf()`, `HrPosition::directManager()`), Task 17 "Security and permissions" (preparing on behalf), Task 21 (overdue recipients), section 0 «قواعد العمل بين الفريقين», section 1.0 fact 9 (HR read-only), section 5 rows "النطاق (Scope)", "بيانات HR", "بيانات HR (الكتابة والحساسية)", "الإشعارات والبريد", and section 9 D-13.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists; a recorded decision overrides the plan's default for D-13 (Revision 2 default: **HR is the source** — `positions.direct_manager_id` of the employee's current position; the local `supervisions` table holds exceptions and additions only and never duplicates HR).
4. Rules: `.ai/rules/database-migrations.md`, `.ai/rules/migrations.md`, `.ai/rules/feature.md`, `.ai/rules/models.md` (HR is read-only, linked by id, no FK), `.ai/rules/middleware-actions-fortify.md` (never `whereHas()` across the `hr` connection), `.ai/rules/providers.md` (morph map), `.ai/rules/views-pages.md`, `.ai/rules/factories.md`, `.ai/rules/seeders.md`, `.ai/rules/database-seeders.md`, `.ai/rules/alerts.md`, `.ai/rules/recipients.md`, `.ai/rules/views-components-notifications.md`, `.ai/rules/policies.md`, `.ai/rules/pages.md`, `.ai/rules/ui.md`, `.ai/rules/views.md`, `.ai/rules/views-enums.md`, `.ai/rules/tests.md`. Then `grep -rin 'hr\b\|employee\|escalat\|visibility' .ai/rules`.
5. Study first:
   - `resources/views/pages/admin/⚡users.blade.php` (`mount()` → `Gate::authorize('viewAny', User::class)`, `showTab()` whitelist `['users', 'roles']`, tab buttons), route `admin.users.index` (`->can('viewAny', User::class)` = `access-management.manage-users`), `app/Policies/UserPolicy.php`, `config/access-control.php`.
   - HR: `app/Models/HrEmployee.php` (`is_active`, `NameColumns`, `pickerOptions()` includes inactive people), `app/Models/HrPosition.php` (`direct_manager_id` — relevant to D-13), `resources/views/components/ui/form-select.blade.php` (`employees` mode).
   - Alerts: `app/Notifications/Alerts/EscalationScanner.php`, `DeadlineCatalog.php`, `DeadlineItem.php` (`directUserIds` are user ids), `AlertDispatcher.php`, `NotificationSettings.php`, `WorkingCalendar.php`; `app/Notifications/EmployeeRecipients.php` (`usersFor()`), `RecipientFilter.php` (module-level view permission only), `RecipientResolver.php`, `app/Notifications/Recipients/UserRecipients.php`, `config/notifications.php` (`modules`, `view_permissions`, `module_icons`, `defaults`), `app/Notifications/SubjectLinks.php`, `app/Providers/AppServiceProvider.php` `configureMorphMap()`, `app/Concerns/RecordsActivity.php`.
   - Visibility: `app/Models/Scopes/TaskVisibilityScope.php` (`constrain()`, `allows()`), `app/Policies/TaskPolicy.php::view()`.
   - Home: `resources/views/pages/dashboard/⚡index.blade.php` (`visible()`, `canView()`, `myWork()`); nested component precedent `resources/views/components/activity/⚡timeline.blade.php`.
   - CSV: `app/Support/CsvExport.php` and `config/livewire.php` `temporary_file_upload.rules` (only `FileType` extensions — a `.csv` upload is refused before your code runs).
   - Tests: `tests/Feature/Admin/UserDirectoryTest.php`, `AccessControl/AdminSafetyTest.php`, `AccessControl/TaskVisibilityTest.php`, `Notifications/OverdueScannerTest.php` (`overdueTaskFor()`, `runScanner()`), `Notifications/DeadlineCatalogTest.php`, `Notifications/SubjectLinksTest.php`, `Models/MorphMapTest.php`, `DashboardTest.php`, `AccessControl/PolicyCoverageTest.php`, `tests/Pest.php` (`insertHrEmployee()`, `insertHrPosition()`, `userWithRole()`).
6. Skills: `laravel-best-practices`, `livewire-development`, `fluxui-development`, `database-migrations`, `laravel-security`, `testing-best-practices`.
7. Use Boost `search-docs` before framework APIs (`['unique constraint', 'generated columns', 'pessimistic locking', 'validation closures']`) and `database-schema` before the migration (confirm `users`; no `supervisions` yet).

## Preconditions to verify
- `supervisions.manage` exists in `config/access-control.php` (`groups` + General Manager baseline) and in the `permissions` table (Task 02 + `permissions:sync`).
- No `supervisions` table, `App\Models\Supervision` or `app/Actions/Supervisions/` yet.
- D-13 is recorded (default: HR is the source). If it says the opposite (local table only), stop and ask.
- Task 30's `App\Support\EmployeeProfile` (`managerOf(int)`, current position from the active contract) and `HrPosition::directManager()` exist (CP1, end of day 3). If not merged yet on day 2, build the local table and tab first and wire the HR source once it lands; never read `contracts` columns beyond what `EmployeeProfile` exposes.
- Note whether `monthly_reports` (Task 17) and `donor_reports.responsible_employee_id` (Task 19) exist yet; they decide which **My Team** sections you build now.
If a check fails, stop and report instead of improvising.

## Revision 2 changes (2026-10-08)
- Owner is now **B3 (Team B)**, reviewed by Dev 1; days 2–3 (Tue 2026-10-13 → Wed 2026-10-14 ½); effort cut from 2 to **1.5 days** because HR is now the source. Dependencies add **Task 30**.
- **D-13 changed:** the supervisor of an employee is, by default, the HR direct manager of their current position (`positions.direct_manager_id` via `EmployeeProfile::managerOf()`). The local `supervisions` table holds only exceptions and additions (extra supervisors, or an override where HR is wrong/missing); adding a pair that HR already provides is refused as a duplicate. The **Supervision** tab lists HR relations read-only next to the local exceptions. HR is never written and never copied into tracking tables.
- `SupervisionMap` must merge both sources (HR direct manager + local rows, active employees only, de-duplicated); Task 17 (prepare on behalf), Task 21 (overdue recipients) and escalation all read it.
- **My Team** card is item 3 of the R9 cut list: build it after the map, tab and escalation, so it can be dropped if late. Its monthly-report and donor-report sections depend on Task 17 (days 7–11) and Task 19 (Dev 2, days 10–12): leave them as follow-ups.
- Work under the inter-team rules (plan section 0 «قواعد العمل بين الفريقين»): do not change Phase 1 tables (`proposals`, `projects`, `donor_proposal`, `proposal_status_notes`) or the proposal state machine — request any such change from Dev 1 so it is recorded in the plan; integrate with Phase 1 by listening to its events (`ProposalStatusChanged`, `ProjectActivated`, `ProjectPeriodChanged`, `FundingAgreementRecorded`, dispatched after commit) instead of calling Phase 1 Actions directly; every PR is reviewed by Dev 1 (backend) or Dev 2 (UI) within one working day and merges only with this task's suite green; no production access — seed or masked data only.
- `⚡users` (Users & Roles) is shared admin code: keep the tab as a nested component and coordinate with Dev 3 (Task 02 edits access control on the same page).
- Register this task's events (`supervision.added` / `supervision.removed`, digest importance) in the Task 21 event catalog `App\Notifications\EventCatalog` so MEAL and General Management are notified (plan section 1.0, fact 8; `EventCatalogCoverageTest` must stay green). If the catalog is not merged yet, add the keys in your PR and coordinate with Dev 3.

## Scope
### In scope
- Migration `create_supervisions_table`: `id`, `supervisor_employee_id`, `supervisee_employee_id` (unsigned bigint, HR ids, no FK), `created_by` (FK `users`, nullable, `nullOnDelete`), timestamps, named unique index (`supervisor_employee_id`, `supervisee_employee_id`), index on `supervisee_employee_id`.
- Model `App\Models\Supervision` + `SupervisionFactory` (`CreatesHrRecords`): `#[Fillable(['supervisor_employee_id', 'supervisee_employee_id', 'created_by'])]`, `casts()`, `TracksActivity` + `RecordsActivity`, `activityEmployeeFields()` both columns, morph key `supervision`, `SubjectLinks::routeFor('supervision')` → `admin.users.index` with `tab=supervision`. Recommended `activityModule()` = `access-management` (reuses `UserRecipients`, no new module icon/banner) — confirm.
- Read helper (propose `App\Support\SupervisionMap`): `superviseesOf(int $supervisorEmployeeId): list<int>`, `supervisorsOf(array $superviseeEmployeeIds): array<int, list<int>>`, `supervises(int $supervisorEmployeeId, int $superviseeEmployeeId): bool` — merging the HR direct manager (`EmployeeProfile::managerOf()`, the default source, D-13) with local exception rows; active HR employees only, de-duplicated, memoized per request; resolve HR ids first, never `whereHas()` across connections.
- Actions in `app/Actions/Supervisions/`: `AddSupervision`, `RemoveSupervision`, and `ImportSupervisions` if the import is built.
- **Supervision** tab in `⚡users`: whitelist `supervision` in `showTab()`, tab button only for `supervisions.manage`; recommended as a nested component `resources/views/components/admin/⚡supervisions.blade.php` (`<livewire:admin.supervisions />`) because `⚡users` is already ~1000 lines. List grouped by supervisor showing HR relations (read-only, labelled "From HR") next to local exceptions, with "inactive" badges, search, add modal (two `x-ui.form-select` employee pickers), remove confirmation.
- Optional CSV import. Because Livewire's temporary-upload rules accept only `FileType` extensions, propose in plan mode: a paste-CSV textarea (no upload, nothing stored), or widening the global rules (needs approval), or deferring.
- `EscalationScanner`: supervisor escalation (Business rules).
- **My Team** card on `pages::dashboard.index` (last; R9 cut item 3).
- Register `supervision.*` in the Task 21 `App\Notifications\EventCatalog` (MEAL + General Management, digest).
- Lang: `lang/{en,ar}/users.php` (tab, form, messages), `lang/{en,ar}/dashboard.php` (card), `lang/{en,ar}/notifications.php` (`alerts.escalation.supervisor`, `events.supervision.*` with `_you` variants if the presenter needs them).
### Out of scope
Monthly report "prepare on behalf" policy (Task 17 consumes the helper); donor report responsibles (Task 19); permission catalog (Task 02); editing HR direct managers (read-only, Task 30); new roles; any write to HR.

## Business rules
- Nobody supervises themselves; a pair is unique, including against HR (a local row duplicating the HR direct manager is refused: «هذه المتابعة موجودة في HR»); a direct loop (A supervises B while B supervises A) is refused; longer chains are allowed; a person may have several supervisors.
- Both employees are active in HR when the row is created. An employee who later becomes inactive keeps the row (shown "inactive") but has no effect in the helper, the card or escalation.
- Escalation: at the program-manager stage (`escalate_manager_after_days` within `escalation_catch_up_days`, both from `NotificationSettings`), the active supervisors of the people named on the item (`DeadlineItem::directUserIds` mapped to employee ids) also receive a Critical alert, key `notifications.alerts.escalation.supervisor`, stage `supervisor:{due}`. Skip anyone already notified as program manager for that item and the named person themselves. Items without named people (watched by roles) get no supervisor escalation. Propose in plan mode whether a task's creator (also in `directUserIds`) should count as "named".
- **My Team** card: shown only to a user with a linked employee who supervises at least one active employee. Sections: overdue open tasks assigned to supervisees (count per person + link); unsent monthly reports (only if Task 17 is merged); donor reports due for supervisees (only if Task 19 is merged, and only for holders of `funding-agreements.view`, no amounts). Unbuilt sections are follow-ups, never placeholder tables.
- Removing a supervision takes effect immediately in the helper, card and escalation.

## Security and authorization
- `supervisions.manage`: `Gate::authorize` inside each Action and at the top of each Livewire method; the tab is hidden and its methods return 403 without it. The route keeps `viewAny` (`access-management.manage-users`); General Manager holds both. Do not widen the route without approval; report the gap if a role gets `supervisions.manage` alone.
- Supervision grants no visibility: `TaskVisibilityScope` is untouched. The card's task query runs as the signed-in user so the global scope applies. For task escalations, keep only supervisors for whom `TaskVisibilityScope::allows($user, $task)` (or `TaskPolicy::view`) is true, so a title never reaches someone who cannot open it; other modules go through `RecipientFilter` via `AlertDispatcher::send()`.
- Employee ids are re-checked server-side against HR (exists, active); the client picker list is never trusted. `created_by` = `auth()->id()`. Ids held by the component are `#[Locked]`.
- Audit: create and delete record activities with both names.

## Validation
Messages in `lang/ar/users.php` and `lang/en/users.php`:
- `supervisorEmployeeId`, `superviseeEmployeeId`: required, integer, an active HR employee — «اختر موظفًا فعّالًا» / "Choose an active employee". Use a closure querying `HrEmployee` (or `Rule::exists(HrEmployee::class, 'id')->where('is_active', true)` after proving it uses the `hr` connection).
- different: «لا يمكن أن يتابع الشخص نفسه» / "A person cannot supervise themselves".
- unique pair: «هذه المتابعة مسجّلة مسبقًا» / "This supervision already exists".
- reverse pair: «لا يمكن أن يتابع شخصان بعضهما» / "Two people cannot supervise each other".
- already in HR: «هذه المتابعة موجودة في HR» / "This supervision already comes from HR".
- Import (if built): header row required, row cap (propose 500), every row valid; errors listed with line numbers «السطر :line: :error» / "Line :line: :error"; the whole batch is rejected on any error.

## Edge cases and concurrency
- Double submit: the unique index rejects the second insert; catch `UniqueConstraintViolationException` and show the duplicate message, never a 500.
- Two admins adding A→B and B→A at once: an application check alone cannot stop it. Propose stored generated columns `pair_low`/`pair_high` (`LEAST`/`GREATEST`) with a unique index (blocks duplicates and direct loops in the database), or a lock on the ordered pair; pick one in plan mode.
- Removing a row already removed: no-op with a message. Permission revoked mid-session: 403.
- Employee without a tracking user: no notification; tasks still counted by employee id on the card.
- Import with a bad row: nothing written. Pairs already present: unchanged and counted, not errors (confirm).
- Scanner cost: load the whole map and HR active flags once per scan; map user ids ↔ employee ids in one query; no per-item queries.

## Data integrity
- Unique index on the pair (plus the loop guard you choose); FK only on `created_by`; no FK to HR.
- Add, remove and import each run in one `DB::transaction` through Eloquent so `RecordsActivity` fires; import is all-or-nothing (propose one activity per row or a single summary event).
- `utf8mb4_unicode_ci` / InnoDB DYNAMIC (`DatabaseEncodingTest`); working `down()`.
- Optional seed in a demo seeder under `Activity::withoutRecording` with active HR employees only; add the model to `tests/Feature/DatabaseSeederTest.php` if seeded.

## Implementation guidance
- `php artisan make:migration create_supervisions_table --no-interaction`, `php artisan make:model Supervision --factory --no-interaction`, `php artisan make:class Support/SupervisionMap --no-interaction` (adjust namespace, `declare(strict_types=1);`).
- Flux modals bound with `wire:model`, never `:open`; `x-ui.*` components; logical classes (`ms-*`, `pe-*`, `text-start`); "inactive" uses a status token plus an icon, not colour alone; full `@php … @endphp` blocks only.
- Card: one `myTeam()` computed on the dashboard with a fixed number of queries (supervisee ids, HR names, tasks grouped by assignee, one per optional section); when the user supervises nobody, only the first query runs.
- Escalation sketch inside the manager-stage branch:
```php
$supervisorUserIds = $this->supervisorUsersFor($item, exclude: [...$managers, ...$item->directUserIds]);
if ($supervisorUserIds !== []) {
    $payload = $this->dispatcher->payload($item->module, NotificationPriority::Critical, 'notifications.alerts.escalation.supervisor', $params, $item->subjectType, $item->subjectId);
    $sent += $this->dispatcher->send('escalation', "supervisor:{$due}", $payload, $supervisorUserIds);
}
```

## Tests (Pest)
- `tests/Feature/Admin/SupervisionTest.php`: add (row, `created_by`, activity); self, duplicate, reverse, inactive and unknown employee refused with messages; remove; no `supervisions.manage` → tab hidden and add/remove 403 (fresh Livewire instance per forbidden call); General Manager succeeds; double add leaves one row; import success and all-or-nothing failure (if built).
- `tests/Feature/Notifications/SupervisorEscalationTest.php` (reuse the `OverdueScannerTest` helpers): supervisor of the assignee is alerted at the manager stage inside the window and not outside it; inactive supervisor gets nothing; a supervisor who is also the program manager gets one alert; a supervisor who cannot see a department-scoped task gets nothing; the ledger prevents repeats; non-working days send nothing.
- `tests/Feature/MyTeamCardTest.php`: hidden without supervisees; counts supervisees' overdue tasks; hides tasks outside `TaskVisibilityScope`; ignores inactive supervisees; constant query count with 1 and 10 supervisees.
- Helper tests for `SupervisionMap` (Task 17 and Task 21 rely on them): the HR direct manager of the current position (active contract) is returned without any local row; an ended contract's manager is not; a local exception adds a supervisor; a local row duplicating HR is refused; no write and no salary/contract column is touched on the `hr` connection.
- Keep green: `Admin/UserDirectoryTest.php`, `AccessControl/AdminSafetyTest.php`, `Notifications/OverdueScannerTest.php`, `Notifications/SubjectLinksTest.php`, `Models/MorphMapTest.php` (updated), `AccessControl/PolicyCoverageTest.php` (add a `SupervisionPolicy` or list the model in `modelsGuardedWithoutPolicy()` with module `supervisions`), `DashboardTest.php`, `DatabaseEncodingTest.php`, `LocalizationTest.php`.
- Run: `php artisan test --compact tests/Feature/Admin/SupervisionTest.php`, then the other files or `--filter=...`.

## Acceptance criteria
- [ ] Supervisors come from HR by default (direct manager of the current position); General Manager adds exceptions in **Users & Roles → Supervision**, which also shows the HR relations read-only; self, duplicate (including HR duplicates) and direct-loop pairs are refused server-side.
- [ ] Escalations reach active supervisors who can see the item, once per stage, with days from `NotificationSettings`.
- [ ] **My Team** shows each supervisor their people's outstanding work and nothing they could not open themselves.
- [ ] No FK or write to HR; no permission or visibility gained through supervision.
- [ ] All strings in Arabic and English; RTL correct; listed tests pass; Pint clean.

## Do not
- Give supervisors implicit permissions or bypass `TaskVisibilityScope`.
- Add a foreign key to an HR table, write to HR, or `whereHas()` across the `hr` connection.
- Hard-code escalation days; add a notification module without its icon/banners; put module logic in `RecipientResolver`.
- Accept a partial import, or trust the client picker list.
- Bind a Flux modal with `:open`, or use the `@php(...)` shorthand.

## Quality bar (this task)
- **Supervision** tab: HR relations shown read-only with a "from HR" label next to local exceptions; add/remove exceptions through a modal with employee pickers; CSV import shows a validation report before writing anything.
- **My Team** card: compact counts with links, hidden when the user supervises nobody.
- Everything in `quality-bar.md` applies. Before reporting, open every new or changed screen in the browser (Arabic RTL and English, light and dark, desktop and 375 px), run `vendor/bin/phpstan analyse` on the touched paths, and list both in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions (D-13, activity module, loop guard, import form, task creator in escalation, card sections available now); wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside Task 23's scope; note anything else as a follow-up.

## Report back
Your final message must contain: summary; files changed; migration added; tests run with pass/fail output; decisions assumed (D-13 HR source and how local exceptions combine, activity module, loop guard, import approach, escalation "named people"); whether the R9 cut (My Team card) was applied; follow-ups (card sections waiting for Tasks 17/19). Handoff facts: for Task 17 and Task 21 the exact `SupervisionMap` signatures, that they merge HR direct managers with local exceptions and return active HR employee ids, and that supervision adds no permission (the monthly-report policy must still require `reports.create/update`); for Task 19/21 how to add the donor-report and digest sections; for Task 26 the new lang keys, the tab and card names and any `data-tour` anchors; for Task 28 a UAT scenario (link, escalate, card).
````
