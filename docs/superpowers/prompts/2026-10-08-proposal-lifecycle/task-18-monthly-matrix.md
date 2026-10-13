# Task 18 — Monthly Matrix (indicators × months)

> **المهمة:** **Monthly Matrix** (مؤشرات × أشهر)
> **المطوّر:** B3 (مراجعة Dev 2) · **الفريق:** B · **الأيام:** 11–12 (الاثنين 2026-10-26 ← الثلاثاء 2026-10-27) · **الجهد:** يومان · **يعتمد على:** Task 13، Task 17
> **متى تستخدمه:** بعد تجميد عقد Task 17 في CP3 (نهاية اليوم 9) ودمج backend التقرير الشهري، وبعد Task 13 (المستهدفات لكل مركز)، على أن تكون Task 11 (`library_indicators`) وTask 03 (`proposals.donor_id`) وTask 15 (مسؤول MEAL لكل محافظة) وTask 30 (`projects.center_id`) مدموجة أيضًا. يجب أن يُدمج قبل تجميد الميزات CP5 (نهاية اليوم 12، 2026-10-27)؛ إن ضاق الوقت يُسقط وضع «التجميع حسب المؤشر» أولًا (البند 2 في قائمة التقليص R9).
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 18 of the HC Tracking proposal-lifecycle correction plan in this Laravel 13 / Livewire 4 / Flux UI 2 repository. The outcome: a read-only **Monthly Matrix** page at `/reports/monthly-matrix` that reproduces the "Study Zone CDCS" Google Sheet from system data: one row per target (a `plan_indicators` row = indicator × center), twelve month columns, then target, total and achieved %, with filters (proposal/donor, center/city, indicator, year, "my centers"), an optional "group by indicator" view and a CSV export. In the corrected story (draft project without dates → concept note → proposal → donor approval fixes dates and funds the project → MEAL confirms the monthly report template and the project becomes active → one agreement), the MEAL side runs alongside: per-center targets (Task 13) are reported every month by the center manager and validated by MEAL (Task 17); this matrix is where the Projects & Fundraising Manager and MEAL read the result. Totals count validated (approved) lines only, through the single achievement definition; nothing is computed locally. Task 21 (monthly digest) links here and UAT (Task 28) compares it with the old sheet.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins) (Laravel Boost rules, Pest, Pint, `php artisan make:* --no-interaction`, no new dependencies or base folders without approval).
2. The plan `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`: section `### Task 18` in full. It is Arabic and authoritative; English table, column, permission and UI names in it are exact. Also read `### Task 17` (your data contract: `monthly_reports`, `MonthlyReportStatus`, `reports.monthly_report_id`, line status mirror draft ↔ reviewed ↔ approved), section 5 rows "البيانات المالية" and "التصدير", section 7 rows CP3 and CP5, section 0 risk R9 and «قواعد العمل بين الفريقين», section 1.0 (project = HR center), and section 9 entries D-07, D-25, D-29 and D-30.
3. The decision log `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists. A recorded decision overrides the plan's default for D-07 (achievement counts at MEAL validation) and D-25 (cumulative indicators, never summing percentages).
4. Rule files (read all): `.ai/rules/resultsindicatorstargetsreports.md` (one achievement definition — the key rule here), `.ai/rules/reports.md`, `.ai/rules/indicators.md` (load once, filter and page in memory, flat query count), `.ai/rules/pages.md` (page header, "Hide money, keep names", resolved filter values), `.ai/rules/ui.md`, `.ai/rules/views.md`, `.ai/rules/views-enums.md`, `.ai/rules/views-pages.md`, `.ai/rules/views-components-ui.md`, `.ai/rules/components-ui.md`, `.ai/rules/routes.md`, `.ai/rules/app.md`, `.ai/rules/components-navigation.md`, `.ai/rules/navigation.md`, `.ai/rules/navigation-css.md` (RTL, logical classes), `.ai/rules/css.md` (status tokens), `.ai/rules/providers.md` (only if you add a rate limiter), `.ai/rules/tests.md`. Then run `grep -rin 'achiev\|export\|csv\|percentage\|rtl' .ai/rules`.
5. Existing code to study first:
   - `app/Models/PlanIndicator.php`: `achievedValue()`, `pendingValue()`, `percentAchieved()`, `achievedValueSql()`, `pendingValueSql()`; and `tests/Feature/Models/PlanIndicatorTest.php` (PHP and SQL forms must agree).
   - `app/Models/Report.php`, `app/Enums/ReportStatus.php`, `app/Enums/IndicatorMeasure.php`, `app/Enums/Period.php`.
   - `resources/views/pages/indicators/⚡index.blade.php` (`#[Url]` filters, load-once, hand-built `LengthAwarePaginator`, `forgetIndicators()`), `tests/Feature/IndicatorDirectoryTest.php` (query-count test with `DB::listen`).
   - `resources/views/pages/donors/⚡index.blade.php` `exportCsv()`, `app/Support/CsvExport.php`, `tests/Feature/CsvExportTest.php`, `tests/Feature/DonorRegisterTest.php` (`assertFileDownloaded`).
   - `resources/views/pages/reports/⚡index.blade.php` (page shell), `resources/views/components/ui/{page-header,header-filter-select,header-filter-multiselect,data-table,empty-state}.blade.php`.
   - `routes/web.php` (`reports/{report}` binds by slug), `resources/views/components/navigation/sidebar.blade.php` (the Reports item uses `routeIs('reports.*')`), `config/access-control.php` (`reports.view` is in `$viewEverything`; `donors.view` and `proposals.view` are restricted).
   - What Tasks 11, 13, 15 and 17 created: `App\Models\LibraryIndicator`, `indicators.library_indicator_id`, `App\Models\MonthlyReport`, `App\Enums\MonthlyReportStatus`, `App\Support\MealCoverage`, and their factories/states.
6. Skills to activate: `livewire-development`, `fluxui-development`, `tailwindcss-development`, `laravel-best-practices`, `laravel-security`, `testing-best-practices`.
7. Use Laravel Boost `search-docs` before relying on framework APIs (Livewire `#[Url]`/`#[Computed]`, returning a download from a Livewire action, `RateLimiter`, query builder grouping/`selectRaw`). Use `database-schema` on `reports`, `monthly_reports`, `plan_indicators`, `indicators`, `library_indicators`, `proposals`, `projects`, `meal_coverages` before writing any query. No migration is expected.

## Preconditions to verify
- `monthly_reports` exists with `project_id`, `period` (first day of the month) and `status` (`MonthlyReportStatus`: `draft`, `submitted`, `validated`), and `reports.monthly_report_id` (nullable FK) — Task 17 (contract frozen at CP3). Months are pre-generated as drafts at activation (D-29), so a draft month without lines is normal. Validated report lines are `reports.status = approved`, submitted lines are `reviewed`. If any of this differs, stop and report.
- Per-center proposal targets live in `plan_indicators` with `source_type = 'proposal'` and `project_id` = center — Task 13.
- `indicators.library_indicator_id` and `library_indicators` (`code`, `name_en`, `name_ar`, `measure`, `is_cumulative`) exist — Task 11. Without them the EN/AR name columns and grouping cannot be built: stop.
- `proposals.donor_id` exists — Task 03 (donor filter).
- `projects.center_id` and `App\Support\EmployeeProfile` / `HrCenter` exist — Task 30 (center labels). If missing, label by project name and city.
- Task 15: `meal_coverages` and `App\Support\MealCoverage::projectsCoveredBy(int $employeeId)` (Revision 9; it replaces the older `citiesCoveredBy()` idea). Never join across the `hr` connection.
- Task 33: monthly report template items and `TemplateItem::isDueIn()`; Task 17: `monthly_report_lines`, their mirrors (`reports.monthly_report_line_id`), shares and breakdowns (CP3 contract).
- `tests/Feature/Models/PlanIndicatorTest.php` is green before you start.
If a required item fails, stop and report instead of improvising.

## Revision 2 changes (2026-10-08)
- Owner is now **B3 (Team B)**, reviewed by Dev 2; days 11–12 (Mon 2026-10-26 → Tue 2026-10-27); effort 2 days (was 2.5). It must merge before **CP5 feature freeze (end of day 12)**; after that only fixes.
- Task 17's contract is now frozen at **CP3 (end of day 9)**, not CP4. Months are generated automatically for the whole project period (D-29): a generated month that has not started yet shows an "upcoming" marker, not "not submitted".
- **R9 cut order, item 2:** if late, drop the "group by indicator" mode first and ship the rows view with filters and export. Build rows first, grouped mode last.
- The center column is the HR center (project = center, D-30): center name and city read through Task 30 (`HrCenter` / `EmployeeProfile`, cached, read-only), falling back to the project name.
- Work under the inter-team rules (plan section 0 «قواعد العمل بين الفريقين»): do not change Phase 1 tables (`proposals`, `projects`, `donor_proposal`, `proposal_status_notes`) or the proposal state machine — request any such change from Dev 1 so it is recorded in the plan; integrate with Phase 1 by listening to its events (`ProposalStatusChanged`, `ProjectActivated`, `ProjectPeriodChanged`, `FundingAgreementRecorded`, dispatched after commit) instead of calling Phase 1 Actions directly; every PR is reviewed by Dev 2 (UI) or Dev 1 (backend) within one working day and merges only with this task's suite green; no production access — seed or masked data only.
- Read-only page: it emits no new events and needs no `EventCatalog` entry; Task 21's monthly digest only links here.

## Revision 3 changes (2026-10-09)
Source: `Stratigy.pptx` (strategy 2026–2030) and `micro logic.xlsx` (the real Micro program logframe, urban and agricultural tracks). Read plan section 1.0 facts 10–12 and the «تعديلات المراجعة 3» subsection of this task. Where this block conflicts with text further down this prompt, this block wins.
- **Pooling replaces "never aggregate percentages".** In "group by indicator" mode and in any total across centers, percentage and average = `PlanIndicator::pooledRatioSql()` (Task 17). Lines without numerator/denominator (legacy or entered as a plain value) are excluded and counted next to the figure: «(n غير قابلة للتجميع)». Never average percentages.
- Non-monthly indicators (`collected_by = meal`, or `per-season` / `follow-up` / `baseline-endline`) go to a separate section below the grid, «مؤشرات تُقاس دوريًا», showing the latest approved value, its date and the target — no month columns.
- Add a strategic-pillar filter (Task 32), through the project's program.
- Replace the test "grouping does not sum percentages" with: grouping uses Σnumerator ÷ Σdenominator, excludes lines without a denominator, and shows their count.

## Revision 4 changes (2026-10-12)
Source: plan header paragraph «المراجعة 4», D-38…D-41, the «تعديلات المراجعة 4» subsection of this task and `### Task 33`. Where this block conflicts with the Revision 2/3 blocks or the base sections, it wins.
- **Two views.** *Center view* (default): one row per **template item** of the project, read from `monthly_report_lines`, so an indicator shared by two donors is one row; the target column is the item's approved target (Task 33). *Donor view* (when a proposal/donor filter is set): rows are that donor's own targets, read from their mirrors in `reports` against `plan_indicators.target`.
- **Totals across donors add lines, never mirrors**, or a center's figure would be counted twice.
- **Not-due cells:** a month where the item is not due (`TemplateItem::isDueIn()` is false) or that lies outside the item's window shows «—», never 0.
- Values still come from validated (confirmed) reports only; submitted-but-unconfirmed values keep their distinct style and stay out of totals.

## Revision 5 changes (2026-10-12)
Source: management note on co-funded indicators, recorded as **D-46** (amends D-40). Read plan section 1.0 facts 14 and 18, decision row D-46, and the «تعديلات المراجعة 4» and «تعديلات المراجعة 5» subsections of this task in the plan. Where this block conflicts with text further down this prompt, this block wins.
- An indicator shared by several donors in one center follows its program's mode, `programs.donor_attribution` (`App\Enums\DonorAttribution`: `shared`, `split`; default `shared`). `shared` (e.g. DESC: 30 students, one report, 30 for each donor) = D-40. `split` (e.g. Micro: 10 beneficiaries = 6 for donor 1 + 4 for donor 2, a separate report per donor): the center manager enters each donor's share monthly, each donor's mirror carries only its share, and the center's achievement is the sum. A beneficiary has exactly one funder, so there is no separate total field and no reconciliation check.
- Center view reads lines (sum of shares in `split`); a `split` row expands into a sub-row per donor. Donor view reads that donor's mirrors (its share in `split`, the full value in `shared`).
- Totals across donors still come from lines, never mirrors. Center target = item target (sum in `split`, highest in `shared`). Test: `split` 6 + 4 → center 10, donor 1 sees 6; `shared` 30 for two donors → center 30, not 60.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-25, D-50, D-51, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- Rows: one per template item in the center view (no duplicate for co-funded indicators); the target is the item's target; non-due months show «—».
- **D-50:** a disaggregated row expands to male, of whom PwD, female, of whom PwD, total (like the Syria Cross Indicator Performance Tracker), read from `monthly_report_line_breakdowns`. Ratios per category = Σnumerator ÷ Σdenominator (D-25, `pooledRatioSql()` pattern); never sum monthly percentages (the Syria Cross workbook's cell Q7 bug). A «PwD only» filter shows the disability columns.
- **D-51:** an «Activities» tab: activity × month with confirmed statuses, grouped by output, like the Syria Cross Activities Tracker; CSV export with the same permission and filters.

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
- **D-25:** fixed-denominator percentage rows show count and percentage per month; pooling across centers/donors = Σcounts ÷ Σfixed denominators; disaggregated rows for these items are counts only.
- **D-12a:** the MEAL «my projects» filter reads `MealCoverage::projectsCoveredBy()` instead of cities.

## Scope
### In scope
- Create `resources/views/pages/reports/⚡monthly-matrix.blade.php` (`pages::reports.monthly-matrix`, `#[Title('titles.monthly_matrix')]`).
- Route `Route::livewire('reports/monthly-matrix', 'pages::reports.monthly-matrix')->can('reports.view')->name('reports.monthly-matrix')`, declared BEFORE `reports/{report}`; otherwise `monthly-matrix` is resolved as a report slug and 404s.
- Sidebar item in the **Monitoring & Evaluation** group (`@can('reports.view')`). Narrow the Reports item's `:current` to `routeIs('reports.index', 'reports.show')` so only one item is highlighted.
- Columns: code, indicator name (English), indicator name (Arabic), center (HR center name + city, Task 30; project name as fallback), source (proposal and donor as plain text, or annual plan), measure, target, 12 month columns, total, achieved %.
- Month window: with exactly one proposal filtered, the 12 columns start at the proposal's start month (`actual_date_begin` ?? `planned_date_begin`) and the year filter becomes grant year 1, 2, …; otherwise January–December of the selected calendar year (the fiscal year, as `annual_plans.year`). Confirm in plan mode.
- Filters bound with `#[Url]`: proposal, donor, center (project), city, indicator (library indicator), year, "my projects" (projects the signed-in user's employee covers through `MealCoverage::projectsCoveredBy()`, Task 15 Revision 9), view mode `rows` / `grouped`.
- Cell states: approved, pending validation, not submitted, upcoming (month not started) — each with an icon or text, not colour alone.
- CSV export `exportCsv()` through `App\Support\CsvExport::download()`.
- Lang keys in `lang/en/reports.php` and `lang/ar/reports.php` (a `matrix` section), `titles.php`, `navigation.php`.
### Out of scope
Monthly report entry, validation and reopening (Task 17); entering targets (Task 13); library CRUD (Task 11); MEAL coverage assignment (Task 15); the monthly digest (Task 21, it only links here); year-over-year comparison through `previous_proposal_id` that Task 10's handoff mentions (not in this task's plan section — list as a follow-up); tours and user guide (Task 26); any change to the achievement definition (D-25).

## Business rules
- A month cell is the value of that target's monthly line: the `reports` row with `monthly_report_id` set, bucketed by `monthly_reports.period` (never by `created_at` or `submit_date`).
- Approved line: normal style, counts. Reviewed line (submitted, awaiting MEAL): pending style, shown but never counted. Draft line or no line: "not submitted" marker.
- Total and achieved % follow the unified definition only: numeric = sum of approved values; percentage = latest approved level by `planned_date`; % = achieved ÷ target × 100, shown as "—" when the target is 0. Read them from `achievedValueSql()` / `achievedValue()` / `percentAchieved()`; never add up the visible cells.
- Known gap — raise in plan mode: `achievedValueSql()` counts every approved report of the target (all months plus legacy non-monthly reports), so the total can differ from the sum of the 12 visible cells. Recommended: keep the unified total and show a small "includes other periods" marker on rows where it differs; do not invent a windowed achievement.
- A target with a non-monthly period (quarterly, annual) shows a value only in the month that holds its line.
- Grouped mode (by library indicator, across centers and donors): numeric indicators sum targets, approved cells and totals; group % = summed achieved ÷ summed target. Percentage indicators are never summed: the group row shows "—" and each target keeps its own row. Show the library `is_cumulative` flag with a note that cumulative counts summed across donors may double count (D-25: show the flag, change nothing).
- "Separate entry, aggregated display": the page writes nothing.
- No money anywhere: no budgets, requested budget, agreements, tranches or currencies.

## Security and authorization
- Route gate `can:reports.view`; `exportCsv()` calls `Gate::authorize('reports.view')` itself.
- Department-scoped roles see every indicator here (no task scoping) unless the decision log says otherwise.
- Donor and proposal names are plain text. Link to `donors.show` only with `donors.view`, to `proposals.show` only with `proposals.view`, to `projects.show` with `projects.view` ("Hide money, keep names"). Pass model instances (or `tap(new Model, ...)`) to `route()`; slugs bind, raw ids 404.
- The export runs the same query method, filters and visibility as the screen, covers all filtered rows (not just the current page), carries no money column, and is rate limited per user. Existing exports rely only on the global `livewire` limiter; propose in plan mode `RateLimiter::attempt('monthly-matrix-export:'.$userId, 10, ...)` inside the action or a named limiter in `AppServiceProvider::configureRateLimiting()`.
- Filter ids from the URL are checked against known values; an unknown id falls back to "all" and never reaches SQL raw.
- Data-returning helpers stay `protected` (`tests/Feature/AccessControl/BrowserCallableMethodsTest.php`).

## Validation
Read-only page; filters only. Any message shown goes to `lang/en/reports.php` and `lang/ar/reports.php`.
- `proposal`, `donor`, `project`, `indicator`: integer and present in their table (`Rule::exists`), otherwise reset to all.
- `city`: one of the cached HR city options (no cross-connection join).
- `year`: integer between 2020 and next year; with a proposal selected, a grant year between 1 and the proposal's length in years.
- `view`: `Rule::in(['rows', 'grouped'])`; `mine`: boolean.
- Pass the RESOLVED value to `x-ui.header-filter-select :value` (pages.md rule) and assert the rendered label in a test.

## Edge cases and concurrency
- Proposal starting in November: columns run November → October, each heading with month and year (as in the original sheet).
- Proposal longer than 12 months: the grant-year selector picks the window.
- No data, or filters exclude everything: `x-ui.empty-state` naming the active filters with a clear-filters action.
- 500+ rows: load once, filter and page in memory (indicators/⚡index pattern); the export streams every filtered row with a generator.
- Target with no monthly lines yet: "not submitted" markers for started months, "upcoming" for months that have not started; total from the unified definition (often 0).
- Report reopened under Task 17: its lines return to draft and leave the total until revalidated; never cache figures across requests.
- Deactivated library indicator: still listed.
- "My centers" for a user without `employee_id` or without coverage: empty result with a hint, no error.
- Read-only page, so no write races; the export re-runs the query, so it may differ from a stale screen — acceptable and documented.

## Data integrity
Read only: no migration, no writes, no cached figures. The export reflects the same query as the screen at export time.

## Implementation guidance
- Fixed number of queries regardless of row count:
  1. Targets: `plan_indicators` joined to `indicators`, `library_indicators`, `projects`; left-joined to `proposals` (morph key `'proposal'`, never the class name) and `donors`; select target, measure and `PlanIndicator::achievedValueSql()` as `achieved_value`.
  2. Cells: one aggregate over `reports` joined to `monthly_reports` (`reports.monthly_report_id IS NOT NULL`), limited to the window and the filtered targets, grouped by `plan_indicator_id`, `monthly_reports.period` and `reports.status`.
  3. Filter options (cached computed properties). City names from HR through existing cached options.
  Shape everything in PHP. No lazy `->reports` access (strict mode throws).
- Achieved %: `percentAchieved()` has no SQL twin. Either hydrate `PlanIndicator` models with eager-loaded `reports` and `indicator` and call `achievedValue()` / `percentAchieved()`, or add `PlanIndicator::percentAchievedSql()` next to its twins with a PHP/SQL agreement case in `tests/Feature/Models/PlanIndicatorTest.php`. Choose in plan mode; never write the formula inline in the page.
- Protected helper shape:
```php
/** @return array{months: list<CarbonImmutable>, rows: list<array{planIndicatorId: int, code: ?string, nameEn: string, nameAr: string, center: string, source: string, measure: IndicatorMeasure, target: float, cells: array<string, array{value: ?float, state: 'approved'|'pending'|'missing'}>, total: float, percent: ?float}>} */
protected function matrix(): array {}
```
- Page: `x-ui.page-header` with a row-count badge; header filters via `x-ui.header-filter-select` / `x-ui.header-filter-multiselect`; the export button (`arrow-down-tray`, like donors) in the actions slot, never in the filters grid.
- Table: horizontal scroll inside the panel (no page-level horizontal scroll), sticky identity columns, `table-fixed`, `tabular-nums`; numbers and codes LTR-isolated (`dir="ltr"` / `<bdi>`), names `dir="auto"`; month headings via `->translatedFormat(__('formats.month_year'))`; logical classes only (`ps-*`, `pe-*`, `text-start`, `text-end`); status colours from the `status-*` tokens; dark mode with Graphite tokens; status legend with text.
- No `@php(...)` shorthand; every string through `__()`.

## Tests (Pest)
Follow `.ai/rules/tests.md`: seed `RolesAndPermissionsSeeder`, `userWithRole()` / `User::factory()->role(...)`, Task 17's factory states for submitted/validated reports, freeze time with `$this->travelTo()`, create fixtures inside `Activity::withoutRecording`.
- `tests/Feature/MonthlyMatrixTest.php`:
  - guest redirected; roleless user gets 403; a MEAL user and a Financial user get 200; `GET /reports/monthly-matrix` resolves while an existing report slug still opens `reports.show`;
  - an approved value lands in its month column; a reviewed value is shown as pending and excluded from the total; a draft shows "not submitted"; a generated month that has not started shows "upcoming" (frozen time);
  - the center column shows the HR center name and city;
  - total equals `achievedValue()` for a numeric target (sum) and for a percentage target (latest level); % equals `percentAchieved()`; target 0 shows "—";
  - a proposal starting in November gives November as the first column and October as the last;
  - grouped mode sums a numeric indicator across two donors and never sums a percentage indicator;
  - each filter (proposal, donor, project, city, indicator, year, my projects, pillar, sector if added) narrows rows; an unknown id is ignored; the header picker shows the resolved label;
  - a MEAL user sees the donor name without a `donors.show` link and no amount anywhere in the HTML; a Financial user gets the link;
  - export: `assertFileDownloaded('monthly-matrix-2026-11-13.csv')` under frozen time; rows equal the screen's under the same filters; a filtered-out target is absent; no amount column; the rate limit refuses the call after the limit;
  - query count is identical for 3 and 30 targets (`DB::listen`, as in `IndicatorDirectoryTest`);
  - Arabic locale: Arabic month names and labels, months in chronological DOM order (`assertSeeInOrder`), numbers inside `dir="ltr"`.
- Update `tests/Feature/NavigationTest.php` (new item, exactly one current item). Run `tests/Feature/LocalizationTest.php`, `tests/Feature/AccessControl/AuthorizationCoverageTest.php`, `tests/Feature/AccessControl/BrowserCallableMethodsTest.php`, `tests/Feature/ReportDirectoryTest.php`, `tests/Feature/Models/PlanIndicatorTest.php`.
- Commands: `php artisan test --compact tests/Feature/MonthlyMatrixTest.php`, then `--filter=...` per change.

## Acceptance criteria
- [ ] MEAL and the Projects & Fundraising Manager read the same layout they used in the Study Zone sheet, from system data.
- [ ] Every row's total and % equal `achievedValue()` / `percentAchieved()` (tested); pending values are visible but never counted.
- [ ] Percentages are never summed in grouped mode (or grouped mode was cut under R9 and reported).
- [ ] Merged before CP5 (end of day 12).
- [ ] The CSV equals the screen for the same filters, respects permissions, carries no money and is rate limited.
- [ ] Query count is flat (tested).
- [ ] No amount appears for anyone; donor/proposal links render only with their view permission.
- [ ] Correct in Arabic/RTL and English; every string in `lang/en` and `lang/ar`; the route is declared before `reports/{report}`.
- [ ] All listed tests pass; Pint is clean.

## Do not
- Compute the total or % from the visible cells, or sum percentages.
- Create a second value table, a materialized summary or a cross-request cache of figures.
- Query or render any money column, or link to donor/proposal pages without their view permission.
- Join or `whereHas()` across the `hr` connection.
- Register the route after `reports/{report}`, or leave two sidebar items highlighted.
- Use `left`/`right` utilities, `@php(...)` shorthand, literal UI text, or a new dependency (no spreadsheet package).

## Quality bar (this task)
- Matrix: sticky code and name columns and header row; months scroll inside the panel; a legend for cell states (approved, awaiting validation, not sent, «—» not due); a totals row; expandable split and disaggregation rows; an export button with a loading state; an empty state that explains the filters.
- Everything in `quality-bar.md` applies. Before reporting, open every new or changed screen in the browser (Arabic RTL and English, light and dark, desktop and 375 px), run `vendor/bin/phpstan analyse` on the touched paths, and list both in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions; wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside Task 18's scope; note anything else as a follow-up.

## Report back
Your final message must contain: a short summary; files created/changed; migrations added (expected none); tests run with their pass/fail output; whether the R9 cut (grouped mode) was applied; decisions assumed (D-07, D-25, D-29, month window and grant-year rule, total-vs-cells marker, % SQL helper or model hydration, export rate limit, "my centers" source); follow-ups (year-over-year comparison, links from cells to the monthly report). Handoff facts: for Task 21 — the route name `reports.monthly-matrix` and the query parameters that deep-link a program, year or "my centers"; for Task 26 — the page title and stable `data-test` / tour hooks; for Task 28 — which filter combination reproduces the "Study Zone CDCS" sheet for UAT.
````
