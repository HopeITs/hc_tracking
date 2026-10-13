# Task 33 — Monthly Report Template and the activation gate

> **المهمة:** نموذج التقرير الشهري (Monthly Report Template) وبوابة التفعيل: لا يصبح المشروع `active` إلا بعد أن يؤكد MEAL نموذج تقريره الشهري (المراجعة 4، مع تعديلات المراجعات 5 و7 و8 و9)
> **المطوّر:** B4 (backend، 3 أيام) + B3 (الواجهة، 2.5 يوم) · مراجعة B1 للـ backend وDev 2 للواجهة · **الفريق:** B · **الأيام:** 8–10 (الأربعاء 2026-10-21 ← الجمعة 2026-10-23؛ B4: اليوم 8 المخطط والبوابة و`isDueIn()`، اليوم 9 الإجراءات والتعبئة المسبقة والدمج، اليوم 10 الاختبارات و`report-templates:seed-existing`؛ B3: اليوم 9 تبويب النموذج، اليوم 10 الطابور وإعدادات الأسئلة والملفات) · **الجهد:** 5.5 أيام في الخطة، وتضيف المراجعتان 8 و9 يومًا ونصفًا يُغطّى من R9 · **يعتمد على:** Task 11 (المكتبة والتواتر)، Task 13 (مستهدفات المقترح)، Task 08 (عقد الأحداث في CP2)، Task 05 (حالة `approved` النظامية)، Task 02 (الصلاحيات)، Task 07 (خانة MEAL Manager)، Task 15 (مسؤول MEAL للمشروع)
> **متى تستخدمه:** البرومبت A (B4) في اليوم 8 بعد CP2 (نهاية اليوم 6: أصناف الأحداث، ومنها `ProjectFunded`، وحالة `approved` للمشروع، ومخطط Task 11 وTask 12) ودمج Task 13. يُجمَّد عقده (الجداول، و`TemplateItem::isDueIn()`، والإجراءات، والحدث `ProjectActivated` بـ `via: template_confirmation`) في **CP3 (نهاية اليوم 9، الخميس 2026-10-22)** لأن Task 17 تبني أسطرها عليه. البرومبت B (B3) من اليوم 9 على عقد A.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

يحتوي هذا الملف على برومبتين: **A** للـ backend (B4) أولًا، ثم **B** للواجهة (B3) في جلسة منفصلة على عقد A. أعطِ B3 قسم **Report back** من رد Claude في جلسة A.

> هذا البرومبت كُتب بعد المراجعة 9، فنصّه يجمع المراجعات 4 و5 و7 و8 و9 في متن واحد، ولا توجد فيه كتل «Revision N» منفصلة. عند أي تعارض مع الخطة، الخطة هي المرجع (القسم `### Task 33` وأقسام «تعديلات المراجعة» فيه).

## Prompt A — backend (B4)

````markdown
## Role and goal
You are implementing Task 33, Prompt A (backend), of the HC Tracking proposal-lifecycle correction plan in this Laravel 13 / PHP 8.5 / Livewire 4 / Flux UI 2 repository. The outcome: **a project becomes `active` only after MEAL confirms its Monthly Report Template.** When a donor approves a proposal (Task 08), each funded project becomes `approved` and gets a draft template whose `pending` items are suggested from that proposal's targets; MEAL picks the indicators the project manager must report monthly — **only from the Indicator Library** (proposal indicators or institutional ones) — and sets for each item the approved target, the months it appears, who enters it, and the mandatory evidence types. Confirming the template activates the project and dispatches `ProjectActivated`, which Task 17 listens to in order to generate the monthly reports. A later proposal on an already active project adds `pending` items that, once confirmed, appear only in that proposal's months. This task also owns the MEAL-managed lists the monthly report needs: evidence types and the narrative question bank (copied per project). Your contract is frozen at **CP3 (end of day 9)**: Task 17 builds its lines on `TemplateItem::isDueIn()` and your tables, and Tasks 18, 19, 21 and 27 read them.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this PR is reviewed against).
2. The plan `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md` — Arabic and authoritative; English table, column, enum, permission and UI names are exact. Read `### Task 33` in full with its «تعديلات المراجعة 5، 7، 8، 9» subsections; the header paragraphs «المراجعة 4» to «المراجعة 9»; section 1.0 facts 13–20 and section 1.2; section 1.1 steps 7–7أ and 1.3 (project status line); the «تعديلات المراجعة 4» subsections of `### Task 05`, `### Task 08`, `### Task 13`, `### Task 17`, `### Task 18`, `### Task 21` and `### Task 27`; section 7 (CP2, CP3); section 9 rows D-25, D-26, D-37, D-38…D-46, D-48, D-49, D-50, D-12a, D-20a.
3. The decision log `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` (sections 5 and 10: permission matrix and conflicts T-01, T-02, T-03 — the plan's Task 33 security text still names Super Admin and "the city's MEAL officer"; Revisions 8 and 9 replaced both).
4. Rule files: `.ai/rules/database-migrations.md`, `migrations.md`, `feature.md`, `models.md`, `providers.md` (strict mode, morph map), `enums.md`, `views-enums.md`, `factories.md`, `seeders.md`, `database-seeders.md`, `console-commands.md`, `resultsindicatorstargetsreports.md` (one achievement definition), `annual-plans.md`, `observers.md`, `recipients.md`, `views-components-notifications.md`, `alerts.md`, `tests.md`. Then run `grep -rin 'target\|plan_indicator\|annual\|template\|evidence\|seed' .ai/rules`.
5. Code to study first: `app/Models/PlanIndicator.php` (`achievedValue()`, `achievedValueSql()`, `chainIssuesFor()`, the `AnnualPlan` source branch), `app/Models/AnnualPlan.php` (`#[Fillable(['program_id','manager_id','year'])]`, `planIndicators()`), `app/Models/Program.php`, `app/Models/Project.php`, `app/Enums/ProjectStatus.php` (Task 05 made `approved` live again), the Task 11 `LibraryIndicator` model and enums (`CollectionFrequency`, `IndicatorCollector`, `DenominatorBasis`, `Disaggregation`), the event classes `ProjectFunded`, `ProposalStatusChanged`, `ProjectActivated` (Task 04, CP2), `app/Actions/Projects/FundProjectFromApprovedProposal.php` (Task 08), Task 07's MEAL Manager slot resolver, `app/Support/MealCoverage.php` (Task 15), `app/Notifications/Alerts/{DeadlineCatalog,NotificationSettings,WorkingCalendar}.php`, `app/Notifications/EventCatalog.php` (Task 21), `app/Console/Commands/SyncPermissions.php` (command style: `--dry-run`, `Activity::withoutRecording`, `DB::transaction`).
6. Skills: `laravel-best-practices`, `database-migrations`, `laravel-security`, `testing-best-practices`. Use Boost `database-schema` on `plan_indicators`, `annual_plans`, `programs`, `projects`, `project_proposal`, `library_indicators`, `results`, `activity_schedules` before writing migrations, and `search-docs` for partial unique indexes on MySQL/MariaDB (generated columns), `lockForUpdate`, queued listeners with `afterCommit`.

## Preconditions to verify
- `ProjectStatus::Approved` is live (not deprecated) and only Task 08 writes `draft → approved` (Task 05, Task 08).
- Event classes `ProjectFunded(projectId, proposalId, startDate, endDate)`, `ProposalStatusChanged(proposalId, from, to, actorId)` and `ProjectActivated(projectId, proposalId, startDate, endDate, via)` exist in `main` (CP2). Do not create or change them; if one is missing, stop and report.
- `library_indicators` has `collected_by`, `frequency`, `follow_up_months`, `measure`, `denominator_basis`, `is_disaggregated` (Task 11); `indicators.library_indicator_id` exists; per-center targets are `plan_indicators` with `source_type = 'proposal'` (Task 13); `project_proposal.target_beneficiaries` exists (Task 03).
- Permissions `report-templates.view/manage/confirm`, `evidence-types.manage`, `narrative-questions.manage` exist (Task 02), with T-02 decided.
- Task 07's slot resolver (`holderEmployeeId(ReviewerSlot::MealManager)`) and Task 15's `MealCoverage::responsibleFor()` exist; if Task 07 is still in review, code against its agreed signature and list it.
If a required check fails, stop and report instead of improvising.

## Scope
### In scope
- **Tables** (all via `php artisan make:migration … --no-interaction`, explicit index/FK names, working `down()`):
  - `monthly_report_templates`: `id`, `project_id` (unique, FK `restrictOnDelete`), `confirmed_at`, `confirmed_by` (FK users, `nullOnDelete`), `last_changed_at`, timestamps. One template per project; it evolves, it is never copied.
  - `monthly_report_template_items`: `id`, `template_id`, `library_indicator_id` (FK **required** — the guarantee of "library only"), `origin` (`TemplateItemOrigin`: `proposal`, `institutional`), `measure` (copied from the library when added), `is_disaggregated` (copied), `entered_by` (`IndicatorCollector`: `project_team`, `meal`; default from the library's `collected_by`), `donor_attribution` (`DonorAttribution`: `shared`, `split`; copied from the project's program), `denominator_basis` (`DenominatorBasis`; copied from the library for percentages), `fixed_denominator` (unsigned int, nullable), `target` (decimal nullable), `target_basis` (short text, e.g. «80% من 250 مستفيدًا»), `schedule` (`TemplateItemSchedule`: `library`, `monthly`, `selected-months`, `after-months`), `schedule_months` (JSON), `starts_on`, `ends_on` (first and last month the item appears), `status` (`TemplateItemStatus`: `pending`, `confirmed`, `retired`), `confirmed_at`, `confirmed_by`, `retired_at`, `sort`, timestamps. A unique (`template_id`, `library_indicator_id`) for non-retired items (MySQL has no partial index: use a generated column that is NULL when retired, or enforce it in a locked transaction plus a test — propose in plan mode).
  - `monthly_report_template_item_targets` (pivot): `item_id`, `plan_indicator_id` (**unique**). One item ← one or more donor targets.
  - `monthly_report_item_evidence_types` (pivot): `item_id`, `evidence_type_id` — the mandatory types for that item.
  - `evidence_types`: `id`, `name_ar` (unique), `name_en`, `is_active`, `sort`, timestamps.
  - `narrative_questions` (the organisation's bank): `id`, `text_ar`, `text_en`, `cadence` (`NarrativeCadence`: `monthly`, `quarterly`), `is_required`, `is_active`, `sort`, timestamps.
  - `monthly_report_template_questions` (the project's copy, D-43a): `id`, `template_id`, `narrative_question_id` (nullable: NULL = a project-specific question), `text_ar`, `text_en`, `cadence`, `is_required`, `is_active`, `sort`, timestamps.
  - `activity_evidence_types` (D-49): `template_id`, `result_id` (activity), `evidence_type_id` — mandatory files when an activity is reported done (Task 17).
  - `programs.donor_attribution` (default `shared`, mirrored in `Program::$attributes`) and `programs.donor_attribution_confirmed_at` (nullable) (D-46).
- **Enums** (TitleCase cases, lower-case values, labels in `lang/{en,ar}/enums.php`): `TemplateItemOrigin`, `TemplateItemSchedule`, `TemplateItemStatus`, `NarrativeCadence`, `DonorAttribution`.
- **Models, factories, policies:** `MonthlyReportTemplate`, `MonthlyReportTemplateItem` (alias `TemplateItem` in prose), `EvidenceType`, `NarrativeQuestion`, `MonthlyReportTemplateQuestion`; `MonthlyReportTemplatePolicy`, `EvidenceTypePolicy`, `NarrativeQuestionPolicy`; `TracksActivity` on templates, items, evidence types and questions; morph keys; relations `Project::reportTemplate()`, `PlanIndicator` ↔ items.
- **The single due rule:** `MonthlyReportTemplateItem::isDueIn(CarbonImmutable $month): bool` — the only definition of "this item appears in month M" (see Business rules); Task 17's lines and Task 18's "—" cells call it. Record it with `record-rule`.
- **Actions** in `app/Actions/ReportTemplates/`: `DraftTemplateFromProposal` (create or complete the template with `pending` items for one project and one approved proposal; merge duplicates), `AddInstitutionalItem`, `UpdateTemplateItem`, `RetireTemplateItem`, `ConfirmMonthlyReportTemplate` (confirms every pending item at once), `ActivateProjectFromConfirmedTemplate` (system step called only by the confirm action), `SaveEvidenceType` / `DeactivateEvidenceType`, `SaveNarrativeQuestion` / `DeactivateNarrativeQuestion`, `ApplyNewQuestionsToTemplates` (**Apply to all templates**, new bank questions only), `SetProgramDonorAttribution`.
- **Listeners** (queued, after commit, idempotent): on `ProjectFunded` → `DraftTemplateFromProposal` for that project and proposal, then notify MEAL «أكّد نموذج التقرير» (`project.funded`, `report_template.drafted`); on `ProposalStatusChanged` with `to = approved` → for linked projects that are already `active` (or already `approved` with a draft template), add `pending` items (`report_template.items_pending`). Both paths reach the same action, so a project funded through either event ends with one template and no duplicate items. Explain the reconciliation in your report: the plan's Task 08 text names `ProjectFunded`, its Task 33 text names `ProposalStatusChanged(to: approved)`; both are needed (new funding vs. a new proposal on an active project).
- **Commands:** `report-templates:seed-existing {--dry-run}` (D-45: one template per open dated project with `pending` items from its library-linked targets; the real run refuses while a program that has an active multi-donor project has no `donor_attribution_confirmed_at`, and names those programs) and a production-safe, idempotent way to create the reference lists — the evidence types (the five originals: أوراق تفقد، موافقات تصوير، أسماء مستفيدين، قوائم توزيع، سجل تسليم/تركيب; plus the Syria Cross list: Attendance Sheets, Participant Database, Digital Survey Export, Baseline / Endline Study, Pre/Post-Test Results, Training Report, Activity Report, Evaluation Report, Final Report, Meeting Minutes, Official Correspondence, Photos & Media, Certificate Registry, External Audit Report, Other (specify in comments)) and the default questions (monthly: الملخص، أبرز الإنجازات، التحديات، الخطوات القادمة; quarterly: «ما الذي تغيّر في المجتمع المستهدف هذا الربع؟»), both in Arabic and English. **Never rely on `db:seed` on production** (Task 27/29 forbid it): use a data migration or a command, keyed by `name_en` / question text, and say which in plan mode. Local seeders may call the same code.
- **Deadline source and settings:** `report_template_due_working_days` in `NotificationSettings` (default 5) and a `DeadlineCatalog` source for `approved` projects without a confirmed template (reminder to the project's MEAL officer, escalation to the MEAL Manager and management, Task 21).
- **Events** registered in Task 21's `EventCatalog`: `project.funded`, `report_template.drafted`, `report_template.items_pending`, `report_template.confirmed`, `report_template.item_changed`, `report_template.item_retired`, `annual_plan.missing` (from 2027).
- Lang groups `lang/{en,ar}/report_templates.php`, `evidence_types.php`, `narrative_questions.php`, `enums.php`, `notifications.php` (`events.report_template.*`).
### Out of scope
The pages (Prompt B); monthly report generation, lines, mirrors, answers and files (Task 17); the Monthly Matrix (Task 18); the donor aggregation (Task 19); the notification matrix itself (Task 21 — you register keys and fire events); annual-plan drafting/approval (Task 24, out of window); any change to `proposals`, `projects` columns or the proposal state machine (ask Dev 1).

## Business rules
- **Activation gate (D-39):** an `approved` project (donor approval + owner acknowledgement, Task 08) becomes `active` only through `ConfirmMonthlyReportTemplate`, and only with at least one `confirmed` item. Confirmation runs `ActivateProjectFromConfirmedTemplate`, which dispatches `ProjectActivated` after commit with the CP1 payload and `via: template_confirmation`. Nothing else in the codebase writes `approved → active`.
- **Agreement or evidence (D-26):** confirmation accepts a project without an agreement only when its proposal carries donor-approval evidence (file or note, Task 08); otherwise refuse — «سجّل الاتفاقية أو أرفق موافقة المانح في المقترح». After activation without an agreement, the **Project** page keeps the «لا اتفاقية مسجلة بعد» warning (Task 08).
- **Pre-fill:** for each `plan_indicator` of the project in the approved proposal whose indicator has a library entry, add or extend a `pending` item. Suggestions only: MEAL may remove them or add others before confirming. A target whose indicator has no library entry (legacy) is listed with «اربطه بالمكتبة أولًا» and never becomes an item.
- **Library only:** no item without `library_indicator_id`; the library entry must be active when the item is added.
- **One item per indicator (D-40 + D-46):** when a non-retired item already exists for the same library entry, link the new donor target to it (`monthly_report_template_item_targets`) instead of creating a second item. Suggested target: `shared` items → the highest linked donor target (or a figure covering both); `split` items → the **sum** of linked donor targets. A lower approved target than that is allowed only after an explicit confirmation (warning, not a block).
- **Target:** set by MEAL at confirmation; required for every `confirmed` item except tracking-only indicators («-» in D-37, `indicators.is_tracking_only`). Percentage ≤ 100; count and average ≥ 0. The item's target is the monthly report's goal and **never overwrites `plan_indicators.target`** (each donor's commitment stays as entered in Task 13).
- **Fixed denominator (D-25):** for a percentage item with `denominator_basis = fixed`, `fixed_denominator` is pre-filled from `project_proposal.target_beneficiaries` of the linked proposals — `shared`: the highest; `split`: the sum (each donor's share uses its own proposal's count in Task 17). MEAL may edit it with a note. Confirmation refuses a `fixed` item without a denominator — «حدد عدد مستفيدي المشروع لهذا المؤشر». A later change applies to draft reports only and notifies the project manager.
- **Donor mode (D-46):** the item copies its program's `donor_attribution` when created; a later program change does not alter existing items. MEAL may change an item's mode only while no line of it is in a `submitted` or `validated` report — «لهذا المؤشر قيم مُرسلة؛ أوقفه وأضف بندًا جديدًا». Institutional items have no donors and behave as `shared`. `SetProgramDonorAttribution` (program page, MEAL and General Manager with `programs.update`) stamps `donor_attribution_confirmed_at` on the first explicit save and is logged.
- **Who enters (D-48, D-48a):** `entered_by` defaults from the library's `collected_by`; MEAL may change it while no line is submitted. `meal` items are entered by MEAL during review, before confirmation of the report (Task 17).
- **Institutional item (D-44, D-44a):** added from the library without a proposal; needs a yearly target per center stored as a `plan_indicators` row whose source is the program's annual plan for that year (`source_type = 'annual_plan'`, `project_id` = the center), so the single achievement definition still works. **2026 only:** if no annual plan exists for the program and year, create a minimal one (program, year, manager = program manager). **From 2027:** refuse — «لا توجد خطة سنوية لبرنامج {X} لعام {Y}، وعلى مدير البرنامج إدخالها» — and fire `annual_plan.missing` to the program manager and MEAL. The institutional item is valid from confirmation to the project's end; its target renews yearly (January reminder to MEAL and the program manager).
- **Item window:** a proposal item runs from the first to the last month of its proposal's actual period; an item linked to several proposals runs from the earliest start to the latest end. **No retroactivity (D-41):** an item never appears before its proposal starts and is never added to a `submitted` or `validated` report; if confirmation comes after the proposal started, the item appears only in that proposal's months whose reports are still drafts, and the confirmation screen says «{n} أشهر من مدة المقترح مُرسلة ولن يظهر فيها هذا المؤشر».
- **Schedule (D-38):** default `library`: `monthly` and `per-event` → every month; `quarterly` → the last month of each quarter counted from the item start; `semi-annual` / `annual` → the last month of each period; `follow-up` → month (funding start + `follow_up_months`). MEAL may override with `monthly`, `selected-months` (chosen months of the project, unique, inside the window) or `after-months` (once, N ≥ 1 months after the project start). `per-season`, `baseline-endline` and `one-time` indicators cannot use `library`: MEAL must pick `selected-months` or `after-months`.
- **Due rule:** item due in month M ⇔ item `confirmed` ∧ `starts_on ≤ M ≤ ends_on` ∧ the schedule matches M. Task 17 adds "∧ the report of M is a draft" when it creates lines.
- **New proposal on an active project:** the project stays `active` and its reports do not change; new or updated items stay `pending` (invisible in reports) until confirmed; on confirmation the project manager is notified «مؤشرات جديدة للمقترح {X} ({المانح}): تُعبّأ من {شهر} إلى {شهر}» (no amounts).
- **Changes after confirmation:** target, schedule or evidence changes apply to draft reports only; **Retire** stops the item from the next month and keeps its values; every change is logged and notifies the project manager. After a proposal's `ends_on` its items stop automatically unless still linked to another running proposal.
- **Narrative questions (D-43, D-43a):** the bank is the organisation's template; when a template is created, the active bank questions are copied into `monthly_report_template_questions`; MEAL edits or adds per project (`report-templates.manage`). Bank edits never change existing templates; **Apply to all templates** copies only new bank questions. Questions and evidence types in use are deactivated, never deleted.

## Security and authorization
- Permissions (Task 02, decision-log section 5): `report-templates.view` — MEAL and General Manager, plus the project owner and its program manager reading their own project (per the T-02 decision); `report-templates.manage` — MEAL and General Manager; `report-templates.confirm` — the project's MEAL officer (`MealCoverage::responsibleFor($project)`, D-12a), the MEAL Manager slot holder and General Manager; `evidence-types.manage` and `narrative-questions.manage` — the MEAL Manager slot holder only (D-20a, T-01). Super Admin is never a business default.
- The project manager never edits or confirms the template; read-only for them.
- Every Action calls `Gate::authorize(...)`. Items load only through `$template->items()->whereKey($id)`; a linked `plan_indicator_id` must belong to the same project (`plan_indicators.project_id = template.project_id`) and a library entry must be active — otherwise reject the whole request (BOLA).
- `ActivateProjectFromConfirmedTemplate` is a system step callable only from `ConfirmMonthlyReportTemplate`, recorded with the confirmer as actor. The project form never accepts `active` (Task 05).
- Notifications carry no amounts. `TracksActivity` on every new model.

## Validation
Messages in `lang/{en,ar}/report_templates.php` (and the evidence/question groups):
- `items.*.target`: required at confirmation (except tracking-only), numeric, min:0, max:100 for percentages — «حدّد المستهدف المعتمد للتقرير الشهري».
- `items.*.schedule`: required, enum; `schedule_months`: required for `selected-months` (integers inside the project's months, no duplicates) or `after-months` (integer ≥ 1) — «اختر الأشهر التي يُسلَّم فيها المؤشر».
- `items.*.evidence_type_ids.*`: exists among active evidence types.
- `items.*.fixed_denominator`: required for `fixed` percentage items at confirmation, integer ≥ 1.
- Confirmation needs at least one confirmed item — «أضف مؤشرًا واحدًا على الأقل إلى نموذج التقرير».
- `evidence_types.name_ar`: required, max:120, unique; `narrative_questions.text_ar`: required, max:500.

## Edge cases and concurrency
- Two confirmations at once: `lockForUpdate` on the template; the second finds no pending items and answers «النموذج مؤكَّد بالفعل»; exactly one `ProjectActivated`.
- A second proposal reaches an `approved` project before the first confirmation: its items join the same draft and everything is confirmed together.
- A donor target deleted after linking: unlink only; the item stays if it has another target or is institutional, otherwise it is flagged «بلا مصدر» for MEAL.
- A library entry deactivated: existing items continue; no new item from it.
- A closed project: the template is read-only.
- Listener retried or both events delivered: no duplicate template, item or pivot row (unique keys + locked upsert).

## Data integrity
- `ConfirmMonthlyReportTemplate` is one transaction: lock the template, confirm pending items, activate the project when it is `approved`, update `last_changed_at`; events and notifications after commit.
- Unique (`template_id`, `library_indicator_id`) for non-retired items and unique `plan_indicator_id` in the pivot prevent duplicates and double links.
- An item with values in reports is never deleted — retire only. Questions keep their text on answers (Task 17 copies it at answer time).
- utf8mb4_unicode_ci / InnoDB DYNAMIC; `DatabaseEncodingTest` green.

## Implementation guidance
```
Project:   draft ──approve+ack (Task 08)──► approved ──ConfirmMonthlyReportTemplate──► active
Item:      pending ──confirm──► confirmed ──retire──► retired
Line due in month M  ⇔  item.confirmed ∧ starts_on ≤ M ≤ ends_on ∧ isDueIn(M) ∧ report(M).status = draft   (the last part is Task 17's)
```
- `final` Action classes, `declare(strict_types=1);`, typed signatures and PHPDoc array shapes; one `handle()` per Action like their siblings.
- Fixed query count for everything the tab and the queue will read (template + items + targets + donors + library entries + evidence + questions): expose read helpers Prompt B can call without N+1.

## Tests (Pest)
- Gate: approval + owner acknowledgement → project `approved`, no monthly reports, `pending` items created (through `ProjectFunded`); confirm → project `active`, exactly one `ProjectActivated` with `via = template_confirmation`.
- Confirm with no items refused; item without a target refused (tracking-only allowed); a legacy target without a library entry never becomes an item.
- Duplicate indicator from two proposals → one item, pivot holds both targets; suggested target = highest (`shared`) / sum (`split`); program mode change does not alter existing items; changing an item's mode after a submitted line refused.
- New proposal on an active project: project stays `active`; pending items invisible; after confirmation they appear only in that proposal's months, never before it starts, never in a submitted report; the project manager is notified without amounts.
- `isDueIn`: `selected-months`, `after-months`, quarterly from the item start, `follow-up` at funding + N; `per-season` cannot use `library`.
- Fixed denominator pre-fill = highest (`shared`) / sum (`split`); confirming a `fixed` item without it refused.
- Institutional item for 2026 creates the minimal annual plan and its `plan_indicators` row (`achievedValue()` works on it); for 2027 without a plan → refused and `annual_plan.missing` fired.
- D-26: confirming a project without agreement and without approval evidence refused; with evidence allowed.
- Authorization: the project manager editing or confirming → 403; another project's `plan_indicator_id` rejected; a MEAL user who is not the project's officer nor the MEAL Manager cannot confirm; only the MEAL Manager slot holder manages evidence types and the bank.
- Concurrency: two confirmations → one activation; a retried listener creates nothing new.
- `report-templates:seed-existing --dry-run` writes nothing; the real run refuses an unconfirmed multi-donor program and is idempotent; the reference-list migration/command is idempotent.
- Fixed query count for the read helpers (5 and 40 items).

## Acceptance criteria
- [ ] No project becomes `active` without a confirmed template (test + a read-only check query for Task 27).
- [ ] MEAL can see every project waiting for a template or for new items, and confirm one in under two minutes (Prompt B uses your helpers).
- [ ] The project manager receives a clear notice of new indicators and their months, without amounts.
- [ ] `isDueIn()` is the only due-month definition and is recorded with `record-rule`; the CP3 contract is written in your report.

## Do not
- Write the template target over `plan_indicators.target`.
- Create a second item for the same indicator instead of linking the target.
- Add lines to past months or reopen reports when items are confirmed.
- Compute due-ness anywhere except `isDueIn()`.
- Seed production reference data through `db:seed`, or list Super Admin in any business permission.

## Quality bar (this task)
- One read helper per question the UI asks (queue rows, tab items with donors and targets, preview of month M), each at a fixed query count; `isDueIn()` is pure and unit-tested over a table of cases.
- Confirmation, retirement and mode changes are single transactions with locks; events and notifications after commit; every message translated.
- Everything in `quality-bar.md` applies. Before reporting, run `vendor/bin/phpstan analyse` on the touched paths and list the result in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions (the non-retired unique index technique, the reference-data mechanism, T-02, event reconciliation); wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes, and `vendor/bin/phpstan analyse` on touched paths.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside this task's scope; note anything else as a follow-up.

## Report back
Your final message must contain: summary; files changed; migrations added; tests run with pass/fail output; decisions assumed (T-02, unique-index technique, reference-data mechanism, D-26 guard, event reconciliation); follow-ups. The **CP3 contract**: table and column names and casts; enum values; `isDueIn()` signature and semantics; every Action's `handle()` signature and validation keys; the policy abilities; the read helpers Prompt B uses; the listeners and the events they fire; the commands and their options. Handoff for Task 17 (lines, `entered_by`, `fixed_denominator`, `donor_attribution`, evidence types per item, template questions, `activity_evidence_types`), Task 18 (item target and "not due" cells), Task 19 (shared vs split footnote), Task 21 (event keys and the deadline source), Task 27 (`report-templates:seed-existing` order, the reference-data step, POST check queries).
````

## Prompt B — UI (B3)

````markdown
## Role and goal
You are implementing Task 33, Prompt B (UI), of the HC Tracking proposal-lifecycle correction plan in this Laravel 13 / Livewire 4 / Flux UI 2 repository. Prompt A (contract frozen at CP3, end of day 9) delivered the Monthly Report Template backend. Your outcome: MEAL's screens for it — a **Monthly Report Template** tab on the **Project** page, the **Template Queue** page, and the **Evidence Types** and **Narrative Questions** settings — so MEAL can see every project waiting for a template (or for new items from a new proposal), build and confirm it quickly, and the project manager can read exactly what will be asked each month.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md`.
2. The plan: `### Task 33` (the «الواجهة» bullets and the Revision 5/7/8/9 subsections), `### Task 17` «تعديلات المراجعة 4» (how the monthly report will look), section 8 «إمكانية الوصول».
3. Prompt A's final report (the CP3 contract). If you do not have it, read the Actions in `app/Actions/ReportTemplates/`, the policies, enums and read helpers before planning.
4. Rule files: `.ai/rules/pages.md`, `ui.md`, `views.md`, `views-enums.md`, `views-pages.md`, `components-ui.md`, `css.md`, `navigation.md`, `components-navigation.md`, `navigation-css.md`, `routes.md`, `admin.md`, `tests.md`.
5. Code to study: `resources/views/pages/projects/⚡show.blade.php` (tabs, header, existing panels), `resources/views/pages/admin/⚡cities.blade.php` and `⚡accounts.blade.php` (admin page anatomy), `resources/views/pages/indicator-library/⚡index.blade.php` (Task 11: library search to reuse in **Add from Library**), `resources/views/components/ui/{page-header,detail-header,panel,data-table,empty-state,enum-badge,confirm-modal,form-section,header-filter-select,multi-select-popover}.blade.php`, `resources/views/pages/programs/⚡show.blade.php` (where the donor-mode control goes), `resources/views/components/navigation/sidebar.blade.php`.
6. Skills: `livewire-development`, `fluxui-development`, `tailwindcss-development`, `laravel-security`, `testing-best-practices`.

## Preconditions to verify
- Prompt A is merged (or its CP3 signatures are signed): tables, enums, Actions, policies and read helpers exist; `php artisan test --compact --filter=Template` is green.
- No `report-templates.*` route exists yet.
If a check fails, stop and report; never change A's contract without B4's and B1's approval.

## Scope
### In scope
- **Monthly Report Template tab** on `projects/⚡show` (its own partial; agree the slot with Dev 1/Dev 2 because Phase 1 owns the page):
  - Header strip: project status (`approved` → «ممول، بانتظار نموذج التقرير»), template state (draft / confirmed / has pending items), last change, confirmer.
  - Items grouped by source: each proposal (name + donor as plain text) and **Institutional**. Per item: code (LTR-isolated), name in the user's language with the other language below, measure, the donors' targets for comparison (read-only), the **approved target** input with `target_basis`, the fixed denominator for `fixed` percentages, schedule control (`library` / `monthly` / `selected-months` month picker / `after-months` number), mandatory evidence types (`x-ui.multi-select-popover`), `entered_by` toggle with a «يحتسبه MEAL» badge, the donor-mode badge («مشترك: القيمة نفسها لكل مانح» / «مقسَّم: حصة لكل مانح»), status badge (`pending` / `confirmed` / `retired`), and **Retire** for confirmed items.
  - **Add from Library** (search active entries of the project's program plus institutional ones; library-only), **Confirm Template** (`x-ui.confirm-modal` listing what will happen: items confirmed, project activated if `approved`, months that already passed «{n} أشهر من مدة المقترح مُرسلة ولن يظهر فيها هذا المؤشر»), and **Preview month X** (a read-only rendering of the narrative questions, the due items with the share sub-rows for `split`, the disaggregation inputs for `is_disaggregated`, the fixed denominator text, and the mandatory evidence types).
  - **Activity evidence** section: the linked proposals' activities, each with its mandatory evidence types when reported done.
  - **Questions** section: the project's copy of the narrative questions (edit, add a project-specific one, deactivate), with **Apply to all templates** shown only in the bank settings.
  - Read-only rendering for the project manager and program manager; a closed project is read-only for everyone.
- **Template Queue** page (`/monthly-report-templates`, `pages::report-templates.index`, `report-templates.view`): `approved` projects waiting for a template and active projects with `pending` items, oldest first; columns: project (center, program), source proposals and donors (plain text), waiting since (working days, with the overdue marker after the threshold), pending item count, action **Open** (eye button to the project tab). Filters with `x-ui.header-filter-select` (program, governorate, "my projects" via `projectsCoveredBy()`); a count badge in the header; sidebar entry in the Monitoring & Evaluation group.
- **Evidence Types** and **Narrative Questions** settings (sections on one admin page or two small pages; choose in plan mode): list, create, edit, deactivate (never delete when used), reorder, bilingual fields; **Apply to all templates** for new questions.
- **Donor mode** control on `programs/⚡show` (`programs.update` holders in MEAL and GM): `shared` / `split` with a one-line explanation and the last confirmation date.
- Lang keys and stable `data-tour` anchors on the queue and the tab for Task 26.
### Out of scope
Backend rules (Prompt A); the monthly report pages (Task 17); the Monthly Matrix (Task 18).

## Business rules (UI)
- Every rule is enforced by A's Actions; the UI mirrors them and shows their messages next to the field or in the confirm modal. Never compute due months, targets or totals in the view — call A's helpers.
- `pending` items are visibly different from `confirmed` ones (badge text, not colour only).
- The preview uses the same helper Task 17 will use for lines, so what MEAL previews is what the manager will see.

## Security and authorization
- Each write calls A's Action; the component checks policies only to show or hide controls. Ids in state are `#[Locked]`; foreign ids surface A's error.
- No money anywhere: donor names are plain text, targets are indicator values, not amounts.

## Edge cases
- No items yet: empty state with **Add from Library** for MEAL, and «لم يُعدّ MEAL نموذج التقرير بعد» for others.
- Another MEAL user confirms meanwhile: the next action gets A's stale/locked message; re-render from the server.
- Long Arabic names: wrap within the cell; numbers and codes stay LTR.

## Tests (Pest)
- Tab: MEAL sees inputs; the project manager sees the read-only version; confirm modal shows the consequences; confirming activates the project and the header changes; a foreign item id shows the error and writes nothing; preview of a quarterly month lists the quarterly question and only due items; `split` preview shows one sub-row per donor; query count flat for 5 and 40 items.
- Queue: lists `approved` waiting projects and active projects with pending items, oldest first; "my projects" filter follows `projectsCoveredBy()`; a user without `report-templates.view` gets 403.
- Settings: only the MEAL Manager slot holder can create, edit and deactivate evidence types and questions; a used type cannot be deleted; **Apply to all templates** adds only new questions.
- Program page: donor mode saved by MEAL/GM, 403 for others; the confirmation date is stamped.
- Arabic and English render; `LocalizationTest` green.

## Acceptance criteria
- [ ] MEAL builds and confirms a project's template from one tab in under two minutes, with a preview of a real month.
- [ ] The queue shows every project waiting for MEAL, oldest first, with overdue markers.
- [ ] The project manager can read the template but never change it.
- [ ] Every screen passes the quality bar: states, RTL/LTR, light/dark, 375 px, keyboard, contrast.

## Do not
- Re-implement A's rules in the view or bypass its Actions.
- Show amounts, or link to donor/proposal pages for users without their view permission.
- Bind a Flux modal with `:open`, use `@php(...)` shorthand, or use left/right classes.

## Quality bar (this task)
- Tab: items grouped by source with sticky group headers; inline editing with per-item save state; schedule control shows the resulting months as chips («الأشهر 3، 6، 9، 12») so MEAL sees the effect before confirming; the confirm modal lists consequences and any months that will not get the item.
- Queue: oldest first, overdue marker in text and colour, filters in the header, an empty state that says «لا مشاريع بانتظار نموذج التقرير».
- Settings: drag-free, keyboard-accessible reordering; deactivate instead of delete when used, with the reason shown.
- Everything in `quality-bar.md` applies (states, RTL/LTR, light/dark, 375 px, keyboard, contrast).

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions (settings page layout, tab slot on the project page); wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes, and `vendor/bin/phpstan analyse` on touched paths.
- Verify every screen in the browser (Arabic RTL + English, light + dark, desktop + 375 px) before reporting; use the preview approach in `.ai/rules/pages.md` if you cannot sign in.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands unless the developer asks. Never run anything against production.
- Stay inside this task's scope; note anything else as a follow-up.

## Report back
Summary; files changed; tests run with pass/fail output; screens checked (locale × theme × width); decisions assumed; follow-ups. Handoff for Task 26 (route names, page titles, `data-tour` anchors for the queue and the tab) and Task 28 (the UAT path: approve → queue → build → preview → confirm → project active → monthly reports generated).
````
