# Task 24 — Annual plan: draft, approval and activities

> **خارج نافذة أكتوبر 2026 — لا تُشغَّل ضمن هذه الخطة** (المراجعة 2، 2026-10-08: محفوظة في الخطة كمرجع لخطة لاحقة؛ أيام الجدول ونقاط التكامل أدناه من النسخة السابقة ولا تنطبق).
>
> **المهمة:** الخطة السنوية: مسودة واعتماد وأنشطة (مشروطة بالقرار D-18)
> **المطوّر:** Dev 1 · **الجهد:** يومان · **يعتمد على:** Task 12، Task 16
> **متى تستخدمه:** فقط إذا سجّل سجل القرارات أن D-18 يعتمد تنفيذ المهمة الآن (ومن يعتمد الخطة، وكيف تُعامل المسودات في **Executive Summary**)، وبعد اكتمال Task 12 (نقطة CP3: `ResultType::Activity` و`milestones.result_id`) وTask 16 (عرض السلسلة في صفحة **Annual Plan**)، وعادةً في اليومين 28–29 من الجدول.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
**Only proceed if decision D-18 approved this task; otherwise stop and report.** Open `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md`. If the file is missing, or D-18 is not recorded as "implement now", implement nothing and report that.

## Role and goal
You are implementing Task 24 of the HC Tracking proposal-lifecycle correction plan in this Laravel 13 / Livewire 4 / Flux UI 2 repository. The outcome: an annual plan has a status (`draft` → `approved`, with approver and date), and the program/branch manager lists the activities the plan will deliver — picked from the program's results framework (type `activity`) with planned months, like the Gantt template's "Planned / Implemented" rows — next to the targets MEAL enters. "Implemented" is derived from the milestones linked to each activity (Task 12), never typed. The **Annual Plan** page then answers "which indicators and activities must be achieved, and which milestones reached" in a draft or approved state. The 8 existing plans become `approved` so **Executive Summary** figures do not move. No other task depends on this one.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins) (Boost tools, `php artisan make:* --no-interaction`, Pest, Pint, no new dependencies or base folders).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`, section `### Task 24` — Arabic and authoritative; English names are exact. Also Task 12 (activity level, `milestones.result_id`, scope rules), Task 16 (chain view on the annual plan page) and section 9 D-18.
3. The decision log above; it overrides the plan's default for D-18 (who approves, drafts in **Executive Summary**: default "existing plans approved, drafts marked").
4. Rules: `.ai/rules/annual-plans.md` (four reads of `annual_plans`, `AnnualPlanMatrixTest`), `.ai/rules/executive-summary.md`, `.ai/rules/database-migrations.md`, `.ai/rules/migrations.md`, `.ai/rules/feature.md` (MariaDB `json` columns are `utf8mb4_bin` by design), `.ai/rules/models.md`, `.ai/rules/providers.md` (mirror defaults in `$attributes`), `.ai/rules/enums.md`, `.ai/rules/views-enums.md`, `.ai/rules/observers.md` (`PlanIndicatorSourceObserver`, `ProgramObserver`), `.ai/rules/results.md`, `.ai/rules/views-components-ui.md` (chain rules; annual-plan targets must not be stranded), `.ai/rules/resultsindicatorstargetsreports.md`, `.ai/rules/components-ui.md`, `.ai/rules/pages.md`, `.ai/rules/ui.md` (status chip in the detail-header meta slot), `.ai/rules/views.md`, `.ai/rules/views-pages.md`, `.ai/rules/factories.md`, `.ai/rules/seeders.md`, `.ai/rules/database-seeders.md`, `.ai/rules/tests.md`. Then `grep -rin 'annual\|activity\|plan_indicators' .ai/rules`.
5. Study first:
   - `app/Models/AnnualPlan.php` (`RecordsActivity`, `activityEvents()`, `delete()` in a transaction, `#[ObservedBy(PlanIndicatorSourceObserver::class)]`), `app/Policies/AnnualPlanPolicy.php` (`update` = `annual-plans.update` or plan manager or program manager; `reassign`), `database/factories/AnnualPlanFactory.php`, `database/seeders/DemoDeliverySeeder.php`.
   - `resources/views/pages/annual-plans/⚡index.blade.php` (the four reads: year options, manager options, the plan-cell read selecting `['id', 'slug', 'program_id', 'manager_id', 'year']`, the grouped year/delivery read), `resources/views/pages/annual-plans/⚡show.blade.php` (`plan()`, `targets()`, `resultGroups()`, `savePlan()`, the Task 16 chain view and how it selects the year's activities today).
   - `resources/views/pages/executive-summary/⚡index.blade.php` (coverage card `annual-plan-coverage`, `planFigures()`, `plansOf()`, `committedThisYear()`).
   - `resources/views/pages/targets/⚡index.blade.php` (`saveTarget()`, `deleteTarget()` — the writer of annual-plan targets), `resources/views/pages/results/⚡index.blade.php` (`canDelete()`, `deleteResult()`, program moves), `app/Models/Result.php`, `app/Enums/ResultType.php`, `app/Models/Milestone.php` (planned/actual dates, `status`, `result_id`), `app/Observers/ResultObserver.php`, `app/Observers/ProgramObserver.php`, `app/Concerns/RecordsParentActivity.php`, `app/Enums/Concerns/HasTranslatedLabel.php`, `app/Actions/Proposals/ChangeProposalStatus.php` (Action shape), `resources/views/pages/proposals/⚡show.blade.php` (`TABS` + `updatedTab()` pattern).
   - Tests: `tests/Feature/AnnualPlanMatrixTest.php`, `AnnualPlanDetailTest.php`, `AnnualPlanCrudTest.php`, `AnnualPlanTest.php`, `ExecutiveSummaryTest.php`, `ResultsFrameworkTest.php`, `TargetDirectoryTest.php`, `AccessControl/PlanningAuthorizationTest.php`, `AccessControl/PolicyCoverageTest.php`, `Notifications/PlanningActivityTest.php`, `DatabaseSeederTest.php`.
6. Skills: `laravel-best-practices`, `livewire-development`, `fluxui-development`, `database-migrations`, `laravel-security`, `testing-best-practices`, `tailwindcss-development`.
7. Use Boost `search-docs` before framework APIs (`['json casts', 'validating arrays', 'pessimistic locking', 'foreign key constraints']`) and `database-schema` on `annual_plans`, `results`, `milestones` before writing migrations.

## Preconditions to verify
- D-18 approves the task, names the approver rule and the **Executive Summary** treatment of drafts.
- `ResultType::Activity` and `milestones.result_id` exist — Task 12 (CP3). The annual plan page shows the chain view — Task 16.
- `annual_plans.status`, `annual_plan_activities`, `App\Enums\AnnualPlanStatus`, `App\Models\AnnualPlanActivity` and `app/Actions/AnnualPlans/` do not exist yet.
- Count local plans (`SELECT COUNT(*) FROM annual_plans`; the plan expects 8) and report it.
If a check fails, stop and report instead of improvising.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-18, and the «تعديلات المراجعة 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- D-18: the annual-plan cycle is decided at the **start of 2027**. Do not run this prompt in the October window.

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
- **D-44a:** from January 2027 every program manager must enter their annual plan; automatic minimal plans exist for 2026 only (Task 33). This task must therefore ship before January 2027 and belongs to the November plan.

## Scope
### In scope
- Migration `add_status_to_annual_plans_table`: `status` varchar(20) NOT NULL default `'draft'`, `approved_by` (FK `users`, nullable, `nullOnDelete`), `approved_at` timestamp nullable; backfill every existing row to `approved`. The plan names no "preparer" column but requires approver ≠ preparer: propose `prepared_by` (FK `users`, nullable, `nullOnDelete`, set by `SaveAnnualPlanActivities`).
- Migration `create_annual_plan_activities_table`: `id`, `annual_plan_id` (FK cascade), `result_id` (FK `restrictOnDelete`), `planned_months` json, `notes` text nullable, timestamps; propose a unique index (`annual_plan_id`, `result_id`).
- Enum `App\Enums\AnnualPlanStatus` (`Draft = 'draft'`, `Approved = 'approved'`, `HasTranslatedLabel`, `values()`); labels in `lang/{en,ar}/enums.php`.
- Model `App\Models\AnnualPlanActivity` + factory (`RecordsParentActivity` on the plan); `AnnualPlan::activities(): HasMany`, `status` cast, `$attributes = ['status' => 'draft']`, `@property` docblocks, `activityEvents()` gains `status`.
- `AnnualPlanPolicy::approve()`; reuse `update` for activities and return-to-draft (confirm).
- Actions in `app/Actions/AnnualPlans/`: `SaveAnnualPlanActivities`, `ApproveAnnualPlan`, and `ReturnAnnualPlanToDraft` (implements "editing an approved plan returns it to draft with a reason").
- `annual-plans/⚡show`: tabs **Targets** (existing content) and **Activities**, status chip, **Approve** button, **Return to Draft** modal with reason, activities grid (activity, 12 planned-month toggles, derived implemented months, notes).
- `annual-plans/⚡index`: a draft marker per cell, read inside the existing plan-cell query.
- `executive-summary/⚡index`: drafts marked per D-18 (or excluded consistently if D-18 says so).
- Guards: annual-plan target writes in `targets/⚡index` refused while the plan is approved; `results/⚡index` refuses deleting an activity linked to a plan, and refuses moves that strand a plan activity.
- Lang: `lang/{en,ar}/annual_plans.php`, `enums.php`, `executive_summary.php`, `results_framework.php`, `targets.php`, `notifications.php` (events `annual_plan.approved`, `annual_plan.returned_to_draft`, `annual_plan.activities_updated`).
### Out of scope
Editing the framework or linking milestones (Task 12); the chain component (Task 16); target entry UI (existing **Targets** page / Task 13); monthly reports (Task 17); budget lines (Task 25).

## Business rules
- New plans start `draft`; the migration marks every existing plan `approved` (approver NULL, shown "Approved (before workflow)" — confirm `approved_at` NULL vs migration time).
- Draft: targets and activities are editable by their permission holders. Approved: read-only; a change first needs **Return to Draft** with a reason (≥ 10 chars) recorded in the **Activity Log** with the actor. Raise the alternative (edit prompts for a reason and reverts automatically) in plan mode.
- Activities come only from the plan's program framework: `results.type = 'activity'`, `results.program_id = annual_plans.program_id`, `results.proposal_id IS NULL`.
- `planned_months`: distinct integers 1–12, stored sorted, at least one.
- Implemented months are derived: a month of the plan year is implemented when a non-cancelled milestone linked to the activity (`milestones.result_id`), in a project of the plan's program, has an actual period (`actual_start_date` → `actual_end_date` or today) overlapping it. Confirm the definition in plan mode; read it in one query for the whole tab.
- **Approve**: only from `draft`; the approver is not the activities' preparer (D-18); sets `approved_by` and `approved_at`. A plan without activities or targets can be approved with a warning (confirm).
- **Executive Summary** (D-18 default): figures include all plans as today; draft plans are marked (e.g. "2 drafts" on the coverage card). Existing plans being `approved` keeps every number unchanged.

## Security and authorization
- Activities: `Gate::authorize('update', $plan)` inside `SaveAnnualPlanActivities` (`annual-plans.update`, plan manager or program manager), plus status `draft`.
- Targets: unchanged `targets.*` (MEAL), plus the draft guard in the server path that writes annual-plan targets.
- Approve: `AnnualPlanPolicy::approve()` = `annual-plans.update` and not the preparer; `Gate::authorize('approve', $plan)` inside `ApproveAnnualPlan`.
- Return to draft: `Gate::authorize('update', $plan)`.
- Object level: activities load through `$plan->activities()->whereKey($ids)`; result ids load through the program-framework query above with `whereKey($ids)`. Any foreign id rejects the whole batch. `status`, `approved_by`, `approved_at`, `prepared_by` are never read from the request.
- Repeat each check at the top of the Livewire method so `AuthorizationCoverageTest` sees it; data helpers stay `protected` (`BrowserCallableMethodsTest`). No money is involved.

## Validation
Messages in `lang/ar/annual_plans.php` and `lang/en/annual_plans.php`:
- `activities.*.result_id`: required, integer, an activity of this plan's program — «اختر نشاطًا من إطار برنامج هذه الخطة» / "Choose an activity from this plan's program framework"; duplicates in the batch — «النشاط مضاف إلى الخطة مسبقًا» / "This activity is already in the plan".
- `activities.*.planned_months`: required, array, min:1; each integer between 1 and 12, distinct — «اختر شهرًا واحدًا على الأقل بين 1 و12 دون تكرار» / "Pick at least one month between 1 and 12, without repeats".
- `activities.*.notes`: nullable, string, max:2000.
- `returnReason`: required, string, min:10, max:1000 — «اكتب سببًا لا يقل عن 10 أحرف» / "Give a reason of at least 10 characters".
- Server invariants (`ValidationException::withMessages()` from the Actions): approved plan edited — «الخطة معتمدة. أعدها مسودة بسبب قبل التعديل.»; approve a non-draft — «الخطة معتمدة مسبقًا»; approver is the preparer — «لا يمكن أن يعتمد الخطة من أعدّ أنشطتها»; stale set — «عُدّلت أنشطة الخطة من مستخدم آخر، أعد التحميل.» — each with an English equivalent.

## Edge cases and concurrency
- Double **Approve**: lock the plan (`lockForUpdate`) and expect `draft`; the second call gets the message and writes nothing.
- Approve while activities are being saved: both Actions lock the plan row first, then its activities (fixed lock order).
- Stale activity set: the form sends the ids it loaded; reject the batch if the locked set differs; disable buttons with `wire:loading`.
- Deleting a framework activity linked to a plan: refused with a message in `canDelete()` (the FK `restrictOnDelete` is the backstop); changing its type or moving it to another program while linked: refused (propose where: page guard or `ResultObserver`).
- Reassigning a plan to another program (`savePlan()`, `reassign`) while it has activities: refuse with a message (propose).
- Deleting a plan cascades its activities; `PlanIndicatorSourceObserver` still deletes its targets. Deleting a program: verify `ProgramObserver` deletes plans (and their activities) before results, so the restrict FK never fires.
- Plans without activities keep Task 16's fallback view; plans with activities show their own list.

## Data integrity
- Migration 1 order: count rows → add columns → backfill `status = 'approved'` for all existing rows (query builder, one statement) → verify no `draft` among pre-existing ids → add FKs. MySQL does not roll back DDL: keep `up()` re-runnable or fail before DDL. `down()` drops FKs then columns.
- Migration 2: new table, `down()` drops it. `planned_months` json passes `DatabaseEncodingTest` (MariaDB json = `utf8mb4_bin` with a `json_valid` check, by design).
- Every write in one `DB::transaction` with the lock; saving activities = delete removed, update kept, insert new, record one activity.
- The four reads of `annual_plans` on the matrix stay four: add `status` to the existing plan-cell select; never a fifth query. **Executive Summary** draft counts ride existing queries (add `status` to `plansOf()`, conditional aggregate for the card).

## Implementation guidance
- `declare(strict_types=1);`, `#[Fillable]` (`['result_id', 'planned_months', 'notes']` on the activity), `casts()` (`'planned_months' => 'array'`, `status` → `AnnualPlanStatus::class`, `approved_at` → `datetime`), CarbonImmutable docblocks, array-shape PHPDoc.
- `php artisan make:migration … --no-interaction`, `php artisan make:model AnnualPlanActivity --factory --no-interaction`, `php artisan make:enum AnnualPlanStatus --no-interaction` (if available; else `make:class`). Factories omit default-bearing columns; add an `approved()` state on `AnnualPlanFactory` as a per-attribute closure.
- Tabs follow `proposals/⚡show` (`TABS` constant + `updatedTab()` guard). Status chip in the `x-ui.detail-header` meta slot with text and icon, not colour alone. Flux modals bound with `wire:model`, never `:open`. Month toggles have accessible labels (localized month names); grid keyboard-navigable; logical classes only.
```php
final class ApproveAnnualPlan
{
    public function handle(AnnualPlan $plan): void
    {
        Gate::authorize('approve', $plan);

        DB::transaction(function () use ($plan): void {
            $locked = AnnualPlan::query()->lockForUpdate()->findOrFail($plan->id);
            // expect draft; approver ≠ prepared_by; set status, approved_by, approved_at; record activity
        });
    }
}
```

## Tests (Pest)
- `tests/Feature/AnnualPlanApprovalTest.php`: approve a draft (status, approver, activity); second approve refused with no write; preparer cannot approve; user without `annual-plans.update` gets 403 (fresh Livewire instance per forbidden call); return to draft needs a reason and records it; approved plan rejects activity and target edits until returned to draft; migration backfill marks pre-existing plans `approved` and new plans start `draft`.
- `tests/Feature/AnnualPlanActivitiesTest.php`: save activities with months (rows, sorted months, one activity log entry); activity from another program, a proposal framework or a non-activity result rejected with the batch unchanged; invalid months rejected; stale set rejected; implemented months derived from linked milestones' actual dates (cancelled ignored); plan manager and program manager may save, a roleless user gets 403.
- Extend `AnnualPlanMatrixTest.php`: draft marker shown; both "four reads" assertions unchanged. Extend `ExecutiveSummaryTest.php`: numbers unchanged for migrated plans; drafts marked (or excluded per D-18). Extend `ResultsFrameworkTest.php`: linked activity cannot be deleted or moved. Extend `TargetDirectoryTest.php`: target edit on an approved plan refused.
- Keep green: `AnnualPlanDetailTest.php`, `AnnualPlanCrudTest.php`, `AnnualPlanTest.php`, `AccessControl/PlanningAuthorizationTest.php`, `AccessControl/PolicyCoverageTest.php` (list `AnnualPlanActivity` in `modelsGuardedWithoutPolicy()` under `annual-plans`), `AccessControl/AuthorizationCoverageTest.php`, `AccessControl/BrowserCallableMethodsTest.php`, `Notifications/PlanningActivityTest.php`, `DatabaseEncodingTest.php`, `DatabaseSeederTest.php`, `LocalizationTest.php`, a new `tests/Feature/Models/AnnualPlanActivityTest.php` (relation, casts).
- Run: `php artisan test --compact tests/Feature/AnnualPlanApprovalTest.php`, then each file above or `--filter=...`.

## Acceptance criteria
- [ ] The **Annual Plan** page shows targets and activities with planned and derived implemented months, in a `draft` or `approved` state.
- [ ] Approval is server-enforced: right permission, not the preparer, once only; an approved plan changes only after **Return to Draft** with a reason.
- [ ] Existing plans are `approved`; **Executive Summary** numbers are unchanged and drafts are marked per D-18.
- [ ] The matrix still reads `annual_plans` exactly four times.
- [ ] All strings in Arabic and English; RTL correct; listed tests pass; Pint clean.

## Do not
- Add a fifth `annual_plans` query to the matrix or query inside a cell.
- Let users type "implemented" months, or accept activities from another program, a proposal framework or a non-activity result.
- Read `status`, approver or preparer from the request; skip the "approver ≠ preparer" check.
- Change **Executive Summary** numbers for migrated plans, or count drafts differently in different cards.
- Bind a Flux modal with `:open`, use the `@php(...)` shorthand, or physical `ml-/mr-/left-/right-` classes.

## Quality bar (this task)
- (Out of the October window.) When run: draft/approved state visible as a text badge on the plan header, the activities matrix keyboard-navigable, approval through a confirm modal.
- Everything in `quality-bar.md` applies. Before reporting, open every new or changed screen in the browser (Arabic RTL and English, light and dark, desktop and 375 px), run `vendor/bin/phpstan analyse` on the touched paths, and list both in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions (D-18 details, `prepared_by`, unique activity per plan, return-to-draft flow, implemented-month definition, migrated `approved_at`, reassign guard); wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside Task 24's scope; note anything else as a follow-up.

## Report back
Your final message must contain: summary; files changed; migrations added (names, the local backfill count); tests run with pass/fail output; decisions assumed (D-18 approver and drafts rule, `prepared_by`, return-to-draft flow, implemented-month definition); follow-ups. Handoff: no task depends on Task 24. For Task 26 list the new lang keys, tabs, buttons and `data-tour` anchors; for Task 27 the expected backfill (every pre-existing plan → `approved`) to verify on the production copy; for Task 28 a UAT scenario (draft → activities → approve → return to draft).
````
