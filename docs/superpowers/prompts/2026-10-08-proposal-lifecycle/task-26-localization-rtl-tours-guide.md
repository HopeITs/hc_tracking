# Task 26 — Localization, RTL, Guided Tours and User Guide

> **المهمة:** التوطين، وRTL، والجولات الإرشادية، ودليل المستخدم
> **المطوّر:** B4 (مراجعة Dev 2) · **الفريق:** B · **الأيام:** 6–12 موزعة (الاثنين 2026-10-19 ← الثلاثاء 2026-10-27؛ في جدول B4: اليوم 7 مفاتيح 11/22/23/31/32، واليوم 11 الجولات بعد أن أخذت Task 33 الأيام 8–10) · **الجهد:** 3 أيام موزعة · **يعتمد على:** تتبع كل مهمة واجهة (04، 05، 06، 07، 08، 09، 11، 12، 13، 15، 16، 17، 18، 19، 30، 31، 32، 33، 34)
> **متى تستخدمه:** بعد انتهاء كل مهمة واجهة مع وضع `per-task` في `<MODE>` ورقمها في `<TASK_NUMBER>`، ثم مرة أخيرة بعد تجميد الميزات (CP5، نهاية اليوم 12، 2026-10-27) وقبل CP6 (نهاية اليوم 13، 2026-10-28) مع وضع `user-guide` في `<MODE>` لتحديث دليلَي المستخدم.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Parameters
- `MODE` = `<MODE>` — `per-task` (after one finished UI task) or `user-guide` (once, after CP5 at the end of day 12 and before CP6 at the end of day 13).
- `TASK_NUMBER` = `<TASK_NUMBER>` — the finished task this run covers (`per-task` only).
If either value is still a literal placeholder, ask the developer before doing anything.

## Role and goal
You are implementing Task 26 of the HC Tracking proposal-lifecycle correction plan, a cross-cutting task run once per finished UI task and once at the end. In `per-task` mode, make everything Task `<TASK_NUMBER>` added fully bilingual (Arabic/English), correct in both directions (RTL/LTR), and covered by a guided tour when it is a main page. In `user-guide` mode, update `docs/user-guide.md` and `docs/user-guide.ar.md` to the corrected story. Why it matters: staff work Arabic-first on the corrected path (project inside an HR center and program, draft without dates → concept note → proposal with its plan (**Logical Framework**, activity calendar, milestones, **Department Tasks**) → donor approval fixes dates and funds the project → MEAL confirms the **Monthly Report Template** → the project becomes active and the monthly reports are generated → one agreement with its tranche schedule (one auto-generated transfer record per tranche) → aggregated donor reports in the **Donor Report Builder**; MEAL: **Indicator Library** → **Logical Framework** → targets per center → **Monthly Report Template** → **Monthly Report** → **Monthly Matrix**), and QA (Task 28) runs every scenario in Arabic.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins) (Laravel 13, PHP 8.5, Livewire 4 + Flux UI 2, Pest 5, Pint, Boost tools, narrow test runs, no new dependencies).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`: `### Task 26` and `### Task <TASK_NUMBER>` (its UI scope and every message it defines). The plan is Arabic and authoritative; English table and UI names are exact. In `user-guide` mode read sections 0–2 and the Objective and Business rules of every task.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists — a recorded decision overrides the plan's default for D-xx (in the guide, describe the decided behaviour).
4. Rules: `.ai/rules/views-enums.md`, `.ai/rules/navigation-css.md`, `.ai/rules/views.md`, `.ai/rules/ui.md`, `.ai/rules/pages.md`, `.ai/rules/css.md`, `.ai/rules/components-ui.md`, `.ai/rules/js.md`, `.ai/rules/tests.md`, plus the module rules for every path Task `<TASK_NUMBER>` touched (map them through `.ai/rules/index.md`: e.g. `proposals.md`, `admin.md`, `indicators.md`, `results.md`, `reports.md`, `funding-agreements.md`, `pages-funding-agreements.md`, `annual-plans.md`, `notifications.md`, `views-components-notifications.md`). Run `grep -rin 'lang\|rtl\|tour' .ai/rules`.
5. Study first: `app/Enums/Locale.php` (`direction()`, `carbonLocale()`), `app/Enums/Concerns/HasTranslatedLabel.php`, `lang/en/formats.php`, `lang/{en,ar}/tour.php`, `lang/{en,ar}/tour_pages.php`, `config/tour.php`, `resources/views/components/tour/overlay.blade.php` (route name → key with `.`/`-` replaced by `_`; copy read from `tour_pages.pages.<route_key>.<id>`; a step with no visible target is skipped; note that the comment in `config/tour.php` says `lang/*/tour.php` but the overlay reads `tour_pages.php`), `resources/views/components/tour/button.blade.php`, `resources/js/tour.js`, `resources/views/components/ui/project-number.blade.php` (LTR isolation), `tests/Feature/LocalizationTest.php`, `tests/Feature/GuidedTourTest.php`, and both user guides.
6. Skills: `livewire-development`, `fluxui-development`, `tailwindcss-development`, `testing-best-practices`.
7. Use Boost `search-docs` (`['localization', 'pluralization']`) before changing how strings are resolved.

## Preconditions to verify
- `per-task`: Task `<TASK_NUMBER>` is merged and its own tests pass; `php artisan route:list --except-vendor` gives the real route names of its pages (do not trust the planned paths below).
- `php artisan test --compact tests/Feature/LocalizationTest.php tests/Feature/GuidedTourTest.php` before you start. If red, list each failing key with its owning task; fix keys of Task `<TASK_NUMBER>` or of finished tasks, report the rest.
- `user-guide`: CP5 reached (features frozen, end of day 12); `per-task` runs exist for 04, 05, 06, 07, 08, 09, 11, 12, 13, 15, 16, 17, 18, 19, 30, 31, 32, 33, 34 (check that their pages appear in `GuidedTourTest`/`LocalizationTest`); know which R9 cuts were applied (Tasks 16, 18, 23, 10, 22). Tasks 24/25 are out of the October window and did not ship.
If a check fails, stop and report instead of improvising.

## Revision 2 changes (2026-10-08)
- Owner is now **B4 (Team B)**, reviewed by Dev 2; spread over days 6–12 (Mon 2026-10-19 → Tue 2026-10-27): day 6 keys for Tasks 11/22/23/31, days 7 and 9 per-task runs, day 11 tours; the `user-guide` run between CP5 (end of day 12) and CP6 (end of day 13).
- Task 20 (media deliverables, **Media Workspace**, `/media-deliverables`) is gone: merged into **Task 31** — the **Plan** tab on **Proposal** (milestones and department tasks) and the **Department Tasks** page (`/department-tasks`), with `TaskKind` / `DeliverableKind` labels.
- Task 19 no longer has a **Reporting Schedule** or `report_schedules`: it is the aggregated donor report — **Donor Report Builder** (`/donor-reports/compose`) and **My Reporting Duties**, with `ReportingPeriod` / `ReportAudience` labels.
- New Task 30 (HR read-only): the employee chip and picker show position, job level, department and center; the project form picks an HR center. Localize its labels and the center/HR warnings.
- Tasks 24 and 25 are out of the October window: no runs, nothing in the guides.
- Required tours now: **Indicator Library**, **Logical Framework** tab, **Reviewers** panel, **Monthly Report**, **Monthly Matrix**, **Plan** tab on **Proposal**, **Department Tasks**, **Donor Report Builder**, **My Reporting Duties**.
- Work under the inter-team rules (plan section 0 «قواعد العمل بين الفريقين»): do not change Phase 1 tables (`proposals`, `projects`, `donor_proposal`, `proposal_status_notes`) or the proposal state machine — request any such change from Dev 1 so it is recorded in the plan; integrate with Phase 1 by listening to its events (`ProposalStatusChanged`, `ProjectActivated`, `ProjectPeriodChanged`, `FundingAgreementRecorded`, dispatched after commit) instead of calling Phase 1 Actions directly; every PR is reviewed by Dev 2 (UI) or Dev 1 (backend) within one working day and merges only with this task's suite green; no production access — seed or masked data only.
- Notification sentences for every event in the Task 21 `EventCatalog` (MEAL + General Management recipients, digest and immediate) must exist in both languages; check them in each per-task run.

## Revisions 3–9 changes (2026-10-09 → 2026-10-12)
Source: plan header paragraphs «المراجعة 3» to «المراجعة 9» and the new Tasks 32, 33 and 34. Where this block conflicts with the Revision 2 block or the base sections, it wins.
- **More UI tasks to cover (rows added to the per-task table below):** 33 (**Monthly Report Template** tab on **Project**, **Template Queue** page, **Evidence Types** and **Narrative Questions** settings), 34 (**Sectors** page and the project form field), 32 (**Pillars** on **Strategy**, **Start from Program Framework**), 15 (MEAL coverage settings page), 12 (activity × months/weeks grid), 04 (**Correct Status**, **Record Final Report Acceptance**), 05 (the `approved` "funded, awaiting template" badge, working days field), 08 (**Approve Proposal** with evidence), 09 (tranche schedule, arrival vs receive hints).
- **Tours now required also on:** **Template Queue** and the **Monthly Report Template** tab (MEAL's main new workflow); intros for **Sectors** and the MEAL coverage page.
- **New enum labels:** `ActivityKind`, `ActivityStatus` (Not Started, Started, Ongoing, Suspended, Cancelled, Completed, Conducted — keep the English status names recognisable in Arabic copy, as MEAL uses them in Excel), `Gender`, `IndicatorCollector`, `DenominatorBasis`, `DonorAttribution`, `TemplateItemStatus`, `TemplateItemSchedule`, `NarrativeCadence`, `MealCoverageScope`, the new `ProjectStatus::Approved` label («ممول، بانتظار نموذج التقرير»).
- **Mixed content to isolate LTR:** generated indicator codes (`002.OP1.2.3`, `HC.OP1.1`), program codes, week numbers, "36 of 180" figures.
- **User guide (`user-guide` mode):** describe the Revision 4 activation gate (approved → template → active), split vs shared funding (D-46), disaggregation (D-50), activity statuses and carry-over (D-51), the fixed-denominator percentage (D-25), sectors, pillars, the tranche schedule and the final-report closing rule (D-02c).

## Scope
### In scope — `per-task`
| Task | Surfaces to localize and check (verify actual files) | Tour |
|---|---|---|
| 06 | **Create Proposal**/**Edit Proposal** on `proposals/⚡index` and `⚡show`; **New Concept Note** on `projects/⚡show` | steps only for new sections |
| 07 | **Reviewers** panel on `proposals/⚡show`; **Default Proposal Reviewers** on `/admin/notification-settings`; review notifications | required: **Reviewers** steps on `proposals.show` |
| 11 | **Indicator Library** page (planned `/indicator-library`), Sidebar item, `CollectionFrequency`/`Disaggregation` labels | required: intro + steps |
| 13 | **Indicators & Targets** tab; project indicators on `projects/⚡show`; grid `aria-label`s | step for the grid |
| 16 (with 12) | **Logical Framework** tab on `proposals/⚡show`; chain view on `programs/⚡show` and `annual-plans/⚡show` | required: **Logical Framework** steps |
| 17 | **Monthly Reports** list and **Monthly Report** page, `MonthlyReportStatus` labels, notifications | required on **Monthly Report**; intro on the list |
| 18 | **Monthly Matrix** (planned `/reports/monthly-matrix`), CSV headings | required |
| 19 | **Donor Report Builder** (planned `/donor-reports/compose`), **My Reporting Duties**, `ReportingPeriod`/`ReportAudience` labels, completeness warnings, due/overdue reminders | required on both pages |
| 30 | employee chip and picker (position, job level, department, center), HR center field on the project form, "inactive center" / "HR unavailable" messages | none (no new page) |
| 31 | **Plan** tab on `proposals/⚡show` (milestones, department tasks), **Department Tasks** (planned `/department-tasks`), `TaskKind`/`DeliverableKind` labels, department-task alerts | required on both |
| 33 | **Monthly Report Template** tab on `projects/⚡show`, **Template Queue** (planned `/monthly-report-templates`), **Evidence Types** and **Narrative Questions** settings, template item labels and badges | required on the queue and the tab |
| 34 | **Sectors** page (planned `/admin/sectors`), the sectors field on the project form, the sector filter and badges | intro |
| 32 | **Pillars** section on **Strategy**, **Start from Program Framework** on the **Logical Framework** tab, import command output | step on the button |
| 04, 05, 08, 09, 10, 12, 14, 15, 21, 22, 23 | any text, enum, notification, email or page they added (Task 04: **Correct Status**, **Record Final Report Acceptance**; Task 08: **Approve Proposal** evidence; Task 09: tranche schedule and the arrival/receive hints; Task 12: activity calendar grid; Task 15: MEAL coverage page; Task 21: every `EventCatalog` sentence and the monthly digest email) | intro for any new page route |
- Lang keys in `lang/en/<module>.php` and `lang/ar/<module>.php`, enum labels in `enums.php`, page titles in `titles.php`, Sidebar labels in `navigation.php`, notification sentences in `notifications.php` (`events.*`), validation messages, email copy.
- Replace literal text in the task's Blade/PHP with keys; direction fixes; `data-*` hook attributes for tour targets; `config/tour.php` entries; `tour_pages.php` copy in both languages; tests.
### In scope — `user-guide`
- Update both guides in parallel: proposals, projects, agreements, MEAL, reports. Map onto the existing structure: 4.2 Delivery, 4.3 Results and learning, 4.4 Funding, 4.5 Statuses, 4.6 Other useful pages, section 5 (new workflows), 6.3 Roles (new permissions), 7, 9 (FAQ), 10 (admin checklist: **Default Proposal Reviewers**, MEAL coverage, **Supervision** (HR direct manager + exceptions), **Event Notifications**, notification thresholds); add **Department Tasks**, the automatic monthly reports and the **Donor Report Builder**; update the Contents list.
### Out of scope
- Behaviour, queries, validation logic, permissions or layout changes of the feature (only text, direction-safe classes and data hooks).
- Translating stored values; regenerating `docs/user-guide.docx` / `docs/user-guide.ar.docx` (follow-up only).
- Other tasks' pages outside this run's task (except fixing keys that block the parity test).

## Business rules
- No literal UI text in Blade or rendered PHP: `__('group.key')` / `trans_choice()` with the key in both languages. Shared words in `common.php`, titles in `titles.php` (`#[Title('titles.x')]`), dates via `->translatedFormat(__('formats.date'))`; `lang/ar.json` holds only vendor sentence keys; never name a group file `results`, `of`, `to`, `files` or `showing`.
- Unified Arabic terms: المذكرة المفاهيمية، المقترح، الاتفاقية، الأثر (Impact)، النتيجة (Outcome)، المُخرَج (Output)، النشاط (Activity)، المستهدف، التحقق. Grep `lang/ar` for synonyms the task introduced and align them.
- Stored values stay English; only labels translate (`$case->label()`).
- Arabic plurals use explicit ranges `{0}|{1}|{2}|[3,10]|[11,*]`; every `:placeholder` of the English text survives.
- Indicator names show in the user's language from **Indicator Library**, the other language as a secondary line.
- Codes (`R8.A1.I1`), numbers, amounts and project numbers render LTR-isolated (`x-ui.project-number` for project numbers, `<bdi dir="ltr">` otherwise); mixed Arabic/English cells use `dir="auto"`.
- Tours: required for **Indicator Library**, **Logical Framework** tab in **Proposal**, **Reviewers** panel, **Monthly Report**, **Monthly Matrix**, **Plan** tab in **Proposal**, **Department Tasks**, **Donor Report Builder**, **My Reporting Duties**; every other new page route gets at least an `intro`. Tour copy explains what the user sees and does; it never contains data.

## Security and authorization
- No sensitive data in text: no amounts in notifications, emails, tour copy or empty states meant for users without money permissions; never add an `:amount` placeholder the task did not pass.
- Error and validation messages are non-technical: no table, column, class, path, SQL or encryption details.
- Tour steps point only at elements the page already renders for that user; never render a hidden money element so a step can reach it. Data hooks carry no record ids.

## Validation
- Every validation message of Task `<TASK_NUMBER>` (component `validate()` message arrays and Action `ValidationException::withMessages()`) resolves to a key present in `lang/ar/<module>.php` and `lang/en/<module>.php`; none falls back to Laravel's generic English sentence in the Arabic UI. Follow the per-field message style of `ManagesAgreementForm::saveAgreement()`.

## Edge cases and concurrency
- Long Arabic labels in grids, badges and buttons: wrap or truncate with a `title`, never clip meaning.
- Codes and numbers inside Arabic sentences: isolate the placeholder value at render time.
- **Monthly Matrix** month columns and grid keyboard navigation (Tasks 13, 17, 18) must follow the reading direction (in RTL, ArrowRight moves toward the start). Fix only if local and trivial; otherwise log a follow-up for the owning task.
- Tab-scoped tour steps only appear when that tab is open; the intro must still read well when it is not.
- Parallel runs editing the same lang files: keep edits grouped per module; on conflicts keep both keys and rerun `LocalizationTest`.
- `user-guide`: decisions marked "افتراضي مطبّق" are described as current behaviour; Tasks 24/25 are out of the October window and are not described; features cut under R9 are not described as shipped.

## Data integrity
- No migrations, no data changes, no seeders. Do not rewrite stored values.
- `lang/en` and `lang/ar` keep identical key sets; both guides keep the same headings, numbering and order.

## Implementation guidance
- `.ai/rules/navigation-css.md`: logical utilities only (`ms/me/ps/pe/start/end/text-start/text-end`, `rounded-s/e`, `border-s/e`); flip directional icons with `rtl:-scale-x-100`. Full `@php … @endphp` blocks, never `@php(...)`.
- Tour wiring (route names are illustrative; use `route:list`):
```php
// config/tour.php → 'pages'
'monthly-reports.show' => [
    ['id' => 'grid', 'targets' => ['[data-monthly-report-grid]']],
    ['id' => 'submit', 'targets' => ['[data-monthly-report-submit]']],
],
// lang/{en,ar}/tour_pages.php → 'pages'
'monthly_reports_show' => [
    'intro' => ['title' => '…', 'body' => '…'],
    'grid' => ['title' => '…', 'body' => '…'],
],
```
- Visual RTL check: you must not sign in with a password. Use the server-side render technique in `.ai/rules/pages.md` ("Preview an auth-gated page…") for each new page in `en` and `ar`, light and dark, desktop and 375px width, then delete `public/__preview.html`; or ask the developer for screenshots.
- `user-guide`: write for staff, task-oriented and short; bold UI names exactly as rendered in each language (take Arabic labels from `lang/ar`); keep each guide's tone and numbering; no screenshots unless supplied.

## Tests (Pest)
- `per-task`:
  - `tests/Feature/LocalizationTest.php` stays green (parity, placeholders, used keys exist, enum labels). On the first run, add two tests driven by one shared file list that later runs extend with their task's Blade files: "listed views use logical direction classes only" (regex for `ml-`, `mr-`, `pl-`, `pr-`, `left-`, `right-`, `text-left`, `text-right`, `rounded-l`, `rounded-r`, `border-l`, `border-r` as whole class tokens) and "listed views contain no literal UI text" (text nodes outside Blade echoes and literal `label`, `placeholder`, `title`, `aria-label`, `heading`, `description` attributes, with a small reviewed allowlist). If the heuristic cannot be made reliable, propose an alternative in plan mode instead of weakening it.
  - Per new page: an Arabic render test (user with `locale` `ar` and a role that may open it) asserting `<html lang="ar" dir="rtl"`, the Arabic title and an Arabic enum label, plus the English counterpart.
  - `tests/Feature/GuidedTourTest.php`: for each tour, the intro and step titles in English, the Arabic intro, and the hook attribute in the HTML (`assertSee('data-…', false)`); tab tours request the page with the tab's query value.
  - If the task added notifications or emails, extend its own test to render the sentence in both locales.
- `user-guide`: no new code tests; run `LocalizationTest` and `GuidedTourTest`; compare heading counts (`grep -c '^#'`) of both guides; spot-check that bold UI names exist in the lang files.
- Run: `php artisan test --compact tests/Feature/LocalizationTest.php tests/Feature/GuidedTourTest.php` and the page tests you touched, or `--filter=...`.

## Acceptance criteria
- [ ] `per-task`: 0 missing keys in either language (automated); 0 literal UI strings and 0 physical direction classes in Task `<TASK_NUMBER>`'s files (automated).
- [ ] `per-task`: each required tour has intro and steps in both languages and a passing `GuidedTourTest` case.
- [ ] `per-task`: every page reviewed in Arabic and English (light/dark, desktop/mobile) with findings listed; every validation message shows in Arabic in its place.
- [ ] `per-task`: every required tour of the Revision 2 list (including **Plan**, **Department Tasks**, **Donor Report Builder**, **My Reporting Duties**) exists in both languages.
- [ ] `user-guide`: both guides describe the shipped corrected story and the decided behaviour, with the same structure and an updated Contents list, before CP6 (end of day 13).

## Do not
- Translate stored values or rename enum values.
- Change behaviour, queries, permissions or layout beyond direction-safe classes and data hooks.
- Add a key in one language only, or put module sentences in `lang/ar.json`.
- Point a tour at money elements or put data in tour copy.
- Use `@php(...)` or physical direction classes.
- Translate mechanically: Arabic must read naturally with the unified terms; flag doubtful terms for MEAL/Finance review.
- Regenerate the `.docx` guides.

## Quality bar (this task)
- Tours: at most six steps per page, one sentence per step, each step anchored on a stable `data-tour` hook; steps whose targets are hidden are skipped, never pointing at nothing.
- RTL review covers icons that imply direction, numeric and code isolation, and mixed-language table cells; take before/after screenshots for the report.
- Everything in `quality-bar.md` applies. Before reporting, open every new or changed screen in the browser (Arabic RTL and English, light and dark, desktop and 375 px), run `vendor/bin/phpstan analyse` on the touched paths, and list both in your report.

## Working method
- Start in plan mode: restate the task (and `MODE`/`TASK_NUMBER`) in 5 bullets, list files you will touch, list open questions; wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside Task 26's scope for Task `<TASK_NUMBER>`; note anything else as a follow-up.

## Report back
- Mode and task covered; summary; files changed; keys added per lang file (counts).
- Tours added (route → step ids) and hooks added (file → attribute).
- RTL and wording findings: fixed, and open ones with their owning task.
- Tests run with pass/fail output; decisions assumed (D-xx); follow-ups (`.docx` regeneration, owning-task issues).
- Handoff: for Task 28, the localized pages, tour routes and known gaps; in `user-guide` mode, for Task 29, the guide sections to link in the release announcement.
````
