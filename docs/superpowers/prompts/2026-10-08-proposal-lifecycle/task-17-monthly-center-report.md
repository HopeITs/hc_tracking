# Task 17 — Monthly center report

> **المهمة:** التقرير الشهري للمركز
> **المطوّر:** B1 (backend، مراجعة Dev 1) + B2 (الواجهة، مراجعة Dev 2) · **الفريق:** B · **الأيام:** 7–11 (الثلاثاء 2026-10-20 ← الاثنين 2026-10-26) · **الجهد:** 7.5 أيام في الخطة (المراجعة 3)، وتضيف المراجعات 4–9 نحو 6 أيام تُمتص حسب R9 · **يعتمد على:** Task 01 (القالب وD-07/D-08/D-09/D-29)، Task 08 (أصناف الأحداث في CP2)، Task 13، Task 15، Task 30، **Task 33** (بنود النموذج و`isDueIn()`، عقد CP3)
> **متى تستخدمه:** البرومبت A (B1) من اليوم 7 (نصف يوم للمخطط) بعد CP2 (نهاية اليوم 6: أصناف `ProjectActivated` و`ProjectPeriodChanged` موجودة) ودمج Task 15 ووصول قالب التقرير الشهري من مدير MEAL (الموعد الأقصى اليوم 5)؛ اليوم 8 الحالات ومستمع التوليد الآلي، واليوم 9 الإرسال والتحقق، واليوم 10 إعادة الفتح والتسلسل والتنبيه، واليوم 11 الاختبارات. يُجمَّد عقده (المخطط والحالات ومستمع التوليد وواجهة الـ Actions) في نقطة التكامل CP3 (نهاية اليوم 9، 2026-10-22)، ويُختبر التوليد الآلي ضمن اختبار التكامل الكامل في CP4 (نهاية اليوم 11). البرومبت B (B2) من اليوم 9 على عقد A المجمَّد في CP3 (الأيام 9–11)، ويعتمد على عقد A كما ورد في تقريره الختامي أو في توقيع CP3.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

يحتوي هذا الملف على برومبتين: **A** للـ backend (B1) أولًا، ثم **B** للواجهة (B2) في جلسة منفصلة على عقد A المجمَّد في CP3. ألصق كل برومبت في جلسته الخاصة، وأعطِ B2 قسم **Report back** من رد Claude في جلسة A (أو عقد CP3 الموقَّع).

## Prompt A — backend (B1)

````markdown
## Role and goal
You are implementing Task 17, Prompt A (backend), of the HC Tracking proposal-lifecycle correction plan in this Laravel 13 / PHP 8.5 / Livewire 4 / Flux UI 2 repository. The outcome: the monthly center report backend. One draft report per project per month is generated automatically when the project is activated — which, since Revision 4, is when MEAL confirms its monthly report template (Task 33) — and extended when its period changes, regardless of the donor's reporting schedule. Its lines are the template items due that month (`monthly_report_lines`), each mirrored into `reports` rows for the linked donor targets so the single achievement definition `PlanIndicator::achievedValue()` keeps working unchanged; it also carries narrative answers, typed evidence files and the month's activities. The report is filled (zero allowed), submitted and locked; the project's MEAL officer confirms it or returns it with a reason, never edits the manager's values; a confirmed report reopens only through a justified request decided by the MEAL Manager slot holder (or Public Relations Manager while that slot is vacant). In the corrected story (draft project -> concept note -> proposal -> donor approval funds the project -> MEAL confirms the template and the project becomes active -> one agreement) this is how per-center targets (Task 13) get monthly figures; Prompt B builds the pages on your contract, Task 18 (**Monthly Matrix**), Task 19 (aggregated donor reports) and Task 21 (monthly summary) read the validated lines. Your contract is frozen at CP3 (end of day 9).

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins).
2. The plan `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`: section `### Task 17` in full (Arabic and authoritative; English table, column, status, permission and UI names are exact). Also its `#### تعديلات المراجعة 2 (2026-10-08)` block, `### Task 13`, `### Task 15`, `### Task 18`, `### Task 19`, `### Task 21` (consumers and the event catalog), `### Task 30` (`EmployeeProfile`), `### Task 08` (`ProjectActivated` / `ProjectPeriodChanged`), section 0 «قواعد العمل بين الفريقين», section 1.0 facts 5, 7 and 8, section 1.2, section 5 rows "النطاق (Scope)", "BOLA/IDOR في Livewire", "التزامن", "الحفظ الجزئي", "سجل التدقيق", "الإشعارات والبريد", "التوليد الآلي", "الأوامر المجدولة", section 7 rows CP2, CP3 and CP4, section 9 rows D-07, D-08, D-09, D-12, D-13, D-22, D-29, D-30.
3. The decision log `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists; a recorded decision overrides the plan's default for D-07 (validation by MEAL counts; "publish" is an optional flag), D-08 (sequencing — Revision 2 applies it on **submit**, not open, because months are pre-generated), D-09 (who decides reopen), D-12 (one MEAL officer per city, MEAL role as fallback), D-13 (supervision source = HR direct manager, local `supervisions` for exceptions), D-22 (working week), D-29 (generate every month at activation; existing active projects from the deploy month, October 2026, not retroactively). Also the MEAL monthly template recorded under Task 01.
4. Rule files: `.ai/rules/database-migrations.md`, `.ai/rules/migrations.md`, `.ai/rules/feature.md`, `.ai/rules/models.md`, `.ai/rules/providers.md` (strict mode: lazy loading throws; morph map), `.ai/rules/views-pages.md` (`HasSlug`), `.ai/rules/enums.md`, `.ai/rules/views-enums.md`, `.ai/rules/factories.md`, `.ai/rules/seeders.md`, `.ai/rules/database-seeders.md`, `.ai/rules/resultsindicatorstargetsreports.md` (one achievement definition), `.ai/rules/reports.md` (form never writes status/submit_date; drafts only deleted, files removed from disk), `.ai/rules/alerts.md`, `.ai/rules/recipients.md`, `.ai/rules/views-components-notifications.md`, `.ai/rules/pages.md`, `.ai/rules/tests.md`. Then run `grep -rin 'report\|deadline\|seen\|alert' .ai/rules`.
5. Existing code to study first:
   - `app/Models/Report.php` (`#[Fillable]`, `slugSource()` reads `planIndicator->indicator->name` — under strict mode set the relation before saving a new line, `activityEventForChange()`), `app/Enums/ReportStatus.php`, `app/Policies/ReportPolicy.php`, `app/Actions/Reports/{SubmitReportForReview,ApproveReport,ReturnReportToDraft,MarkReportSeenByMeal,DeleteReport,SaveReportFile,DeleteReportFile}.php`, `app/Livewire/Concerns/ManagesReportForm.php` (`coverableWindow()`, percentage cap, save rules).
   - `app/Models/PlanIndicator.php` (`achievedValue()`, `pendingValue()`, `achievedValueSql()`, `pendingValueSql()`), `app/Models/Project.php` (`start_date`, `end_date`, `city_id`, `user_id` = HR employee), `app/Models/User.php::ownedProjectIds()` (owner + program manager), `app/Support/MealCoverage.php` (Task 15), `app/Concerns/HasSlug.php`.
   - Alerts: `app/Notifications/Alerts/{DeadlineCatalog,DeadlineItem,AlertDispatcher,WorkingCalendar,NotificationSettings,StalledScanner,DataGapScanner}.php`, `config/notifications.php` (`modules`, `view_permissions`, `module_icons`, `defaults`), `resources/views/pages/admin/⚡notification-settings.blade.php` (`Keys`, validation), `app/Notifications/{EmployeeRecipients,SubjectLinks,ActivityRecorder}.php`, `app/Notifications/Recipients/{ModuleRecipients,ReportRecipients}.php`, `app/Concerns/RecordsActivity.php::recordActivity()`, `app/Models/Activity.php::withoutRecording()`, `app/Providers/AppServiceProvider.php::configureMorphMap()`.
   - Tests: `tests/Feature/ReportTest.php`, `tests/Feature/Models/ReportTest.php`, `tests/Feature/Models/PlanIndicatorTest.php`, `tests/Feature/AccessControl/ReportWorkflowAuthorizationTest.php`, `tests/Feature/AccessControl/PolicyCoverageTest.php`, `tests/Feature/Notifications/{DeadlineCatalogTest,StalledScannerTest,IndicatorAndGapScannerTest,ReportActivityTest,NotificationSettingsAdminTest}.php`, `tests/Feature/Models/MorphMapTest.php`, `tests/Feature/DatabaseSeederTest.php`, `tests/Pest.php` (`insertPlanIndicator()`, `insertReport()`, `userWithRole()`).
6. Skills to activate: `laravel-best-practices`, `database-migrations`, `laravel-security`, `testing-best-practices`.
7. Use Laravel Boost `search-docs` before relying on framework APIs (`lockForUpdate`, `UniqueConstraintViolationException`, nested array validation, `DB::afterCommit`, date casts). Use `database-schema` on `reports`, `plan_indicators`, `projects`, `report_files` before writing migrations.

## Preconditions to verify
- Funded projects carry `projects.start_date` / `end_date` (Task 08) and become `active` when MEAL confirms their monthly report template (Task 33, Revision 4); the event classes `ProjectActivated` (dispatched by Task 33) and `ProjectPeriodChanged` (Task 08) exist and are dispatched after commit (CP2 end of day 6). Read their payloads; do not call Phase 1 Actions.
- Task 33's schema is frozen at CP3: `monthly_report_templates`, `monthly_report_template_items` (with `entered_by`, `donor_attribution`, `denominator_basis`, `fixed_denominator`, `is_disaggregated`), the item ↔ `plan_indicators` pivot, `evidence_types`, `monthly_report_template_questions`, `activity_evidence_types` and `TemplateItem::isDueIn()`. B4 builds it on days 8–10; agree the shapes with B4 before you write `monthly_report_lines`.
- `App\Support\EmployeeProfile` and `projects.center_id` exist (Task 30, CP1).
- Per-center targets exist as `plan_indicators` with `source_type = 'proposal'` and `project_id` = center (Task 13).
- `App\Support\MealCoverage::responsibleFor(Project $project): ?int` and `projectsCoveredBy(int $employeeId)` exist (Task 15, Revision 9).
- `reports.reopen` exists in `config/access-control.php` (Task 02).
- No `monthly_reports` table exists. Optional: the Task 21 `App\Notifications\EventCatalog` (register your events there; if absent, add the keys and coordinate with Dev 3), `supervisions` + the HR direct manager (Task 23), the `meal_manager` slot of `proposal_default_reviewers` (Task 07), file encryption in `StoredFiles` (Task 22) — if missing, skip that part and list it as a follow-up.
If a required check fails, stop and report instead of improvising.

## Revision 2 changes (2026-10-08)
- Owners are now **B1 (Prompt A, backend, 3.5 days, reviewed by Dev 1)** and **B2 (Prompt B, UI, 3 days, reviewed by Dev 2)**, Team B; days 7–11 (Tue 2026-10-20 → Mon 2026-10-26). Effort 6.5 days. Dependencies add Task 30 and D-29; Task 08 now means the event classes delivered at CP2.
- Contract freeze moved from CP4 to **CP3 (end of day 9, 2026-10-22)**: schema, statuses, the generation listener and the Action interface. CP4 (end of day 11) runs the full E2E test, which includes "activation generates the monthly reports". Feature freeze is CP5 (end of day 12).
- **Automatic generation (D-29):** a queued listener `GenerateMonthlyReportsForProject` on `ProjectActivated` creates one `draft` `monthly_reports` row per month from the start month to the end month (no lines); on `ProjectPeriodChanged` it adds only the new months and never deletes a month that has values. Generation ignores donor reporting schedules (plan section 1.0, fact 5). Idempotent: the unique `(project_id, period)` index plus insert-or-skip, so a repeated or retried event creates nothing new.
- **Lines** are generated on the first working day of each month by a new scheduled command `monthly-reports:prepare` (daily, `withoutOverlapping()`, `onOneServer()`, ledger/idempotent, `--dry-run`), and on first open if not yet generated, because targets may be added after activation.
- **Submit** is refused before the last working day of the month (`WorkingCalendar`), and only for months inside `start_date`…`end_date`.
- **Sequencing (D-08) moves from open to submit:** month M cannot be submitted while month M-1 (inside the project window and with targets) is not submitted. Months already exist, so opening any generated month is allowed.
- **Backfill for existing active projects:** a command `monthly-reports:backfill {--from=YYYY-MM} {--dry-run}` generates months for projects already `active` at deploy, from the deploy month (default `2026-10`), never retroactively (D-29). Task 27 runs it in the rehearsal.
- **Center manager = project owner** (project = HR center, D-30). When the owner's current HR position (Task 30 `EmployeeProfile`) is not at the project's center, show a warning — never block.
- A generated `draft` month that has not started yet is excluded from "overdue", from due/overdue alerts and from deadlines.
- **Events** in the Task 21 catalog (`App\Notifications\EventCatalog`) so MEAL + General Management are notified: `monthly_reports.generated` (one grouped notification per activation, not one per month), `monthly_report.due`, `.overdue`, `.submitted`, `.validated`, `.returned`, `.reopen_requested`, `.reopen_decided`.
- Supervision (Task 23) now comes from HR first (`positions.direct_manager_id` through `EmployeeProfile::managerOf()`), with the local `supervisions` table for exceptions only.
- Work under the inter-team rules (plan section 0 «قواعد العمل بين الفريقين»): do not change Phase 1 tables (`proposals`, `projects`, `donor_proposal`, `proposal_status_notes`) or the proposal state machine — request any such change from Dev 1 so it is recorded in the plan; integrate with Phase 1 by listening to its events (`ProposalStatusChanged`, `ProjectActivated`, `ProjectPeriodChanged`, `FundingAgreementRecorded`, dispatched after commit) instead of calling Phase 1 Actions directly; every PR is reviewed by Dev 1 (backend) or Dev 2 (UI) within one working day and merges only with this task's suite green; no production access — seed or masked data only.
- Never change `projects` or `proposals`; react to `ProjectActivated` / `ProjectPeriodChanged` only. Any extra payload you need on those events is requested from Dev 1 before CP3.

## Revision 3 changes (2026-10-09)
Source: `Stratigy.pptx` (strategy 2026–2030) and `micro logic.xlsx` (the real Micro program logframe, urban and agricultural tracks). Read plan section 1.0 facts 10–12 and the «تعديلات المراجعة 3» subsection of this task. Where this block conflicts with text further down this prompt, this block wins.
- This block **replaces** the rule "one line per active `plan_indicator` of the project; value required on submit (zero allowed)".
- **Lines only for due indicators (D-36).** Month M of a center gets a line for a `plan_indicator` only when (a) its indicator has `collected_by = project_team` or no library entry (legacy behaviour), and (b) its frequency is due in M: `monthly` and `per-event` every month; `quarterly` in the last month of each quarter counted from the proposal start; `semi-annual`/`annual` in the period's last month; `follow-up` in month (funding start + `follow_up_months`). `per-season`, `baseline-endline`, `one-time` and every `collected_by = meal` indicator never appear in the monthly report: they are entered through the existing per-target reports on **Reports** (`planned_date`, existing overdue alerts). Without this filter managers enter zeros for outcome indicators and a percentage's achievement (latest approved value) collapses to 0.
- **Numerator/denominator.** Add `reports.numerator` and `reports.denominator` (decimal(12,2), nullable). For `percentage`/`average` indicators whose library entry has `numerator_label`, the user enters both and the server computes `value` (×100 for percentage); never accept `value` from the request for these lines. Zero denominator → «المقام لا يكون صفرًا؛ اترك السطر فارغًا إن لم يُقَس شيء هذا الشهر».
- **Not measured this month:** allowed for percentage/average lines (`value` NULL, which requires `reports.value` to become nullable — README note 8). It is excluded from achievement and is not a zero. Counts stay required (zero allowed).
- **Pooling (D-25).** Add `PlanIndicator::pooledRatioSql()` (plus a PHP twin and an agreement test): Σnumerator ÷ Σdenominator over the latest approved line per target inside a window (×100 for percentage). Tasks 18 and 19 must use it and nothing else. Record it with `record-rule` on `.ai/rules/resultsindicatorstargetsreports.md`. Its signature is part of the CP3 contract.
- Extra tests: a `collected_by = meal` indicator gets no line; a 12-month `follow-up` indicator appears only in funding month 13; a quarterly one appears in months 3/6/9/12; `value` is computed from numerator/denominator and a posted value is ignored; zero denominator rejected; a not-measured line leaves `achievedValue()` unchanged. *(The due-line rule and its first three tests are replaced by Revision 4: due-ness now comes from `TemplateItem::isDueIn()`; keep them as `isDueIn` cases in Task 33's tests.)*

## Revision 4 changes (2026-10-12)
Source: plan header paragraph «المراجعة 4», section 1.0 facts 13–17, section 1.2, decisions D-38…D-45, the «تعديلات المراجعة 4» subsection of this task and `### Task 33`. This block **replaces** the Revision 3 rule "lines only for due indicators (D-36)", the base rule "one line per active target" and the fixed narrative columns on `monthly_reports`. Where it conflicts with the Revision 2/3 blocks or the base sections, it wins.
- **Generation:** the `ProjectActivated` listener stays, but the event now fires when MEAL confirms the template (Task 33). An `approved` project (funded, template not confirmed) has no monthly reports.
- **Lines = due template items (D-38):** month M gets one line per `confirmed` item of the project's template with `starts_on ≤ M ≤ ends_on` and `TemplateItem::isDueIn(M)` (Task 33's single definition — never recompute frequency here). `pending` and `retired` items never appear.
- **No retroactivity (D-41):** **Refresh Lines** and `monthly-reports:prepare` add lines for newly confirmed items to **draft** reports only, inside the item's window. A `submitted` or `validated` report is never touched or reopened because of a new item, and no line is added to a month before the item's proposal starts.
- **Line table:** `monthly_report_lines` (`id`, `monthly_report_id` FK cascade, `template_item_id` FK restrict, `value`, `numerator`, `denominator` decimal(12,2) nullable, `not_measured` boolean, `note`, timestamps; unique (`monthly_report_id`, `template_item_id`)). This is what the manager fills and MEAL reads.
- **Mirrors keep achievement unchanged (D-40):** for every line, one `reports` row per `plan_indicator` linked to its item (`reports.monthly_report_line_id`, FK cascade), with the line's value, numerator and denominator and the status that mirrors the report (`draft` ↔ `reviewed` ↔ `approved`). Mirrors are written inside the same transaction as save, submit, validate, return and reopen, and are never editable from **Reports**. An institutional item mirrors onto its annual-plan target; a tracking-only item (no target) has no mirror. This is for `shared` items; `split` items are in Revision 5. Decide in plan mode whether mirrors also keep `reports.monthly_report_id` (useful for Task 18/19 queries).
- **Narrative (D-43, D-43a):** `monthly_report_answers` (`monthly_report_id`, the template question id, `question_text` copied at answer time, `answer`). Monthly questions every month; quarterly questions in the last month of each quarter counted from the project start. Required questions block submit — «أجب عن السؤال: {السؤال}». Questions come from the project's template (`monthly_report_template_questions`), not from the bank.
- **Evidence files (D-42):** `report_files.monthly_report_line_id` and `evidence_type_id` (required for files on a monthly report). On submit every mandatory evidence type of the line's item needs at least one file — «أرفق {النوع} للمؤشر {المؤشر}». A Not measured line is exempt.
- **Existing active projects (D-45):** their reports exist from the deploy month, but they have no lines until MEAL confirms the seeded template (Task 27's `report-templates:seed-existing`); the report shows «بانتظار تأكيد نموذج التقرير من MEAL».
- In the base Business-rules table read "one per active target" as "one per due confirmed item", and drop the "active target in a month" bullet (the item window and `isDueIn()` replace it).
- Tests: an `approved` project has no reports; a `pending` item never shows; a newly confirmed item is not added to a submitted month or to a month before its proposal starts; one `shared` item linked to two donors → one line and two mirrors with the same value, and `achievedValue()` of each target equals it; submit without a mandatory evidence file refused; a quarterly question shows in months 3 and 6 only; editing a question later does not change old answers.

## Revision 5 changes (2026-10-12)
Source: management note on co-funded indicators, recorded as **D-46** (amends D-40). Read plan section 1.0 facts 14 and 18, decision row D-46, and the «تعديلات المراجعة 4» and «تعديلات المراجعة 5» subsections of this task in the plan. Where this block conflicts with text further down this prompt, this block wins.
- An indicator shared by several donors in one center follows its program's mode, `programs.donor_attribution` (`App\Enums\DonorAttribution`: `shared`, `split`; default `shared`). `shared` (e.g. DESC: 30 students, one report, 30 for each donor) = D-40. `split` (e.g. Micro: 10 beneficiaries = 6 for donor 1 + 4 for donor 2, a separate report per donor): the center manager enters each donor's share monthly, each donor's mirror carries only its share, and the center's achievement is the sum. A beneficiary has exactly one funder, so there is no separate total field and no reconciliation check.
- New table `monthly_report_line_shares` (`monthly_report_line_id` cascade, `plan_indicator_id` restrict, `value`, `numerator`, `denominator`, `not_measured`, unique on the pair). `plan_indicator → proposal → donor` identifies the donor; the manager never picks a donor and `plan_indicator_id` is never accepted from the request.
- Shares are created with the line for each linked `plan_indicator` whose proposal covers the month (D-41); later-linked proposals add shares to draft reports only.
- The line's `value` / `numerator` / `denominator` are computed server-side as the sums of its shares. Mirrors for `split` items point at the share (`reports.monthly_report_line_share_id`, nullable) and carry its numbers; `achievedValue()` is unchanged.
- Submit: every count share required (zero allowed); ratio shares need numerator and denominator or Not measured. Evidence files stay on the line. UI: a sub-row per donor (donor, proposal, target) and an automatic total.
- Tests: 6 + 4 → line 10, mirrors 6 and 4; ratio shares 9/10 and 1/2 → line 10/12; client-sent line value ignored; empty share blocks submit; `shared` items create no shares.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-05, D-07, D-09, D-12, D-43, D-48, D-49, D-50, D-51, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
These changes replace the Revision 7 activity statuses (`ActivityExecution`, `rescheduled_to`) and their validation.
- D-07: after submit the report is locked for the manager; MEAL either **Confirm** (counts in achievement) or **Return** with a mandatory note. D-05: reports exist for every project month, including funding gaps (institutional items and narrative only). D-04: the first report is the month of `actual_date_begin`. D-12: submit notifications go to `MealCoverage::responsibleFor()`.
- ~~D-09/D-09a: when the slot is empty, the project's MEAL officer decides~~ — superseded by Revision 9 below (Public Relations Manager decides while the MEAL Manager slot is vacant; use Task 07's slot resolver).
- **D-48 (plan Revision 7):** items with `entered_by = meal` are read-only for the manager (badge «يحتسبه MEAL»), do not block submit, and block **Confirm** until MEAL fills them or marks «لم يُقس هذا الشهر» — «أدخل قيم مؤشرات MEAL أو علّمها: لم يُقس هذا الشهر». Policy `updateLine` checks `entered_by`: `project_team` lines → the project owner while `draft`; `meal` lines → the project's MEAL officer or the MEAL Manager while `draft` or `submitted`. Their mirrors are written when MEAL saves. BOLA: a manager posting a value for a MEAL line gets 403.
- **Activity section data model (plan Revision 7, statuses as replaced by Revision 8):** `monthly_report_activities` (`id`, `monthly_report_id` FK cascade, `result_id` FK restrict, `proposal_id`, `planned_month` date, `status` `App\Enums\ActivityStatus` nullable until filled, `started_on`, `executed_on`, `completed_on` (dates, nullable), `reason` text nullable, `note`, timestamps; unique (`monthly_report_id`, `result_id`)); activity evidence files in `report_files.monthly_report_activity_id` (nullable, next to `monthly_report_line_id`), with mandatory types from Task 33's `activity_evidence_types`; `project_activity_statuses` (`project_id`, `result_id`, `status`, `started_on`, `completed_on`, `last_monthly_report_id`; unique pair). Rows are created at generation and by **Refresh Lines** for draft reports only (D-41), from `activity_schedules` (the project override wins over the proposal row) where the activity's proposal covers the month, plus carried-over activities.
- **D-50 disaggregation (decided):** table `monthly_report_line_breakdowns` (`monthly_report_line_id` cascade, `monthly_report_line_share_id` nullable cascade, `gender` `App\Enums\Gender` male|female, `value`, `numerator`, `denominator`, `disabled_value`, `disabled_numerator`, `disabled_denominator`; unique on line+share+gender). For a disaggregated item the manager enters, per gender, the count and of whom persons with disabilities — or numerator and denominator and their disability parts for ratios/averages. Share (`split`) = sum of its breakdown; line = sum of shares (or of the breakdown for `shared`). All totals server-side; never from the request. Mirrors in `reports` carry totals only, so `achievedValue()` is unchanged.
  - Validation: disabled ≤ that gender's figure («عدد ذوي الإعاقة أكبر من عدد الذكور/الإناث»), same for numerator and denominator; numerator ≤ denominator; ≥ 0; both genders required on submit (zero allowed); Not measured on the share or line exempts the breakdown.
  - Security: load via `$line->breakdowns()`; reject a share id that does not belong to the line.
- **D-51 activity statuses (decided):** `App\Enums\ActivityStatus`: `not_started`, `started`, `ongoing`, `suspended`, `cancelled`, `completed`, `conducted` (definitions from the Children University calendar sheet). Required inputs: Not Started → reason (min 10); Started → start date; Ongoing → nothing; Suspended → reason; Cancelled → reason, final; Completed → completion date + required evidence, final; Conducted (events only) → execution date + required evidence. A `continuous` activity cannot be Conducted; an `event` accepts only Not Started, Conducted, Cancelled. Completed/Cancelled change only through report reopen.
  - Carry-over: an activity appears in month M if scheduled in M **or** its last confirmed status is Not Started, Started, Ongoing or Suspended (badge «مستمر من {الشهر}» / «متأخر من {الشهر}»). No `rescheduled_to`, no monthly rescheduling.
  - On **Confirm**, `project_activity_statuses.status` stores the same value with `started_on` / `completed_on`; Return writes nothing; reopen restores the previous state from the activity log.
  - A confirmed-status grid "activity × months" on the **Project** page (like the Activities Tracker sheet) with CSV export belongs to Prompt B.
- D-43a: the report reads the project template's questions (`monthly_report_template_questions`, Task 33), not the bank; answers keep the question text.
- Tests to add: breakdown 10 (2) + 17 (1) → line 27, mirror 27; disabled > gender rejected; numerator > denominator rejected; split + breakdown sums; Ongoing carries to next month without a schedule row; Completed stops appearing; event rejects Ongoing; Not Started without reason rejected; MEAL line blocks Confirm but not submit; manager editing a MEAL line → 403.

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
- **D-25 (count entry for percentages):** for a percentage item with `denominator_basis = fixed` the manager enters **only the count** (numerator, labelled by `numerator_label`). The denominator is the template's `fixed_denominator`, shown read-only («من أصل {n} مستفيدًا في المقترح»); the server computes the percentage and ignores any `denominator` in the request. Mirrors in `reports` store numerator and the fixed denominator, so `pooledRatioSql()` is unchanged. `split` (D-46): each share's denominator = that donor's proposal beneficiaries for this project. `entered` items (MEAL pre/post tests, surveys) keep numerator + denominator as in Revision 3.
- With D-50 disaggregation, counts per gender/disability are entered and summed on the server; the percentage is computed for the total only (the denominator is not disaggregated).
- Validation: numerator ≤ fixed denominator — «العدد أكبر من عدد مستفيدي المشروع في المقترح ({n})»; values ≥ 0; required on submit (zero allowed).
- **D-09a:** reopen decisions by the MEAL Manager slot holder, and by Public Relations Manager when the slot is empty (Task 02). No project-MEAL-officer fallback.
- **D-12a:** route to `MealCoverage::responsibleFor($project)` (project officer → governorate → MEAL Manager); MEAL queue from `projectsCoveredBy()`.
- **D-49b:** the activities section shows planned weeks when set («الأسبوع 2 و3»). **D-48a** confirmed (no longer open).
- Tests: fixed item 36 of 180 → 20% and mirror 36/180; numerator above denominator rejected; request `denominator` ignored for fixed items; split shares use their own proposal's denominator; an officer from another governorate assigned to the project sees and confirms the report while the governorate officer does not see it. Effort +0.5 day (B1).
- **Confirm/Return authority (D-12a):** `reports.approve` AND (the project's MEAL officer from `responsibleFor()`, the MEAL Manager slot holder, or General Manager) AND not the preparer/submitter. The base Security section's "any MEAL role holder" fallback and its "no city restriction" rule are dropped.

## Scope
### In scope
- Create migration `create_monthly_reports_table`: `id`, `slug`, `project_id` (FK `restrictOnDelete`), `period` (date, first day of the month), `status` (VARCHAR(50), default `draft`), `prepared_by`, `submitted_at`, `submitted_by`, `validated_at`, `validated_by`, `seen_by_meal_at`, the narrative fields of the MEAL template (default four, named like `reports`: `executive_summary`, `key_achievements`, `challenges`, `next_period_plans`), timestamps; UNIQUE `(project_id, period)`, index `status`. User columns are FKs to `users`; propose on-delete behaviour in plan mode (recommended `restrictOnDelete`, like `reports.user_id`, so the audit keeps its actors).
- Create migration `create_monthly_report_reopen_requests_table`: `monthly_report_id` (FK `restrictOnDelete`), `requested_by`, `reason`, `status` (default `pending`), `decided_by`, `decided_at`, `decision_note`, timestamps.
- Create migration `add_monthly_report_id_to_reports_table`: nullable FK `cascadeOnDelete`, indexed.
- Open questions to settle in plan mode before CP3: (a) `reports.value` is NOT NULL today, but lines must exist before a value is entered — recommended: make it nullable, keep it required in `ManagesReportForm`, prove `achievedValueSql()`/`pendingValueSql()` still match their PHP twins; (b) where the return reason lives — recommended `returned_at`, `returned_by`, `return_reason` on `monthly_reports`; (c) the line note — recommended `reports.narrative_report_notes`.
- Enums `App\Enums\MonthlyReportStatus` (`Draft`='draft', `Submitted`='submitted', `Validated`='validated', with `lineStatus(): ReportStatus` and `isEditable()`, exhaustive `match`) and `App\Enums\ReopenRequestStatus` (`Pending`='pending', `Granted`='granted', `Denied`='denied').
- Models + factories (states `submitted()`, `validated()`): `MonthlyReport` (`HasSlug` from project slug + `Y-m`, `TracksActivity`, relations `project`, `lines` (HasMany `Report`), `reopenRequests`, `preparedBy`, …) and `MonthlyReportReopenRequest`; `Report::monthlyReport()` and `monthly_report_id` in its fillable list.
- `app/Policies/MonthlyReportPolicy.php`: `viewAny`, `view`, `open` (with a `Project`), `update`, `submit`, `validate`, `return`, `requestReopen`, `decideReopen`, `markSeen`, `delete`.
- Guards on the generic workflow: `ReportPolicy::submit/approve/returnToDraft/delete/update` refuse a line; `SaveReportFile` and `DeleteReportFile` authorize a line against `MonthlyReportPolicy::update` on its report; `ManagesReportForm` refuses to open or save a line. Confirm this split in plan mode.
- Actions in `app/Actions/MonthlyReports/`: `OpenMonthlyReport`, `SaveMonthlyReportLines`, `SubmitMonthlyReport`, `ValidateMonthlyReport`, `ReturnMonthlyReport`, `RequestMonthlyReportReopen`, `DecideMonthlyReportReopen`, `MarkMonthlyReportSeen`, plus `RefreshMonthlyReportLines` (the **Refresh Lines** button) and `DeleteMonthlyReport` (draft never submitted).
- One read helper for both prompts, e.g. `app/Support/MonthlyReportPeriods.php`: active targets of a project in a month, the required months of a project, whether a month can be submitted (and why not: before its last working day, previous month not submitted), whether it has started, and the due date.
- Create the queued listener `app/Listeners/GenerateMonthlyReportsForProject.php` on `ProjectActivated` and `ProjectPeriodChanged` (after commit, idempotent; adds months, never deletes a month with values), emitting one grouped `monthly_reports.generated` event.
- Create commands `monthly-reports:prepare {--dry-run}` (generate lines for the current month on its first working day; scheduled daily in `routes/console.php` with `withoutOverlapping()` and `onOneServer()`) and `monthly-reports:backfill {--from=YYYY-MM} {--dry-run}` (existing active projects, default from the deploy month `2026-10`, never earlier than the project start; prints created/skipped per project).
- Owner–center check: a helper that warns when the project owner's current HR position (`EmployeeProfile`) is not at the project's `center_id` (warning only).
- Register every monthly-report event in `App\Notifications\EventCatalog` (Task 21) with MEAL + General Management as oversight recipients.
- Notifications: module `monthly-reports` with `app/Notifications/Recipients/MonthlyReportRecipients.php`, registered in `config/notifications.php` (`modules`, `view_permissions` => `reports.view`, `module_icons`); morph key `monthly_report`; sentences in `lang/{en,ar}/notifications.php` under `events.monthly_report.*`.
- Alerts: a monthly-report source in `DeadlineCatalog` (months that have not started are skipped); `NotificationSettings` default `monthly_report_due_working_days` = 5 in `config/notifications.php` plus its field on **Notification Rules** (`admin/⚡notification-settings`); exclude lines from `DeadlineCatalog::reports()`, `StalledScanner` and the `report_without_files` gap.
- Lang `lang/{en,ar}/monthly_reports.php` (validation and action messages), enum labels in `enums.php`. Seed a few monthly reports in `MealSeeder`; add both models to `DatabaseSeederTest` and a `PolicyCoverageTest` entry for the request model.
### Out of scope
Pages, routes, Sidebar, `SubjectLinks` entry and Reports-page changes (Prompt B); **Monthly Matrix** (Task 18); aggregated donor reports (Task 19); monthly summary email (Task 21); the `supervisions` table and HR supervision map (Task 23); the `ProjectActivated` / `ProjectPeriodChanged` event classes (Dev 1); encryption (Task 22); the optional department-head "publish" flag (D-07, follow-up); tours (Task 26).

## Business rules
| From | Action | To | Lines (`reports.status`) | Guards |
|---|---|---|---|---|
| — | Generate (listener on `ProjectActivated` / `ProjectPeriodChanged`, or `monthly-reports:backfill`) | draft | none yet | one per project and month (unique), idempotent, regardless of donor schedule |
| draft | Prepare / Open (`monthly-reports:prepare` on the month's first working day, or first open) | draft | created `draft`, one per active target | month ≤ current month, inside `start_date`…`end_date`; idempotent |
| draft | Save lines / narrative | draft | values and notes saved | preparer; draft only |
| draft | Refresh Lines | draft | missing lines added, none removed | draft only |
| draft | Submit | submitted | all `reviewed`, `submit_date` = today | every line has a value (0 allowed); no partial submit; not before the month's last working day; nearest previous required month submitted (D-08, on submit) |
| submitted | Validate | validated | all `approved` | MEAL scope; validator is not the preparer or submitter |
| submitted | Return (reason ≥ 10) | draft | all `draft`, `submit_date` NULL | MEAL scope |
| validated | Request reopen (reason ≥ 10) | validated | — | preparer side; one pending request |
| validated + pending | Decide: grant | draft | all `draft` (figures leave achievement) | `reports.reopen` |
| validated + pending | Decide: deny (note) | validated | — | `reports.reopen` |
| any | Mark as Seen | unchanged | — | `reports.mark-seen`; stamps `seen_by_meal_at` once |
- Active target in a month: `plan_indicators.project_id` = the project and the month overlaps the source window (annual plan year; proposal actual dates, else planned — the rule in `ManagesReportForm::coverableWindow()`; extract it to one shared place rather than copying). Raise in plan mode whether targets of proposals that are not approved are excluded (recommended: yes).
- A month with no active target is not required and does not block the sequence. A generated month that has not started is never overdue and raises no alert. A closed project keeps the months inside its window; months after `end_date` cannot be opened.
- Line rows: `user_id` = the opener, `period_start`/`period_end` = month bounds, `planned_date` = last day of the month (confirm), `value` NULL until entered.
- Figures count in achievement only after validation (D-07); percentage values ≤ 100.
- A line of a monthly report is never edited, submitted, approved or deleted from the generic **Reports** pages.

## Security and authorization
- Prepare (open, save, refresh, submit, delete draft, request reopen): `reports.create` / `reports.update` AND a relation to the project: `in_array($project->id, $user->ownedProjectIds(), true)` (owner = center manager, or program manager), or the owner's supervisor when Task 23 exists (HR direct manager via `EmployeeProfile::managerOf()`, plus `supervisions` exceptions). The owner whose HR position is at another center gets a warning, not a refusal. The permission alone is never enough.
- Validate and return: `reports.approve` AND (the user's `employee_id` equals `MealCoverage::responsibleFor($project)`, OR the user holds the MEAL role with no city restriction, OR General Manager) AND the user is neither `prepared_by` nor `submitted_by`. When `responsibleFor()` returns null, any MEAL role holder may validate (fallback). Define "no city restriction" in plan mode (recommended: a MEAL user who covers no city in `meal_coverages`).
- Decide reopen: `reports.reopen` (D-09; ask whether the same governorate scope applies). Mark as Seen: `reports.mark-seen`. View: `reports.view`. No money anywhere.
- Every Action calls `Gate::authorize` with `MonthlyReportPolicy` itself; reload the report by id with `lockForUpdate()` and re-check the policy on that row.
- BOLA: load lines only through `$monthlyReport->lines()->whereKey($ids)`; any id outside the report rejects the whole batch and writes nothing. Reopen requests load through `$monthlyReport->reopenRequests()`.
- Files keep the existing rules (`SecureUpload`, 5MB, `FileType`, ownership check, rate-limited download) and gain encryption through `StoredFiles` when Task 22 lands.
- Audit: `MonthlyReport` records `monthly_reports.generated` (one grouped entry per activation), `monthly_report.opened`, `submitted`, `validated`, `returned`, `reopen_requested`, `reopen_granted`, `reopen_denied`, `seen_by_meal`. Line status changes run under `Activity::withoutRecording` so one transition notifies once, not once per line; confirm in plan mode. Reopen requests are never deleted.

## Validation
Messages in `lang/{en,ar}/monthly_reports.php`, never inline text:
- `period`: required, `date_format:Y-m`, not after the current month — "You cannot open a report for a future month." / «لا يمكن إنشاء تقرير لشهر مستقبلي»; inside the project window — "The month is outside the project period." / «الشهر خارج فترة المشروع».
- Sequence — "Submit the :month report first." / «أرسل تقرير :month أولًا» (month via `translatedFormat`).
- `lines.*.id`: required, integer. `lines.*.value`: nullable on save, required on submit, numeric, min:0, max:100 for percentage lines, max 9999999999.99 — "Enter a value for every indicator (zero is allowed)." / «أدخل قيمة لكل مؤشر (صفر مسموح)». `lines.*.note`: nullable, max:2000.
- `reason` (return, reopen request): required, string, min:10. `decisionNote`: required when denying, max:2000.
- Locked — "The report is submitted and locked." / «التقرير مُرسَل ومقفل». Stale — "The report status just changed. Reload the page." / «تغيّرت حالة التقرير للتو، أعد التحميل».

## Edge cases and concurrency
- Generation and a manual open race on the same month, or the activation event is delivered twice: the unique index rejects the duplicate; catch `UniqueConstraintViolationException` and return the existing report so the UI can redirect to it.
- Submit while someone saves: both lock the report row (`lockForUpdate`); submit reads the lines after the lock; a save after submit gets the locked message.
- Validate and return at the same moment: each Action takes `expectedStatus` and refuses when the locked row differs; exactly one wins.
- Double submit / double validate / double decide: the second call is refused with the stale message and writes nothing.
- Target added after opening: **Refresh Lines** adds it in draft only; a target with reports cannot be deleted (existing rule).
- Responsible employee inactive or missing: `responsibleFor()` returns null and the MEAL role fallback applies; owner inactive in HR does not block validation.
- Permission removed mid-session: every Action re-checks and returns 403.
- Strict mode: set `planIndicator` (with `indicator`) on each new line and `project` on the report before saving, or `HasSlug` lazy-loads and throws.
- `ProjectPeriodChanged` shortens nothing (D-05: extend only); if a month disappears from the window it is kept when it has values and reported, never deleted.
- `monthly-reports:prepare` and `monthly-reports:backfill` run twice or overlap: no duplicates (unique index, `withoutOverlapping()`), `--dry-run` writes nothing.
- Monthly lines must not flood the existing scanners: exclude `monthly_report_id IS NOT NULL` from per-report deadlines, stalled reports and "approved report without files".

## Data integrity
- UNIQUE `(project_id, period)`; `reports.monthly_report_id` FK `cascadeOnDelete`; `monthly_report_reopen_requests.monthly_report_id` `restrictOnDelete`.
- Every transition is one `DB::transaction` changing the report and all its lines together; no mixed state. Bulk line updates go through Eloquent (or a documented query-builder update inside `withoutRecording` — decide in plan mode) and never leave a line behind.
- Delete only a draft that was never submitted (`submitted_at` NULL, no reopen requests); collect its stored files first and remove them from disk after commit (the DB cascade does not, `.ai/rules/reports.md`).
- Migrations: `database-schema` first; pre-check before changing `reports.value`; every `down()` works (dropping nullability fails once NULL rows exist — say so in the migration docblock, like `transfers_tracking`).
- Model `$attributes` mirror column defaults (`status`), casts use enums and CarbonImmutable.

## Implementation guidance
- Plain Action classes with `handle()`, `declare(strict_types=1);`, typed signatures, PHPDoc array shapes, `#[Fillable]`, `casts()`, curly braces.
```php
final class OpenMonthlyReport
{
    /** @throws ValidationException period / sequence errors; returns the existing report for a duplicate month */
    public function handle(Project $project, string $period): MonthlyReport {}
}

final class SaveMonthlyReportLines
{
    /** @param list<array{id: int, value: ?string, note: ?string}> $lines  @param array<string, ?string> $narrative */
    public function handle(MonthlyReport $report, array $lines, array $narrative = []): MonthlyReport {}
}

final class ValidateMonthlyReport
{
    public function handle(MonthlyReport $report, MonthlyReportStatus $expectedStatus): MonthlyReport {}
}
```
- Status mirror (from the plan): `draft -submit-> submitted -validate-> validated`; `submitted -return(reason)-> draft`; `validated -reopen granted-> draft`; lines mirror `draft <-> reviewed <-> approved`.
- Recipients: submit -> the governorate responsible (`MealCoverage::responsibleFor`) and the `meal_manager` slot (Task 07), else MEAL role holders; validate/return -> the preparer; reopen request -> `reports.reopen` holders in scope; decision -> the requester. Map HR employees to users with `EmployeeRecipients::usersFor()`. Fan-out runs after commit (`ActivityRecorder`).
- Deadlines: one `DeadlineItem` per project and required month not yet submitted; subject = the monthly report when it exists, otherwise the project; due date = `WorkingCalendar::addWorkingDays(endOfMonth, monthly_report_due_working_days)`; the stage key embeds the due date. The existing due-soon, overdue and escalation scanners then remind and escalate; never literals, never bare `startOfWeek()`.

## Tests (Pest)
Follow `.ai/rules/tests.md` (seed `RolesAndPermissionsSeeder`, `User::factory()->role(...)`, fixtures inside `Activity::withoutRecording`, assert on the records you create).
- Create `tests/Feature/MonthlyReportGenerationTest.php`: `ProjectActivated` generates every month of the period once (no lines); `ProjectPeriodChanged` adds only the new months and deletes none with values; a repeated or retried event creates no duplicates; one grouped `monthly_reports.generated` notification reaches MEAL + General Management; `monthly-reports:prepare` creates lines on the month's first working day (frozen time) and is idempotent; `monthly-reports:backfill --from=2026-10` covers existing active projects from October 2026 only, `--dry-run` writes nothing; a generated month that has not started is not overdue.
- Create `tests/Feature/MonthlyReportOpenTest.php`: lines only for active targets of this project (annual plan year, proposal window; other projects and out-of-window targets excluded); future month and out-of-window refused; opening month M while M-1 is unsubmitted is allowed (sequencing is on submit); duplicate open returns the same record (one row); a user with `reports.create` but no relation to the project gets 403; an owner whose HR position is at another center gets a warning but can prepare.
- Create `tests/Feature/MonthlyReportWorkflowTest.php`: save with a line id of another report rejects the batch and writes nothing; submit with an empty value refused; submit before the month's last working day refused; submit of M refused while M-1 (with targets) is unsubmitted, allowed when M-1 had no targets; submit locks the report, lines `reviewed`, `submit_date` today; save after submit refused; validate turns lines `approved` and `PlanIndicator::achievedValue()` changes; return needs ≥ 10 characters and restores drafts; stale `expectedStatus` refused with no change.
- Create `tests/Feature/MonthlyReportReopenTest.php`: request with reason; a second pending request refused; grant restores drafts and withdraws achievement; deny keeps `validated` with the note; requests survive and cannot be deleted; no `reports.reopen` -> 403.
- Create `tests/Feature/AccessControl/MonthlyReportAuthorizationTest.php`: MEAL editing a value -> 403; preparer validating own report -> 403; MEAL officer of another governorate -> 403; fallback MEAL validates a city without a responsible; General Manager validates; generic `ReportPolicy` and `ManagesReportForm` refuse lines.
- Create `tests/Feature/Notifications/MonthlyReportAlertsTest.php`: submit notifies the responsible and the MEAL manager (or MEAL role fallback) after commit, once per transition; every monthly-report event is in `EventCatalog` and reaches MEAL + General Management (`EventCatalogCoverageTest` green); due date honours the setting and working days; lines are absent from report deadlines, stalled reports and the files gap.
- Create `tests/Feature/Models/MonthlyReportTest.php`: casts, relations, slug, unique `(project_id, period)`, FK behaviours, column default by raw insert.
- Update `Models/MorphMapTest`, `AccessControl/PolicyCoverageTest`, `DatabaseSeederTest`, `EnumCastTest`, `LocalizationTest`, `Notifications/NotificationSettingsAdminTest`, `Notifications/DeadlineCatalogTest`, `Models/PlanIndicatorTest` (PHP and SQL forms agree with NULL draft values); keep `ReportTest`, `ReportDirectoryTest`, `ReportDetailTest`, `AccessControl/ReportWorkflowAuthorizationTest` green.
- Run `php artisan test --compact tests/Feature/MonthlyReportWorkflowTest.php` and each file above (or `--filter=...`).

## Acceptance criteria
- [ ] Opening, saving, submitting, validating, returning and reopening all run through Actions with server-side policy checks, each covered by a success and a 403 test.
- [ ] Figures enter achievement only after validation and leave it on a granted reopen (test through `achievedValue()`).
- [ ] No partial submit and no mixed line states (tests).
- [ ] Activation generates every month once, period extension adds only new months, and repeated events create nothing (tests); `monthly-reports:prepare` and `monthly-reports:backfill` are idempotent with `--dry-run`.
- [ ] Sequencing is enforced on submit; submit before the month's last working day is refused.
- [ ] Late months raise due, overdue and escalation alerts on working days with the admin threshold; months that have not started raise none.
- [ ] Every monthly-report event is registered in the Task 21 catalog and reaches MEAL + General Management.
- [ ] The contract (Action signatures, policy abilities, enums, helper, listener, commands) is written in the report for Prompt B and signed at CP3 (end of day 9).

## Do not
- Create a separate values table or a second achievement computation.
- Let MEAL change values, or allow partial submission.
- Trust ids, statuses or the preparer from Livewire state.
- Use `startOfWeek()` instead of ISO weeks, or literal day counts in alerts.
- Send notifications inside the transaction, or one notification per line.
- Delete a submitted monthly report or any reopen request.

## Quality bar (this task)
- One read helper for months and due-ness, used by the listener, the commands and the policy; fixed query counts for generation and for loading a report with all its lines, shares, breakdowns, answers and activities.
- Every transition writes the report, its lines, mirrors and activity statuses in one transaction; events and notifications after commit; one grouped notification per activation.
- Everything in `quality-bar.md` applies. Before reporting, run `vendor/bin/phpstan analyse` on the touched paths and list the result in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions; wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside this task's scope; note anything else as a follow-up.

## Report back
Your final message must contain: a summary; files changed; migrations added; tests run with pass/fail output; decisions assumed (D-07, D-08 on submit, D-09, D-12, D-13, D-22, D-29 and the plan-mode answers: `reports.value` nullability, return-reason columns, line note column, non-approved proposal targets, "no city restriction", line activity recording); follow-ups. The CP3 contract for Prompt B: every Action class with its `handle()` signature, thrown validation keys and messages; `MonthlyReportPolicy` ability names and arguments; enum values and labels; `MonthlyReport` attributes and relations to render; the `MonthlyReportPeriods` API (required months, can-submit reason, started flag, due date); the listener and both commands with their options and output; how line files are saved (Action + policy); lang groups; the notification module key and what B must add (routes `monthly-reports.index` / `monthly-reports.show`, `SubjectLinks`, Sidebar). Also for Task 18: lines are `reports` with `monthly_report_id`, approved = validated; for Task 19: how validated months per project and period are queried; for Task 21: event names (as registered in `EventCatalog`) and the deadline rule; for Task 23: where the HR direct manager and supervisions plug into the preparer check; for Task 27: migrations, any data pre-check, and the exact `monthly-reports:backfill --from=2026-10 --dry-run` / apply run step and expected output; for Task 29: the `monthly-reports:prepare` schedule entry.
````

## Prompt B — UI (B2)

````markdown
## Role and goal
You are implementing Task 17, Prompt B (UI), of the HC Tracking proposal-lifecycle correction plan in this Laravel 13 / Livewire 4 / Flux UI 2 repository. Prompt A (contract frozen at CP3, end of day 9) delivered the monthly report backend: tables, enums, `MonthlyReportPolicy`, the Actions in `app/Actions/MonthlyReports/` and a periods helper. Your outcome: the **Monthly Reports** page (`/monthly-reports`: "my reports" for project managers, and a MEAL queue built from `MealCoverage::projectsCoveredBy()`) and the **Monthly Report** page (`/monthly-reports/{monthlyReport}`: the narrative questions, one keyboard-friendly grid of template items — indicator in Arabic and English, approved target, value inputs, note and typed evidence files per line — a files summary, the month's activities, and the Submit / Confirm / Return / Request Reopen / Decide / Mark as Seen / Refresh Lines actions), plus routes, Sidebar items, notification links and the read-only treatment of monthly lines on the generic **Reports** pages. In the corrected story the center manager sends the month in one screen and MEAL validates it without editing; Task 18 (**Monthly Matrix**) and UAT (Task 28) read the result.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins).
2. The plan `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`: section `### Task 17` in full (Arabic and authoritative; UI names **Monthly Reports**, **Monthly Report**, **Refresh Lines**, **Mark as Seen** are exact), especially "Implementation notes" (fixed columns, keyboard navigation, draft autosave with `wire:model.blur`); its `#### تعديلات المراجعة 2 (2026-10-08)` block (auto-generated months, sequencing on submit, no submit before the month's last working day); section 0 «قواعد العمل بين الفريقين»; section 5 rows "BOLA/IDOR في Livewire" and "الحفظ الجزئي"; section 8 "إمكانية الوصول".
3. The decision log `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists (D-07, D-08, D-09, D-12, D-29 and the MEAL template fields).
4. Prompt A's final report (the CP3 contract). If you do not have it, read the Actions, `MonthlyReportPolicy`, the enums and `app/Support/MonthlyReportPeriods.php` (or the helper A named) before planning.
5. Rule files: `.ai/rules/pages.md` (page header, `wire:model` modals, eye button, resolved filter values), `.ai/rules/ui.md`, `.ai/rules/views.md` (no `@php(...)`, `input:class`, red text tokens, Graphite dark mode), `.ai/rules/views-enums.md`, `.ai/rules/views-pages.md` (slug routes, pass models to `route()`), `.ai/rules/reports.md`, `.ai/rules/resultsindicatorstargetsreports.md`, `.ai/rules/indicators.md` (load once, flat query count), `.ai/rules/components-ui.md`, `.ai/rules/app.md`, `.ai/rules/components-navigation.md`, `.ai/rules/navigation.md`, `.ai/rules/navigation-css.md` (logical classes, RTL icons), `.ai/rules/routes.md`, `.ai/rules/css.md`, `.ai/rules/tests.md`. Then run `grep -rin 'report\|modal\|grid\|rtl' .ai/rules`.
6. Existing code to study first: `resources/views/pages/reports/⚡index.blade.php` and `⚡show.blade.php` (filters, file list and upload, status actions, `ManagesReportForm`), `resources/views/components/reports/form-modal.blade.php`, `resources/views/pages/indicators/⚡index.blade.php` (load-once computed reads, hand-built paginator), `resources/views/components/ui/{page-header,detail-header,header-filter-select,enum-badge,panel,empty-state,button,form-textarea,data-table}.blade.php`, `resources/views/components/ui/targets-grid.blade.php` if Task 13 created it (reuse its keyboard navigation), `resources/views/components/navigation/sidebar.blade.php` (Monitoring & Evaluation group), `routes/web.php` (`reports.*` routes), `app/Notifications/SubjectLinks.php`, `app/Http/Controllers/ReportFileDownloadController.php`, `app/Rules/SecureUpload.php`. Tests: `tests/Feature/ReportDirectoryTest.php`, `tests/Feature/ReportDetailTest.php`, `tests/Feature/ReportFileCrudTest.php`, `tests/Feature/NavigationTest.php`, `tests/Feature/AccessControl/{AuthorizationCoverageTest,BrowserCallableMethodsTest}.php`, `tests/Feature/IndicatorDirectoryTest.php` (query-count pattern).
7. Skills to activate: `livewire-development`, `fluxui-development`, `tailwindcss-development`, `laravel-security`, `testing-best-practices`.
8. Use Laravel Boost `search-docs` for Livewire 4 (`wire:model.blur`, `#[Url]`, `#[Computed]`, `#[Locked]`, file uploads, redirects with `navigate`) and Flux components before using them.

## Preconditions to verify
- Prompt A's contract is frozen at CP3 and its schema, listener and Actions are merged (B2 starts on day 9; if an Action is still in review, build against its signed signature and rebase after merge): `monthly_reports`, `monthly_report_reopen_requests`, `reports.monthly_report_id`, `MonthlyReportStatus`, `MonthlyReportPolicy` and the Actions `OpenMonthlyReport`, `SaveMonthlyReportLines`, `SubmitMonthlyReport`, `ValidateMonthlyReport`, `ReturnMonthlyReport`, `RequestMonthlyReportReopen`, `DecideMonthlyReportReopen`, `MarkMonthlyReportSeen`, `RefreshMonthlyReportLines`, `DeleteMonthlyReport` exist with the signatures in A's report.
- Its tests pass: `php artisan test --compact tests/Feature/MonthlyReportWorkflowTest.php`.
- No `monthly-reports.*` route exists yet.
If a check fails, stop and report; do not change A's contract (Actions, policy, schema) without B1's and Dev 1's approval — it is frozen at CP3.

## Revision 2 changes (2026-10-08)
- Owner is now **B2 (Team B)**, reviewed by Dev 2; days 9–11 (Thu 2026-10-22 → Mon 2026-10-26), against A's contract frozen at **CP3 (end of day 9)**. UI ready for the CP4 E2E run (end of day 11); feature freeze CP5 (end of day 12).
- Months are **generated automatically** at activation (D-29), so the list shows every month of the period as an existing draft: there is no "not opened / Open" state for a generated month — the action is "Fill" (open the existing report; lines are prepared on first open if `monthly-reports:prepare` has not run yet).
- Sequencing is on **submit**: the Submit button is disabled with the helper's reason while the previous required month is unsubmitted, or before the month's last working day. Months that have not started are shown as "upcoming", never "overdue".
- If the project owner's current HR position is not at the project's center (Task 30), show A's warning on the report header; do not block.
- Work under the inter-team rules (plan section 0 «قواعد العمل بين الفريقين»): do not change Phase 1 tables (`proposals`, `projects`, `donor_proposal`, `proposal_status_notes`) or the proposal state machine — request any such change from Dev 1 so it is recorded in the plan; integrate with Phase 1 by listening to its events (`ProposalStatusChanged`, `ProjectActivated`, `ProjectPeriodChanged`, `FundingAgreementRecorded`, dispatched after commit) instead of calling Phase 1 Actions directly; every PR is reviewed by Dev 2 (UI) within one working day and merges only with this task's suite green; no production access — seed or masked data only.
- Notifications reach MEAL + General Management through A's events registered in the Task 21 `EventCatalog`; this prompt only adds `SubjectLinks` and pages, no new events.

## Revision 3 changes (2026-10-09)
Source: `Stratigy.pptx` (strategy 2026–2030) and `micro logic.xlsx` (the real Micro program logframe, urban and agricultural tracks). Read plan section 1.0 facts 10–12 and the «تعديلات المراجعة 3» subsection of this task. Where this block conflicts with text further down this prompt, this block wins.
- Line rows follow Prompt A's rule: only indicators due this month and collected by the project team appear. Show the indicator's `frequency_note` under its name so the manager understands why it appears this month.
- `percentage`/`average` lines with a numerator label render two inputs (labels from `numerator_label` / `denominator_label`) and a read-only computed value; counts keep the single value input. Add a **Not measured this month** toggle for ratio/average lines only.
- Add the same numerator/denominator inputs to the existing per-target report form (`App\Livewire\Concerns\ManagesReportForm`) under the same condition, because MEAL's periodic reports go through it (+0.5 day, day 11).
- Extra tests: a ratio line shows two inputs and saves the computed value; the toggle saves NULL; the existing report form accepts numerator/denominator for a ratio indicator.

## Revision 4 changes (2026-10-12)
Source: plan header paragraph «المراجعة 4», section 1.0 facts 13–17, the «تعديلات المراجعة 4» and «تعديلات المراجعة 7» subsections of this task, and `### Task 33`. Where this block conflicts with the Revision 2/3 blocks or the base sections, it wins.
- **The Monthly Report page has sections in this order:** (1) narrative questions of the month (monthly ones, plus quarterly ones in the last month of a quarter; required ones marked); (2) the indicator grid: indicator (code, name in the user's language, secondary language below), the **approved target from the template**, value input(s), note, and the required evidence types with «مرفق» / «ناقص» badges; (3) a files summary grouped by evidence type; (4) "this month's activities by calendar" (plan Revision 7, statuses from Revision 8): activity, its output, proposal, status as a segmented control, the dates and reason each status requires, planned weeks (Revision 9), and required files with attached/missing badges.
- Lines come from A's `monthly_report_lines` (confirmed, due template items), not from `plan_indicators`. A month whose project template has no confirmed items yet shows one clear state: «بانتظار تأكيد نموذج التقرير من MEAL» with no inputs.
- Every upload on a line or activity asks for its evidence type (`evidence_types`, active only); submit lists every missing mandatory type.
- Lines with `entered_by = meal` render read-only for the manager with a «يحتسبه MEAL» badge; MEAL fills them on the same page during review.
- `split` items (Revision 5) render one sub-row per donor (donor, proposal, its target) and a read-only automatic total; disaggregated items (Revision 8) expand to male / of whom with disability / female / of whom with disability / total (two rows for numerator and denominator on ratios); fixed-denominator percentages (Revision 9) show one count input with «من أصل {n} مستفيدًا في المقترح» and the computed percentage.
- Also part of this prompt: the confirmed activity status grid "activity × months" on the **Project** page with a CSV export (Revision 8).
- Tests: the four sections render in order; an empty-template month shows the waiting state and no inputs; upload without an evidence type is refused; submit lists missing mandatory types; a MEAL line has no input for the manager.

## Revision 5 changes (2026-10-12)
Source: management note on co-funded indicators, recorded as **D-46** (amends D-40). Read plan section 1.0 facts 14 and 18, decision row D-46, and the «تعديلات المراجعة 4» and «تعديلات المراجعة 5» subsections of this task in the plan. Where this block conflicts with text further down this prompt, this block wins.
- An indicator shared by several donors in one center follows its program's mode, `programs.donor_attribution` (`App\Enums\DonorAttribution`: `shared`, `split`; default `shared`). `shared` (e.g. DESC: 30 students, one report, 30 for each donor) = D-40. `split` (e.g. Micro: 10 beneficiaries = 6 for donor 1 + 4 for donor 2, a separate report per donor): the center manager enters each donor's share monthly, each donor's mirror carries only its share, and the center's achievement is the sum. A beneficiary has exactly one funder, so there is no separate total field and no reconciliation check.
- New table `monthly_report_line_shares` (`monthly_report_line_id` cascade, `plan_indicator_id` restrict, `value`, `numerator`, `denominator`, `not_measured`, unique on the pair). `plan_indicator → proposal → donor` identifies the donor; the manager never picks a donor and `plan_indicator_id` is never accepted from the request.
- Shares are created with the line for each linked `plan_indicator` whose proposal covers the month (D-41); later-linked proposals add shares to draft reports only.
- The line's `value` / `numerator` / `denominator` are computed server-side as the sums of its shares. Mirrors for `split` items point at the share (`reports.monthly_report_line_share_id`, nullable) and carry its numbers; `achievedValue()` is unchanged.
- Submit: every count share required (zero allowed); ratio shares need numerator and denominator or Not measured. Evidence files stay on the line. UI: a sub-row per donor (donor, proposal, target) and an automatic total.
- Tests: 6 + 4 → line 10, mirrors 6 and 4; ratio shares 9/10 and 1/2 → line 10/12; client-sent line value ignored; empty share blocks submit; `shared` items create no shares.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-05, D-07, D-09, D-12, D-43, D-48, D-49, D-50, D-51, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
These changes replace the Revision 7 activity statuses (`ActivityExecution`, `rescheduled_to`) and their validation.
- D-07: after submit the report is locked for the manager; MEAL either **Confirm** (counts in achievement) or **Return** with a mandatory note. D-05: reports exist for every project month, including funding gaps (institutional items and narrative only). D-04: the first report is the month of `actual_date_begin`. D-12: submit notifications go to `MealCoverage::responsibleFor()`.
- ~~D-09/D-09a: when the slot is empty, the project's MEAL officer decides~~ — superseded by Revision 9 below (Public Relations Manager decides while the slot is vacant).
- D-48: items with `entered_by = meal` are read-only for the manager, do not block submit, and block **Confirm** until MEAL fills them or marks «لم يُقس هذا الشهر» (D-48a, confirmed in Revision 9: during review, before confirmation).
- **D-50 disaggregation (decided):** table `monthly_report_line_breakdowns` (`monthly_report_line_id` cascade, `monthly_report_line_share_id` nullable cascade, `gender` `App\Enums\Gender` male|female, `value`, `numerator`, `denominator`, `disabled_value`, `disabled_numerator`, `disabled_denominator`; unique on line+share+gender). For a disaggregated item the manager enters, per gender, the count and of whom persons with disabilities — or numerator and denominator and their disability parts for ratios/averages. Share (`split`) = sum of its breakdown; line = sum of shares (or of the breakdown for `shared`). All totals server-side; never from the request. Mirrors in `reports` carry totals only, so `achievedValue()` is unchanged.
  - Validation: disabled ≤ that gender's figure («عدد ذوي الإعاقة أكبر من عدد الذكور/الإناث»), same for numerator and denominator; numerator ≤ denominator; ≥ 0; both genders required on submit (zero allowed); Not measured on the share or line exempts the breakdown.
  - Security: load via `$line->breakdowns()`; reject a share id that does not belong to the line.
- **D-51 activity statuses (decided):** `App\Enums\ActivityStatus`: `not_started`, `started`, `ongoing`, `suspended`, `cancelled`, `completed`, `conducted` (definitions from the Children University calendar sheet). Required inputs: Not Started → reason (min 10); Started → start date; Ongoing → nothing; Suspended → reason; Cancelled → reason, final; Completed → completion date + required evidence, final; Conducted (events only) → execution date + required evidence. A `continuous` activity cannot be Conducted; an `event` accepts only Not Started, Conducted, Cancelled. Completed/Cancelled change only through report reopen.
  - Carry-over: an activity appears in month M if scheduled in M **or** its last confirmed status is Not Started, Started, Ongoing or Suspended (badge «مستمر من {الشهر}» / «متأخر من {الشهر}»). No `rescheduled_to`, no monthly rescheduling.
  - On **Confirm**, `project_activity_statuses.status` stores the same value with `started_on` / `completed_on`; Return writes nothing; reopen restores the previous state from the activity log.
- D-43a: the report reads the project template's questions (`monthly_report_template_questions`, Task 33), not the bank; answers keep the question text.
- Tests to add: breakdown 10 (2) + 17 (1) → line 27, mirror 27; disabled > gender rejected; numerator > denominator rejected; split + breakdown sums; Ongoing carries to next month without a schedule row; Completed stops appearing; event rejects Ongoing; Not Started without reason rejected; MEAL line blocks Confirm but not submit; manager editing a MEAL line → 403.

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
- **D-25 (count entry for percentages):** for a percentage item with `denominator_basis = fixed` the manager enters **only the count** (numerator, labelled by `numerator_label`). The denominator is the template's `fixed_denominator`, shown read-only («من أصل {n} مستفيدًا في المقترح»); the server computes the percentage and ignores any `denominator` in the request. Mirrors in `reports` store numerator and the fixed denominator, so `pooledRatioSql()` is unchanged. `split` (D-46): each share's denominator = that donor's proposal beneficiaries for this project. `entered` items (MEAL pre/post tests, surveys) keep numerator + denominator as in Revision 3.
- With D-50 disaggregation, counts per gender/disability are entered and summed on the server; the percentage is computed for the total only (the denominator is not disaggregated).
- Validation: numerator ≤ fixed denominator — «العدد أكبر من عدد مستفيدي المشروع في المقترح ({n})»; values ≥ 0; required on submit (zero allowed).
- **D-09a:** reopen decisions by the MEAL Manager slot holder, and by Public Relations Manager when the slot is empty (Task 02). No project-MEAL-officer fallback.
- **D-12a:** route to `MealCoverage::responsibleFor($project)` (project officer → governorate → MEAL Manager); MEAL queue from `projectsCoveredBy()`.
- **D-49b:** the activities section shows planned weeks when set («الأسبوع 2 و3»). **D-48a** confirmed (no longer open).
- Tests: fixed item 36 of 180 → 20% and mirror 36/180; numerator above denominator rejected; request `denominator` ignored for fixed items; split shares use their own proposal's denominator; an officer from another governorate assigned to the project sees and confirms the report while the governorate officer does not see it. Effort +0.5 day (B1).

## Scope
### In scope
- Routes in `routes/web.php`: `Route::livewire('monthly-reports', 'pages::monthly-reports.index')->can('reports.view')->name('monthly-reports.index')` and `monthly-reports/{monthlyReport}` -> `pages::monthly-reports.show`, name `monthly-reports.show` (slug binding), plus the policy `view` check in `mount()`.
- Create `resources/views/pages/monthly-reports/⚡index.blade.php`: tab/segment "My reports" (projects from `ownedProjectIds()` and supervisees if present (HR direct manager + `supervisions`, Task 23), their generated months with state: upcoming, draft, submitted, validated, returned, reopen pending; due date and overdue marker (never on upcoming months); **Fill** opens the existing report; the helper's can-submit reason shown when Submit is blocked) and "To validate" (submitted reports in the user's governorates; filters by city, project, month, status via `x-ui.header-filter-select`; MEAL fallback sees all).
- Create `resources/views/pages/monthly-reports/⚡show.blade.php`: `x-ui.detail-header` with project, month (`translatedFormat`), status badge, preparer, submitted/validated meta, seen flag; the lines grid; the narrative section; action buttons by ability; modals for submit confirmation (lists empty lines), return (reason), reopen request (reason), decision (grant/deny + note), delete draft; reopen request history.
- Grid component (e.g. `resources/views/components/monthly-reports/lines-grid.blade.php`, or reuse Task 13's grid): fixed columns — code (LTR-isolated), indicator name in the user's language with the other language as a secondary line, target (value + period), value input, note input, files (count, upload, download); arrow-key / Enter navigation in Alpine; each input labelled.
- Autosave in draft: `wire:model.blur` on value, note and narrative fields; each blur calls `SaveMonthlyReportLines` for that line only and shows the line's error inline.
- Line files: upload, rename/replace and delete through the existing `SaveReportFile` / `DeleteReportFile` (authorized against the monthly report by A), validation with `SecureUpload`, download through `reports.files.download`.
- Generic **Reports** pages: a monthly line shows a "Monthly report" chip linking to its report and no edit, delete, submit, approve or return controls.
- Sidebar: "Monthly Reports" in the Monitoring & Evaluation group under `reports.view`; `SubjectLinks` entry `'monthly_report' => showRoute('monthly-reports.show', MonthlyReport::class, $id)`; enum-badge tones for `MonthlyReportStatus` (explicit arms).
- Lang keys in `lang/{en,ar}/monthly_reports.php`, `titles.php` (`monthly_reports`, `monthly_report`), `navigation.php`; stable `data-*` hooks for the Task 26 tour.
### Out of scope
Schema, Actions, policy and alert rules (Prompt A); **Monthly Matrix** (Task 18); monthly summary (Task 21); supervision management (Task 23); tour copy and the user guide (Task 26).

## Business rules
- Inputs exist only for a preparer while the report is a draft; submitted and validated reports render read-only for everyone. MEAL never sees value inputs.
- Submit is one action for the whole report; the confirm modal lists lines still empty (zero is a value). Submit is disabled, with the reason, before the month's last working day or while the previous required month is unsubmitted (sequencing on submit, D-08). After a successful submit the page re-renders locked.
- Validate and Return appear only to users who pass `MonthlyReportPolicy::validate` / `return`; Return requires a reason of at least 10 characters.
- **Request Reopen** appears on validated reports for the preparer side when no request is pending; **Decide** appears to `reports.reopen` holders while one is pending.
- **Refresh Lines** appears in draft only and reports how many lines were added.
- **Mark as Seen** is independent of status and shows who/when once set.
- Figures shown as "achieved" come only from `PlanIndicator::achievedValue()` / `percentAchieved()`; never compute them in the view.
- No money on these pages.

## Security and authorization
- Every public method that writes calls the matching Action, which authorizes; the component also checks the policy before opening a modal (UI only). `AuthorizationCoverageTest` must stay green.
- Hold only ids in Livewire state and mark them `#[Locked]`; never hold statuses or the preparer as trusted state. Line ids sent from the grid are re-checked by the Action (`$report->lines()->whereKey()`); show its error if one is foreign.
- View helpers that return data are `protected` or `#[Computed]` (see `BrowserCallableMethodsTest`), never public methods returning records.
- Links to projects, proposals or donors render only with the target page's view permission ("Hide money, keep names").
- A user who loses a permission mid-session gets the Action's 403 and a toast, not a broken page.

## Validation
- Client-side hints only mirror A's rules; the server messages from A (`lang/*/monthly_reports.php`) are displayed per field: value required on submit (zero allowed), numeric, min 0, max 100 for percentage lines; note ≤ 2000; reason ≥ 10; deny note required.
- File uploads: `SecureUpload`, 5MB, `FileType` extensions, the same messages as the report detail page.
- Any new UI string goes to `lang/en` and `lang/ar` in the module group; Arabic plurals use explicit ranges.

## Edge cases and concurrency
- Two tabs open the same month: the report already exists (generated); both open it, and line preparation is idempotent.
- The report is submitted or returned in another session while the grid is open: the next blur gets the locked or stale message; show a toast and re-render from the server (unset computed state).
- Fast typing then Submit: submit waits for pending saves (disable the button with `wire:loading` on the save targets) so no value is lost.
- A line added by **Refresh Lines** appears at its sorted position without losing focus elsewhere.
- Empty states: no generated months (project not active yet), no active targets, nothing to validate; each explains why.
- Long Arabic names and RTL: columns keep fixed widths, numbers stay LTR-isolated, arrows flip in RTL (`rtl:-scale-x-100`).

## Data integrity
- The UI never writes models directly; all writes go through A's Actions or the existing report-file Actions.
- After each write call one `forget…()` helper that unsets every computed (report, lines, files, requests, abilities) so the same request renders fresh data.
- The grid loads in a fixed number of queries (report + project, lines with plan indicator, indicator, library entry and files, reopen requests, abilities), shaped in PHP.

## Implementation guidance
- Pages are Livewire single-file components under `resources/views/pages/monthly-reports/` (create with `php artisan make:livewire` — check `--help` for the `pages::` syntax — or mirror a sibling page). `#[Title('titles.monthly_reports')]`.
- `x-ui.page-header` with a count badge, filters in the filters slot, actions in the actions slot; Flux modals bound with `wire:model` booleans (`showSubmitModal`, `showReturnModal`, `showReopenModal`, `showDecisionModal`, `showDeleteModal`), never `:open`; no `@php(...)` shorthand.
- Status is never conveyed by colour alone (badge text + icon); contrast in both themes with Graphite tokens; logical spacing (`ps-*`, `pe-*`, `text-start`).
- Keyboard grid: `role="grid"` semantics or a labelled table, `aria-label` per input (indicator name + column), Enter moves down, arrows move between cells, Escape leaves the grid.

## Tests (Pest)
Follow `.ai/rules/tests.md` (seed `RolesAndPermissionsSeeder`, roles via factories, fresh Livewire instance for each forbidden call, assert modal booleans, not ids alone).
- Create `tests/Feature/MonthlyReportPagesTest.php` (`pages::monthly-reports.index`): a center manager sees every generated month of their projects with the right states (upcoming months never overdue) and fills a month (redirect to the show page); a month blocked by the sequence can be filled but its Submit is disabled with the reason; MEAL sees submitted reports of their governorate only; fallback MEAL sees all; a Team Member sees the list without Open and gets 403 on the call.
- Create `tests/Feature/MonthlyReportPageTest.php` (`pages::monthly-reports.show`): the grid shows code, Arabic and English names, target and inputs for the preparer in draft; blur saves one line; a foreign line id shows the error and writes nothing; submit with empty lines lists them; Submit disabled before the month's last working day and while the previous month is unsubmitted (frozen time); the owner–center warning shows when the owner's HR position is at another center; after submit the inputs disappear and a save attempt shows the locked message; MEAL sees Validate and Return but no inputs; return without a 10-character reason fails; reopen request and decision flows; **Refresh Lines** adds a new target's line; line file upload and download; query count equal for 5 and 40 lines; Arabic render uses `lang/ar` labels and `dir` is correct.
- Update `tests/Feature/ReportDirectoryTest.php` and `tests/Feature/ReportDetailTest.php` (monthly chip and link, no edit/submit/approve/delete controls for lines), `tests/Feature/NavigationTest.php` (Super Admin route order with `monthly-reports.index`; if Task 11 already added `indicator-library.index`, keep both), `tests/Feature/Notifications/SubjectLinksTest.php`; keep `LocalizationTest`, `AccessControl/AuthorizationCoverageTest`, `AccessControl/BrowserCallableMethodsTest`, `ReportFileCrudTest` green.
- Run `php artisan test --compact tests/Feature/MonthlyReportPageTest.php` and each file above (or `--filter=...`).

## Acceptance criteria
- [ ] A center manager finds every month already generated, fills and submits a month in one screen (not before its last working day, and in sequence), and cannot edit it afterwards.
- [ ] The responsible MEAL officer validates or returns from the same page without any value input.
- [ ] Reopen request and decision work end to end; history is visible.
- [ ] Generic **Reports** pages never let anyone edit or move a monthly line.
- [ ] Both pages have flat query counts (tests), work by keyboard, and render correctly in Arabic RTL and English, light and dark.

## Do not
- Write models from the component or bypass A's Actions and policy.
- Compute achievement in the view, or show money.
- Bind a Flux modal with `:open`, use `@php(...)` shorthand, or use left/right classes.
- Change A's schema or signatures without approval (CP3 contract).
- Add placeholder Sidebar links or a new dependency or base folder.

## Quality bar (this task)
- **Monthly Report** page: the four sections in order with a sticky section nav on desktop; an autosave indicator ("Saving… / Saved") per line; a sticky action bar with **Submit** that opens a checklist modal (empty values, missing evidence, unanswered required questions) linking to each problem.
- Phone width: the grid collapses to one card per line (indicator, target, inputs, files) instead of a sideways-scrolling table; split and disaggregation inputs stack with their labels.
- Read-only states explain themselves («التقرير مُرسَل ومقفل», «يحتسبه MEAL», «بانتظار تأكيد نموذج التقرير من MEAL»).
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
Your final message must contain: a summary; files changed; migrations added (expected: none); tests run with pass/fail output; decisions assumed (plan-mode answers on list layout, grid component reuse, autosave granularity); follow-ups (e.g. anything in A's contract that was missing). Handoff facts: for Task 26 — the route names, page titles and the `data-*` hooks for the **Monthly Report** tour and the list intro; for Task 18 — the UI patterns (grid, filters, status markers) worth reusing in **Monthly Matrix**; for Task 28 — the end-to-end UAT path (activation generates months, fill, submit, validate, return, reopen) with the roles used in tests.
````
