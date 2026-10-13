# Task 19 — Dynamic aggregated donor reports (quarterly, semi-annual, annual, custom) and their due dates

> **المهمة:** التقارير التجميعية الديناميكية للمانح (ربعي/نصفي/سنوي/مخصص) ومواعيد تسليمها (**Donor Report Builder**، **My Reporting Duties**)
> **المطوّر:** Dev 2 (مراجعة Dev 1) · **الفريق:** A · **الأيام:** 10–12 · **الجهد:** 3 أيام · **يعتمد على:** Task 09، Task 13، Task 17 (عقد CP3: `monthly_reports` وأسطره)
> **متى تستخدمه:** بعد نقطة التكامل CP3 (نهاية اليوم 9: مخطط `monthly_reports` وحالاته وعلاقته بـ `reports` مجمّدة) ودمج Task 13. القسم المالي يحتاج Task 09 (الاتفاقية وسجل التحويل المولَّد، CP4 نهاية اليوم 11)؛ ابدأ بـ `DonorReportAggregator` في اليوم 10 وأضف القسم المالي بعد دمج 09. يُفضَّل دمج Task 18 (لمشاركة الاستعلام المجمّع)، وTask 22 (تشفير الملفات)، وكتالوج Task 21 (أحداث `donor_report.*`). تجميد الميزات نهاية اليوم 12 (CP5).
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 19 of the HC Tracking proposal-lifecycle correction plan (Laravel 13, PHP 8.5, Livewire 4 + Flux UI 2, Pest). You are on Team A (owner Dev 2, reviewed by Dev 1), days 10–12, 3 days of effort. This task stays with Team A because it touches money and donors.

Binding domain facts (plan section 1.0): center managers submit a **monthly report every month regardless of donor deadlines** (Task 17); **donor reports are aggregated and dynamic** — the Projects & Fundraising Manager picks a proposal (hence its donor and centers) and a period (quarterly, semi-annual, annual or custom), and the system aggregates the **validated** monthly report lines of that proposal's centers and indicators into one report that can be saved, exported and submitted. "Narrative ← PR Manager · Financial ← Finance Manager · a due date, an actual submission date and files · reminders" stays at the level of the donor report itself.

The outcome:
- **Donor Report Builder** page (`/donor-reports/compose`): choose proposal, `ReportingPeriod` and start month (or from/to for custom) → read-only aggregation: per indicator (target, period total, cumulative to period end, achievement %), broken down per center; narratives per center per month; links to monthly report files; a completeness warning listing months not submitted or not validated per center; a finance section (transfers received in the period incl. `fees`, net, one line per currency) **only for money-permission holders**.
- **Save as Donor Report**: freeze the aggregation into a versioned `donor_reports` snapshot; **Regenerate** creates version + 1; a submitted report never changes.
- **Due dates are entered manually per report** (D-32) and remind the responsible person; **My Reporting Duties** lists a person's reports without any money.
- Export CSV and a print view (PDF from the browser).

There is **no** `report_schedules` table, no periodic schedule generator, no **Extend Schedule** / **Stop** actions (D-32). Older drafts of this prompt had them; they contradict the revised plan and must not be built.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins) (Boost tools, `php artisan make:* --no-interaction`, no new dependencies or base folders without approval).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md` — Arabic and authoritative; English names are exact. Read: section 0 (teams, phases, inter-team rules), section 1.0, `### Task 19` in full, `### Task 17` including its "تعديلات المراجعة 2" block (`monthly_reports`, `reports.monthly_report_id`, statuses, narrative fields), `### Task 13` (targets per center), `### Task 18` (the shared grouped query), `### Task 09` including its revision block (`GenerateExpectedTransfers`, `transfers_tracking.source`, `fees`), `### Task 21` (catalog rows `donor_report.*`), section 5 rows "البيانات المالية", "التقرير التجميعي", "التصدير", "الملفات", section 7 (CP3, CP4, CP5), and section 9 rows D-07, D-21, D-22, D-25, D-31, D-32.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists — a recorded decision overrides the plan's default (D-21: final file mandatory for donor reports; D-25: percentages never summed, cumulative = latest value; D-32: no schedule generator).
4. Rules (read all): `.ai/rules/database-migrations.md`, `migrations.md`, `feature.md` (JSON columns are `utf8mb4_bin` on MariaDB), `pages-funding-agreements.md`, `support-models.md` ("Funding money has one definition"), `models.md`, `views-pages.md`, `enums.md`, `views-enums.md`, `factories.md`, `seeders.md`, `providers.md`, `alerts.md`, `recipients.md`, `views-components-notifications.md`, `funding-agreements.md`, `transfers.md`, `admin.md`, `proposalsfunding-agreementstransfersdonors.md`, `reports.md`, `resultsindicatorstargetsreports.md`, `pages.md`, `ui.md`, `views.md`, `routes.md`, `app.md`, `components-navigation.md`, `navigation.md`, `navigation-css.md`, `tests.md`. Then run `grep -rin 'donor_report\|hide money\|working day\|achievedValue\|snapshot\|CsvExport' .ai/rules`.
5. Study first: `app/Models/{DonorReport,DonorProposal,TransfersTracking,MonthlyReport,Report,PlanIndicator}.php`; `app/Support/{AgreementMoney,CsvExport,StoredFiles}.php`; `TransfersTracking::stateSql()` / `settledSql()` and the `datedWithin` scope; `app/Actions/FundingAgreements/{SaveDonorReport,MarkDonorReportSubmitted,DeleteDonorReport}.php`; `resources/views/pages/funding-agreements/⚡show.blade.php` (`reports()`, `scopedReport()`, `markReportSubmitted()`, report modal); `database/migrations/*_create_donor_reports_table.php`; `app/Notifications/Alerts/{DeadlineCatalog,DeadlineItem,AlertDispatcher,DueSoonScanner,OverdueScanner,WorkingCalendar,NotificationSettings}.php`; `app/Notifications/{RecipientFilter,SubjectLinks,EmployeeRecipients}.php`; `app/Rules/SecureUpload.php`; `app/Models/Media.php`; `app/Enums/{DonorReportType,MediaCollection}.php`; an existing protected download controller (e.g. `app/Http/Controllers/ProposalFileDownloadController.php`); the Task 18 **Monthly Matrix** query if merged; `tests/Feature/AccessControl/PolicyCoverageTest.php`; `tests/Feature/Notifications/DeadlineCatalogTest.php`; `IndicatorDirectoryTest` (constant-query pattern).
6. Activate skills: `laravel-best-practices`, `livewire-development`, `fluxui-development`, `database-migrations`, `laravel-security`, `performance-optimization`, `testing-best-practices`.
7. Use Boost `search-docs` (JSON casts, `lockForUpdate`, Livewire file uploads, Flux modal, `addMonthsNoOverflow`, streamed downloads) and `database-schema` on `donor_reports`, `donor_proposal`, `proposals`, `project_proposal`, `monthly_reports`, `reports`, `plan_indicators`, `transfers_tracking`, `media` before writing migrations.

## Preconditions to verify
- CP3 (Task 17): `monthly_reports` (`project_id`, `period` = first day of month, `status` with `validated`, narrative columns) and `reports.monthly_report_id`; validated lines are `reports.status = approved`. If missing, stop.
- Task 13: proposal targets are `plan_indicators` with `project_id` = center and a library indicator link (`is_cumulative` on `library_indicators`, Task 11).
- Task 09: `donor_proposal.proposal_id` unique, agreement only for approved proposals; `transfers_tracking` has `fees`, `fees_currency`, `net_amount_received`, `amount_received_currency`, `receive_date` (and `source` after Task 09). Missing → build everything except the finance section and list it as a follow-up.
- Task 02: permission `reports.aggregate` exists in `config/access-control.php` (defaults: Public Relations Manager, General Manager, MEAL; Super Admin only technically) and is not part of `$viewEverything`.
- Task 17/33 CP3 contract: `monthly_report_lines`, their mirrors in `reports` (`monthly_report_line_id`), `monthly_report_answers` and evidence files typed by `evidence_types`.
- `donor_reports` today: `donor_proposal_id` (NOT NULL), `title`, `type` (`DonorReportType`), `due_on`, `submitted_on`; `DonorReport` uses `RecordsActivity` and is in `modelsGuardedWithoutPolicy()`.
- Default responsible source: Task 07 `proposal_default_reviewers` setting / `ReviewerSlot` (has `finance_manager`; check for a PR Manager slot).
- Task 22: if `StoredFiles::putEncrypted()` / `stream()` exist, use them for submission files; otherwise use the current path and list encryption as a follow-up.
If a hard precondition fails, stop and report instead of improvising.

## Revision 3 changes (2026-10-09)
Source: `Stratigy.pptx` (strategy 2026–2030) and `micro logic.xlsx` (the real Micro program logframe, urban and agricultural tracks). Read plan section 1.0 facts 10–12 and the «تعديلات المراجعة 3» subsection of this task. Where this block conflicts with text further down this prompt, this block wins.
- **Source of figures:** every approved `reports` row for the proposal's targets whose `planned_date` falls in the period, **monthly lines and ordinary per-target reports alike**, so MEAL's periodic indicators (12-month follow-up, season, baseline/endline) are included. This replaces the `monthly_report_id IS NOT NULL` condition for figures; narrative and completeness still come from monthly reports.
- **Pooling (D-25):** counts sum; percentage and average = `PlanIndicator::pooledRatioSql()` across the proposal's centers, with a per-center breakdown; lines without a denominator are listed in the completeness section. Cumulative stays latest-value. This replaces "percentages are never aggregated across centers".
- Tracking-only indicators ("-" in the reference framework, `indicators.is_tracking_only`) show the aggregated value without achievement %.
- The saved snapshot stores pooled numerator and denominator, not only the percentage.
- Replace the test "percentages are not summed" with: center A 9/10 (90%) and center B 1/2 (50%) pool to 10/12 = 83.3%, not the 70% average. Add: an approved periodic MEAL report inside the period is included.

## Revision 4 changes (2026-10-12)
Source: plan header paragraph «المراجعة 4», D-40, D-42, D-43, the «تعديلات المراجعة 4» subsection of this task and `### Task 33`. Where this block conflicts with the Revision 3 block or the base sections, it wins.
- **Figures from the proposal's mirrors:** a donor's aggregated report reads the `reports` mirrors on **its own targets** only, inside its proposal's period. An item shared with another donor appears with the full value for each donor (D-40, `shared` items) and carries the footnote «هذا المؤشر مشترك مع تمويل آخر».
- **Narrative:** the answers to the monthly report questions (`monthly_report_answers`, with the question text stored at answer time) for the months in the period, plus the quarterly answers that fall in it — not fixed narrative columns. The skeleton's narrative keys therefore become question/answer pairs.
- **Evidence:** the list of supporting files grouped by evidence type, as protected links (no copies, no attachments).

## Revision 5 changes (2026-10-12)
Source: management note on co-funded indicators, recorded as **D-46** (amends D-40). Read plan section 1.0 facts 14 and 18, decision row D-46, and the «تعديلات المراجعة 4» and «تعديلات المراجعة 5» subsections of this task in the plan. Where this block conflicts with text further down this prompt, this block wins.
- An indicator shared by several donors in one center follows its program's mode, `programs.donor_attribution` (`App\Enums\DonorAttribution`: `shared`, `split`; default `shared`). `shared` (e.g. DESC: 30 students, one report, 30 for each donor) = D-40. `split` (e.g. Micro: 10 beneficiaries = 6 for donor 1 + 4 for donor 2, a separate report per donor): the center manager enters each donor's share monthly, each donor's mirror carries only its share, and the center's achievement is the sum. A beneficiary has exactly one funder, so there is no separate total field and no reconciliation check.
- No split logic in `DonorReportAggregator`: a donor's mirrors already hold its share. The footnote "shared with another funding" appears for `shared` items only.
- Test: Micro 6 + 4 over three months → donor 1 = 18, donor 2 = 12, no footnote; DESC 30 over three months → 90 for each donor, with the footnote.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-25, D-27, D-50, D-51, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- **D-50:** every disaggregated indicator shows male, of whom PwD, female, of whom PwD, total — per center and for the proposal. The snapshot stores the breakdown as numerators and denominators.
- Report header from system data (as in the Syria Cross tracker): donor contract number (external project/agreement number), implementer, project title, goal.
- **D-51:** an activities section with each activity's status at period end and its execution months.
- Self-funded proposals (internal donor, D-27a) are excluded from **Donor Report Builder**.
- **Super Admin is for developers only (D-20a).** It keeps every permission technically (`Role::booted()`), but never appears in a business default list, is never a fallback decision-maker, and is never the only account a test uses.

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
- **D-25:** proposal-level percentage for a fixed-denominator indicator = Σcenter counts ÷ Σtheir `project_proposal.target_beneficiaries`. Show each center's beneficiaries; the snapshot stores the denominator used.

## Scope
### In scope
- Enums: `App\Enums\ReportingPeriod` (`quarterly`, `semi-annual`, `annual`, `custom`) and `App\Enums\ReportAudience` (`donor`, `internal`), both with `HasTranslatedLabel` and `values()`.
- Migration on `donor_reports` (all nullable or defaulted, so existing rows stay valid): `audience` (default `donor`), `period_type`, `period_start`, `period_end`, `snapshot` (JSON), `version` (unsigned small int, default 1), `responsible_employee_id` (HR id, no FK), `notes`; index on `(donor_proposal_id, type, period_start, period_end)`; propose a unique `(donor_proposal_id, type, period_start, period_end, version)` in plan mode.
- `MediaCollection::ReportSubmission` (`report_submission`) — files as `media` rows with `mediable_type = 'donor_report'` (check the morph map), stored under `media/donor-reports/{id}` so `files:prune` still sees them.
- `App\Support\DonorReportAggregator` (read-only, constant query count): returns the aggregation array for (proposal, period, include finance).
- Actions in `app/Actions/DonorReports/` (or `FundingAgreements/` — follow sibling convention; ask in plan mode): `ComposeDonorReport` (authorize + validate + aggregate, no write), `SaveDonorReportSnapshot` (version handling), updated `MarkDonorReportSubmitted`, `SaveDonorReportFile` / `DeleteDonorReportFile`.
- `App\Policies\DonorReportPolicy`; remove `DonorReport` from `modelsGuardedWithoutPolicy()`.
- Pages: `resources/views/pages/donor-reports/⚡compose.blade.php` (`Route::livewire('donor-reports/compose', 'pages::donor-reports.compose')->name('donor-reports.compose')`), a read-only snapshot view + print view for a saved report (propose route names in plan mode), and `resources/views/pages/reporting-duties/⚡index.blade.php` (`my-reporting-duties`, `reporting-duties.index`) inside the existing `auth` group; sidebar items.
- `App\Http\Controllers\DonorReportFileDownloadController` + route `donor-reports/{donorReport}/files/{media}/download` (`donor-reports.files.download`, `throttle:files`). Monthly report files are **linked** through their own protected routes, never copied.
- CSV export of a saved snapshot via `App\Support\CsvExport` (rate-limited).
- Reminders: extend `DeadlineCatalog::donorReports()` with the responsible person (direct, permission-exempt, linked to **My Reporting Duties**), CC and fallback; extend `DeadlineItem` / `AlertDispatcher::sendForItem()` as needed.
- Funding Agreement page: list saved donor reports with audience, period, version, responsible, due/submitted; entry button to the builder.
- Lang keys in `lang/{en,ar}/` for a new `donor_reports.php` group, `reporting_duties.php`, `enums.php`, `titles.php`, `navigation.php`.
### Out of scope
- Periodic schedules or due-date generators (D-32). Monthly reports themselves (Task 17), **Monthly Matrix** (Task 18), the notification catalog and digests (Task 21 — you only emit the events), encryption mechanism (Task 22), Word document generation (plan section 0: out of scope), `AgreementMoney`/`Tranches` changes.

## Business rules
- Period inside the grant (`actual_date_begin`…`actual_date_end`): quarterly = 3 months from the start month, semi-annual = 6, annual = 12, all clipped at the grant end; custom = `startMonth`…`endMonth`. Months are calendar months (`addMonthsNoOverflow` from the anchor).
- **Figures from validated lines only** (D-07): approved `reports` rows — *the source condition is replaced by Revisions 3 and 4 (mirrors on the proposal's targets, monthly and periodic alike)* — using the unified achievement definition. Lines awaiting validation appear in the completeness section and never in totals.
- ~~Percentages are never summed across centers~~ — replaced by Revision 3 (pooled Σnumerator ÷ Σdenominator through `pooledRatioSql()`) and Revision 9 (fixed denominators).
- Cumulative indicators (`library_indicators.is_cumulative`): "cumulative to period end" = latest validated value, not a sum (D-25). If this conflicts with `PlanIndicator::achievedValue()`, raise it in plan mode instead of writing a second definition.
- Centers: those linked to the proposal; a center linked mid-period appears from the month it was linked (verify a pivot timestamp exists; otherwise ask). Indicators removed from the framework after reports exist show in a "historical indicators" section with their recorded values.
- Completeness: per center, list months in the period that are not submitted or not validated, shown as a clear warning at the top; a period with no validated data shows «لا بيانات متحقق منها في هذه الفترة» and can still be saved.
- Finance section: transfers of the proposal's agreement received in the period (`receive_date` in range), with amount received, `fees` (+ `fees_currency`), net, one line per currency, through `AgreementMoney` / `TransfersTracking::stateSql()`; no local money formulas.
- Snapshot: `snapshot` JSON stores `schema_version`, `generated_at`, `generated_by`, proposal/donor/center names, period, indicator rows, per-center breakdown, narratives, file references (media ids and owners, not copies), completeness, and the finance section **only when the generator held `funding-agreements.view`**. A saved report never changes when monthly data changes later.
- Versions: **Regenerate** on an unsubmitted report creates a new row with `version + 1` (same agreement, type, period); concurrent generation is allowed and yields two versions with visible numbers. A submitted report is never regenerated or edited.
- Saving requires the proposal's agreement (`donor_reports.donor_proposal_id` is NOT NULL); without one the builder previews but disables **Save** with a message — confirm in plan mode.
- Due date: `due_on` entered manually per report as the donor requests (D-32); reminders before and after the date in working days, thresholds from `NotificationSettings`.
- Default responsible: narrative → PR Manager, financial → Finance Manager; editable. Ask whether to add a PR Manager slot to **Default Proposal Reviewers** or read the single active holder of role `Public Relations Manager`; do not invent a slot silently.
- Submission (**Mark as Submitted**): `submitted_on` ≤ today; `audience = donor` requires ≥ 1 `report_submission` file (D-21); `internal` may have none; a submitted report cannot be submitted again.
- Events for the Task 21 catalog: `donor_report.saved`, `donor_report.submitted` (after commit); `.due` / `.overdue` come from the deadline scanners.

## Security and authorization
- Compose and save: `reports.aggregate` **and** `view` on the proposal, via `Gate::authorize` inside the actions; every public Livewire write method authorizes itself (`AuthorizationCoverageTest`); view helpers stay `protected` (`BrowserCallableMethodsTest`).
- Finance section: queried **only** for holders of `funding-agreements.view`; for others the query is skipped server-side and nothing is written into the snapshot. When a saved snapshot is viewed, the finance block renders only for `funding-agreements.view` holders.
- `DonorReportPolicy`: `view` = `funding-agreements.view` or `reports.aggregate` or responsible (`responsible_employee_id` = `$user->employee_id`, both non-null); `submit` and file upload/delete = responsible or `funding-agreements.update`; `downloadFile` = responsible or `funding-agreements.view`; `regenerate` = `reports.aggregate` and not submitted; `delete` = `funding-agreements.update` and not submitted.
- Object level: the proposal id is re-loaded and checked on the server (approved and visible); saved reports load through the policy; files through `$donorReport->media()->whereKey($mediaId)`; a tampered id gets 403/404 with no write.
- **My Reporting Duties**: only title, type, audience, period, version, due/submitted dates, donor name and proposal name (plain text). No amounts, currencies, agreement files, or links to `funding-agreements.show` / `proposals.show` / `donors.show` without that page's permission. Never grant `funding-agreements.view` to responsibles as a shortcut.
- Export and print use the same permission and the same snapshot (no live re-query), rate-limited; notifications carry no amounts.
- Activity Log (`activities`): save, regenerate, submit, file add/remove.

## Validation
Messages in `lang/en/donor_reports.php` and `lang/ar/donor_reports.php`:
- `proposalId`: required, exists, approved and visible — «اختر مقترحًا معتمدًا».
- `periodType`: required, Enum(`ReportingPeriod`). `startMonth`: required, `date_format:Y-m`, inside the grant — «الفترة خارج مدة المنحة». Custom: `endMonth` required, `date_format:Y-m`, ≥ `startMonth`, inside the grant.
- `type`: required, Enum(`DonorReportType`). `audience`: Enum(`ReportAudience`).
- `dueOn`: nullable, date. `responsibleEmployeeId`: nullable, `Rule::exists(HrEmployee::class, 'id')->where('is_active', true)` — «اختر موظفًا فعّالًا». `notes`: nullable, max:2000.
- `submittedOn`: required, date, before_or_equal:today — «تاريخ التسليم لا يكون في المستقبل».
- `submissionFiles.*`: `file`, `max:5120`, extensions from `FileType::values()`, `new SecureUpload`; donor report without a file → «أرفق ملف التقرير قبل تسليمه».

## Edge cases and concurrency
- Double submit or two people submitting: transaction + `lockForUpdate` on the report, re-check `submitted_on IS NULL`; the second gets «سُلِّم هذا التقرير بالفعل» and writes nothing.
- Concurrent save of the same period: lock the agreement row while computing `version = max + 1`, or rely on the unique index and retry once.
- Monthly report reopened (Task 17) after a snapshot was saved: the snapshot stays frozen; the builder shows the difference only when regenerated.
- Grant period changed (`ProjectPeriodChanged` / re-approval): existing snapshots stay; periods outside the new grant are refused for new compositions.
- Responsible inactive in HR or without a login: the slot holder becomes direct; both pages show a warning.
- Permission revoked between page load and action: the action re-authorizes and refuses.
- Partial failure after files were written: delete them (`StoredFiles::forget`) so no orphan file or row remains.
- Large proposals (10 centers × 12 months): constant query count; no N+1 on centers (HR names loaded in one batched lookup).

## Data integrity
- `DonorReportAggregator` is read-only and shares its grouped query with **Monthly Matrix** (Task 18) — one definition, grouped by `plan_indicator_id`, `project_id`, month. If Task 18 is not merged, put the query in the aggregator and tell the Task 18 owner to reuse it.
- New columns are nullable or defaulted; mirror the `audience` and `version` defaults in `DonorReport::$attributes` (strict-mode trap). JSON column collation per `feature.md`; tables `utf8mb4_unicode_ci` on InnoDB DYNAMIC (`DatabaseEncodingTest`).
- No FK to HR. `down()` drops indexes, then columns.
- Saving and submitting run in one transaction each; events and notifications after commit.

## Implementation guidance
- `declare(strict_types=1);`, `#[Fillable([...])]`, `casts()` (`snapshot` → `array`, dates, enums), CarbonImmutable, lower-case enum values, `php artisan make:* --no-interaction`.
- Skeleton:
```php
final class DonorReportAggregator
{
    /**
     * @return array{period: array{start: string, end: string, months: list<string>}, indicators: list<array{plan_indicator_ids: list<int>, name_en: string, name_ar: string, measure: string, is_cumulative: bool, target: float|null, period_total: float|null, cumulative: float|null, percent: float|null, centers: list<array{project_id: int, target: float|null, value: float|null, percent: float|null}>}>, narratives: list<array{project_id: int, month: string, executive_summary: string|null, key_achievements: string|null, challenges: string|null, next_period_plans: string|null}>, files: list<array{media_id: int, monthly_report_id: int, name: string}>, completeness: list<array{project_id: int, missing: list<string>, unvalidated: list<string>}>, finance: list<array{currency: string, received: float, fees: float, net: float}>|null}
     */
    public function aggregate(Proposal $proposal, CarbonImmutable $start, CarbonImmutable $end, bool $includeFinance): array {}
}
```
- Flux modals bound with `wire:model` booleans, never `:open`. Pages use `x-ui.page-header`, `x-ui.header-filter-select`, `x-ui.employee-chip`, the cached employee picker, numbers in `dir="ltr"` spans, logical Tailwind classes, all text through `__()`.
- Narrative comes from `monthly_report_answers` (Revision 4): render question/answer pairs per center and month; the old fixed column names (`executive_summary`, …) no longer exist.
- Print view: a clean Blade layout for the browser's print-to-PDF; no PDF library.
- Sidebar: **Donor Report Builder** for `reports.aggregate`; **My Reporting Duties** only when the user is responsible for at least one report (one memoized exists query) or holds `funding-agreements.update`.
- After merging, record the new `donor_reports` columns and the "validated lines only, snapshot frozen" rule with Boost `record-rule` (glob `{database/migrations/**,app/Models/DonorReport.php,resources/views/pages/funding-agreements/**,resources/views/pages/donor-reports/**}`).

## Tests (Pest)
- `tests/Feature/DonorReportAggregatorTest.php`: quarterly/semi-annual/annual/custom produce the right months and clip at the grant end; totals use validated lines only (submitted-but-not-validated excluded and listed as incomplete); percentages not summed across centers; cumulative = latest value; per-center breakdown; center linked mid-period appears from its month; historical indicator section; constant query count with 10 centers × 12 months.
- `tests/Feature/DonorReportBuilderTest.php`: user without `reports.aggregate` → 403 (fresh Livewire instance per forbidden call); invisible or unapproved proposal refused; period outside grant refused; user without `funding-agreements.view` sees no amounts in the HTML and the saved `snapshot` has no finance key; finance holder sees received/fees/net per currency; save creates version 1, regenerate version 2; the snapshot does not change after a monthly report is reopened and edited; submitted report cannot be regenerated.
- `tests/Feature/DonorReportSubmissionTest.php`: responsible without money permission submits own report with a file; donor report without a file refused; internal without file accepted; future date refused; second submit writes nothing; another employee 403; BOLA on report id and media id.
- `tests/Feature/ReportingDutiesPageTest.php`: lists only my reports; no amounts, currencies or agreement links for a user without `funding-agreements.view`; that user gets 403 on `funding-agreements.show`; Arabic render uses lang keys.
- `tests/Feature/Notifications/DeadlineCatalogTest.php`: reminder reaches the responsible even without `funding-agreements.view`, links to **My Reporting Duties**; CC and fallback; ledger stage includes `due_on`; freeze time with `$this->travelTo()`.
- CSV export: same permission, no finance columns for non-holders.
- Update `PolicyCoverageTest`, `MorphMapTest` if touched, `FundingAgreementTest`; run `LocalizationTest`, `DatabaseEncodingTest`, `AccessControl/AuthorizationCoverageTest`, `NavigationTest`.
- Follow `.ai/rules/tests.md` (id-returning helpers, `DB::table()` reads for schema assertions, `RolesAndPermissionsSeeder` + `User::factory()->role(...)`). Run `php artisan test --compact tests/Feature/DonorReportAggregatorTest.php`, then `--filter=...` per change.

## Acceptance criteria
- [ ] The Projects & Fundraising Manager picks a proposal and a quarterly period and gets, on one screen, a report aggregated from the centers' validated monthly reports, then saves, exports and submits it.
- [ ] No center manager prepares anything donor-specific.
- [ ] Unvalidated months are listed as incomplete and never counted.
- [ ] Users without money permissions never see amounts — not in HTML, snapshot, export or notifications.
- [ ] Saved snapshots are versioned and frozen; submitted reports never change; a donor report cannot be submitted without a file.
- [ ] Reminders reach the responsible in working days; all listed tests pass; Pint clean.

## Do not
- Build `report_schedules`, a schedule generator, or any automatic periodic due dates (D-32).
- Compute a second achievement definition, sum percentages, or sum cumulative indicators.
- Copy monthly report files into the donor report.
- Query or store finance data for users without `funding-agreements.view`; rely on `@can` alone.
- Write money formulas outside `AgreementMoney` / `TransfersTracking`.
- Change Phase 1 tables or the proposal state machine.
- Add a PDF/Word library or any dependency.

## Quality bar (this task)
- **Donor Report Builder** as a short flow (proposal → period → preview → save); completeness warnings at the top; an indicator table with expandable per-center rows; the finance section visually separate and present only for money holders; a clean print view (no navigation, sensible page breaks).
- **My Reporting Duties**: due and overdue badges, links to the report, no money.
- Everything in `quality-bar.md` applies. Before reporting, open every new or changed screen in the browser (Arabic RTL and English, light and dark, desktop and 375 px), run `vendor/bin/phpstan analyse` on the touched paths, and list both in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions (action folder, snapshot view route, save without agreement, PR Manager slot, center-linked-mid-period source, cumulative vs `achievedValue()`); wait for approval.
- Implement in small steps (aggregator → builder → snapshot → submission → duties/reminders → export); after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside Task 19's scope; note anything else as a follow-up.

## Report back
Your final message must contain: a short summary; files created/changed; migrations added (names, up/down behaviour); tests run with their pass/fail output; decisions assumed (D-07, D-21, D-25, D-32, PR Manager slot, save-without-agreement, version uniqueness); follow-ups (finance section or encryption if Tasks 09/22 were not merged, shared query with Task 18). Handoff facts: for Task 21 — the `donor_report.saved` / `.submitted` events, the `DeadlineCatalog` stages for `.due` / `.overdue`, and `responsible_employee_id` for the monthly digest (money only for `funding-agreements.view` holders); for Task 26 — page and button names for tours and the guide; for Task 28 — a UAT scenario for the Projects & Fundraising Manager (compose quarterly, save, export, submit).
````
