# Report Tracker Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the flat Report Tracker table with a door screen (Monthly reports · My reporting duties · Donor reports) that opens a view made for each kind.

**Architecture:** One Livewire page (`pages/active-reports/⚡index`) gets a `kind` URL property ('' = doors, `duties`, `monthly`, `donor`). `App\Support\Reporting\ActiveReports` stays the only data source; two small readers shape its rows into a places × months grid and into grouped duties, so every count on a door is computed from the same rows its view shows.

**Tech Stack:** Laravel 13, Livewire 4 single-file pages, Flux, Tailwind 4 + Desk CSS tokens, Pest.

**Spec:** `docs/superpowers/specs/2026-11-26-report-tracker-redesign-design.md` (mockup: `docs/superpowers/specs/2026-11-26-report-tracker-mockup.html`)

## Global Constraints

- PHP is `C:\php85\php.exe` (`.\php.cmd` for the user). Never start a dev server; the user's `composer run dev` owns :8000/:5173.
- **No commits.** Each task ends with its files staged (`git add`), never committed. The branch `reports-edits` already holds unrelated uncommitted work: never stage, revert or reformat files outside each task's list.
- Run only the affected test files (`vendor/bin/pest tests/Feature/<File>.php`), never the full suite.
- `vendor/bin/pint --dirty --format agent` after PHP edits.
- Read `.ai/rules/index.md` and every matching rule before editing a path (pages.md, ui.md, views.md, views-enums.md, views-pages.md, desk.md, css.md, app-2.md, tests.md, routes.md apply).
- Every visible string in `lang/en/active_reports.php` and `lang/ar/active_reports.php`; Arabic plurals `{0}|{1}|{2}|[3,10]|[11,*]`.
- Red and greys only: `--brand` (#be1f24) plus Desk greys; red text uses `text-status-critical` / `--brand-ink`, never `text-primary` on dark.
- No money is read or shown. Access is today's: monthly by `MonthlyReportPreparers` / `MonthlyReport::validatableBy`, donor by `DonorReportPolicy`. Route gate stays `reports.view`.
- Records open as their own page (`monthly-reports.show`, `donor-reports.show`) with `wire:navigate`, never a modal.
- Query count must not grow with the number of reports.
- Full `@php … @endphp` blocks only, never `@php(...)`; page files ≤ 750 lines (split into `_partials`).

## Review Focus

1. **Door count ≠ view count** after filters/search: door numbers are read before filters, view numbers after. Expectation: doors ignore filters; the switcher badges equal the door numbers. Test in Task 4.
2. **A user with neither preparer nor validator duties and no donor access** opens the page: expectation is the Monthly view with an empty state, not a 403 or an empty door screen. Test in Task 4.
3. **Old links** `?tab=needs|all|done` and `?type=donor` (bookmarks, notifications): expectation is they land on the matching kind, never an error. Test in Task 4.
4. **A month range with From after To, or wider than 12 months**: expectation is a field error and the previous range kept, never swapped or silently clamped. Test in Task 5.
5. **A template whose first started month is inside the window** (new project): expectation is "—" before its first month, not "Late". Test in Task 2.

---

### Task 1: Drop hand-recorded reports from the tracker and carry what the views need

**Files:**
- Modify: `app/Enums/ActiveReportType.php` (remove `Report` case)
- Modify: `app/Support/Reporting/ActiveReports.php` (remove `narrative()` and its call; add fields below)
- Modify: `app/Support/Reporting/ActiveReportRow.php`
- Modify: `lang/en/enums.php`, `lang/ar/enums.php` (remove `active_report_type.report`)
- Test: `tests/Feature/ActiveReportsTest.php`

**Interfaces:**
- Produces on `ActiveReportRow` (new promoted constructor params, appended after `$search`):
  - `public ?int $templateId` — monthly: `report_template_id`; donor: `null`.
  - `public bool $returned` — monthly: `returned_at !== null && status === Draft`; donor: `false`.
  - `public bool $needsCheck` — true when it needs the viewer as validator (monthly in `$validatable`), false otherwise.
- Produces `ActiveReportRow::state(): string` returning one of `late`, `returned`, `wait`, `draft`, `done` (monthly) or `overdue`, `due`, `submitted` (donor). Rule: monthly Validated → `done`; Submitted → `wait`; Draft and `$returned` → `returned`; Draft and `$overdue` → `late`; other Draft → `draft`. Donor: `DonorReportState` value.

- [ ] **Step 1:** Update the existing tests: `arpSeedAllTypes()` stops creating narrative reports; tests named "…all three types…" assert monthly and donor only and assert a `Report` row id never appears (`assertDontSeeHtml('data-active-report="report-')`). Add `test('a monthly row knows its template, whether it was returned and its tracker state')` asserting `templateId`, `returned` and `state()` for a validated, submitted, returned, late and current draft row read through `app(ActiveReports::class)->for($meal)` / `for($meal, closed: true)`.
- [ ] **Step 2:** Run `vendor/bin/pest tests/Feature/ActiveReportsTest.php` — expect FAIL (unknown property `templateId`).
- [ ] **Step 3:** Implement the interface above (`report_template_id` and `returned_at` are already selected in `monthly()`; no new query). Remove the `Report` import and the `Report::class` branch in the page's `typeOptions()` so the page still renders (the page is rebuilt in Task 4).
- [ ] **Step 4:** Run the same file — expect PASS (the "query count" test included).
- [ ] **Step 5:** `vendor/bin/pint --dirty --format agent`; `git add` the five files. No commit.

### Task 2: Monthly grid reader

**Files:**
- Create: `app/Support/Reporting/MonthlyTrackerGrid.php` (`php artisan make:class Support/Reporting/MonthlyTrackerGrid --no-interaction`)
- Test: `tests/Feature/MonthlyTrackerGridTest.php` (`php artisan make:test --pest MonthlyTrackerGridTest`)

**Interfaces:**
- Consumes: `ActiveReportRow` (`type`, `templateId`, `periodKey`, `state()`, `name`, `context`, `owner`, `url`, `needsMe`, `programId`).
- Produces: `final class MonthlyTrackerGrid { public static function build(Collection $rows, string $from, string $to): array }` where `$rows` are monthly rows (open + closed), `$from`/`$to` are `Y-m`, and the result is
  `array{months: list<string>, places: list<array{templateId: int, name: string, context: string, owner: ?string, programId: ?int, behind: int, cells: array<string, ?ActiveReportRow>}>, counts: array{open: int, needs: int, late: int, wait: int, done: int}}`.
  `cells` is keyed by every month in `months`; `null` means no report that month. `behind` = number of `late` cells. Places sorted by `behind` desc, then name. `counts` are over the cells in the window only.

- [ ] **Step 1:** Write tests (build rows with the existing `arpTemplate()` helper — move `arpTemplate`, `arpDonorReport` into `tests/Pest.php` only if both files need them; otherwise duplicate the minimal helper): `it('puts each report in its place and month')`, `it('leaves a month without a report empty, not late')` (template whose first month is the 4th of 6 → first three cells `null`), `it('counts behind as the late months of a place')`, `it('sorts the most behind place first')`, `it('counts only cells inside the window')`.
- [ ] **Step 2:** Run `vendor/bin/pest tests/Feature/MonthlyTrackerGridTest.php` — expect FAIL (class not found).
- [ ] **Step 3:** Implement `build()`. Months: every `Y-m` from `$from` to `$to` inclusive (CarbonImmutable `startOfMonth()->addMonth()` loop). Group rows by `templateId`; place name/context/owner from the newest row.
- [ ] **Step 4:** Run the file — expect PASS.
- [ ] **Step 5:** Pint; `git add` both files. No commit.

### Task 3: Reporting duties reader

**Files:**
- Create: `app/Support/Reporting/ReportingDuties.php`
- Test: `tests/Feature/ReportingDutiesTest.php`

**Interfaces:**
- Consumes: `ActiveReportRow` (`needsMe`, `needsCheck`, `type`, `templateId`, `periodKey`, `due`, `overdue`, `url`, `name`, `context`).
- Produces: `final class ReportingDuties { public static function group(Collection $rows): array }` returning
  `array{fill: list<DutyLine>, check: list<DutyLine>, send: list<DutyLine>, total: int, late: int}` and
  `final readonly class DutyLine { public function __construct(public ActiveReportType $type, public string $name, public string $context, public list<string> $months, public CarbonImmutable $due, public bool $late, public string $url) {} }` (same file or its own file in `app/Support/Reporting/`).
  Rules: only `needsMe` rows. Monthly with `needsCheck` → `check`, one line per report. Monthly otherwise → `fill`; rows of the same `templateId` merge into one line with `months` sorted, `due` and `url` of the **oldest** month, `late` if any is late. Donor → `send`. Each group sorted late first, then `due` ascending. `total` counts lines, not reports.

- [ ] **Step 1:** Tests: `it('merges the open months of one place into one line that opens the oldest')`, `it('lists each report to validate on its own line')`, `it('puts donor reports the viewer is responsible for under send')`, `it('ignores rows that do not need the viewer')`, `it('sorts late lines first, then by due date')`.
- [ ] **Step 2:** Run `vendor/bin/pest tests/Feature/ReportingDutiesTest.php` — expect FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run — expect PASS.
- [ ] **Step 5:** Pint; `git add`. No commit.

### Task 4: Page shell — kind, doors, switcher, memory, single-kind skip

**Files:**
- Modify: `resources/views/pages/active-reports/⚡index.blade.php` (rewrite: PHP class + frame only)
- Create: `resources/views/pages/active-reports/_doors.blade.php`, `_switcher.blade.php`
- Modify: `lang/en/active_reports.php`, `lang/ar/active_reports.php` (add keys; remove `tab_*`, `type`, `all_types`, `none_done*`, `more_filters` if no longer used)
- Test: `tests/Feature/ActiveReportsTest.php`

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces on the component (used by Tasks 5–7):
  - `#[Url(except: '')] public string $kind` — '' | `duties` | `monthly` | `donor`.
  - `#[Url(except: '')] public string $search`, `#[Url(except: '')] public string $show` (chip; reset to '' on kind change).
  - `#[Computed] kinds(): list<string>` — `monthly` always; `donor` when `can('viewAny', DonorReport::class)`. `duties` is offered only when `count(kinds) > 1`.
  - `#[Computed] doorCounts(): array{duties: array{total:int, late:int, check:int}, monthly: array{needs:int, late:int, open:int, done:int}, donor: array{needs:int, late:int, open:int, done:int}}` — read from unfiltered rows.
  - `chooseKind(string $kind): void` (validates against kinds, stores `session(['active_reports.kind' => $kind])`), `backToDoors(): void` (sets '' and `session()->forget('active_reports.kind')`).
  - `mount()`: legacy `?tab=needs` → `duties`, `?tab=all|done` → '' ; `?type=monthly|donor` → that kind; then if `$kind === ''` use the session value when still allowed; if only one kind, force it.
- Data hooks: doors `data-tracker-door="{kind}"`, switcher `data-tracker-switch="{kind}"`, single-kind note `data-tracker-single`.
- Door order in markup: monthly, duties, donor (CSS `order:-1` moves duties first under 960px, Task 8).

- [ ] **Step 1:** Replace the tab tests with: `test('the door screen offers only the kinds the viewer can open')` (MEAL sees 3 doors; a Team Member without donor access sees no door screen and lands on monthly with `data-tracker-single`); `test('choosing a kind is remembered until logout and back to the doors forgets it')` (call `chooseKind('donor')`, new component instance in the same session has `kind` = donor; `backToDoors()` then new instance shows doors); `test('door numbers equal the switcher numbers and ignore the search')`; `test('old tab and type links land on the matching kind')` (`?tab=needs` → duties, `?tab=done` → doors, `?type=donor` → donor, `?kind=bogus` → doors); `test('a kind the viewer cannot open falls back to the doors')`.
- [ ] **Step 2:** Run `vendor/bin/pest tests/Feature/ActiveReportsTest.php` — expect FAIL.
- [ ] **Step 3:** Rewrite the component and the two partials per the mockup. Keep `x-desk.register` (title "Report Tracker" + count of the open kind; no `views` prop — the switcher is its own partial so it can show badges). Doors are `<button wire:click="chooseKind('…')">`. Each door's picture: monthly = 6 stacked bars (one per month of the default window, segments done/wait/draft/late from `MonthlyTrackerGrid::build`); donor = date leaf of the earliest open donor report with its countdown; duties = the first 3 `DutyLine`s. Body includes `_monthly-grid`, `_donor-lanes` or `_duties` by kind (stub partials returning an empty-state are fine until Tasks 5–7).
- [ ] **Step 4:** Run the file — expect PASS (query-count test included).
- [ ] **Step 5:** Pint; `git add`. No commit.

### Task 5: Monthly reports view

**Files:**
- Create: `resources/views/pages/active-reports/_monthly-grid.blade.php`, `_monthly-summary.blade.php`
- Modify: `⚡index.blade.php` (filters `program`, `from`, `to`; `#[Computed] grid()` = `MonthlyTrackerGrid::build(...)` over open + closed monthly rows after program/search filters)
- Modify: lang en/ar
- Test: `tests/Feature/ActiveReportsTest.php`

**Interfaces:**
- `#[Url(except: '')] public string $from`, `$to` (`Y-m`). Default window: the 6 most recent **started** months ending at the current month. Validation: both `Y-m`, `from <= to`, at most 12 months → else `addError('from', …)` and keep the previous range.
- Chips (`show`): '' (Open) · `needs` · `late` · `wait` · `done`. A non-matching cell gets class `is-dim` (rows never removed).
- Cell markup: `<a href="{{ $row->url }}" wire:navigate class="rt-cell" data-state="{{ $row->state() }}" data-tracker-cell="{{ $row->key() }}">`; empty cell `<span class="rt-cell" data-state="none">—</span>`. Late label "Late · N days" from `$row->due`.
- Summary: last finished month submitted X of Y (submitted + validated over cells with a report), late, to validate, current month open; returned drafts count with late.

- [ ] **Step 1:** Tests: `test('the monthly grid shows each place by month with its state')` (assert `data-tracker-cell` + `data-state` for late, wait, done; `data-state="none"` for a month with no report); `test('a chip dims cells that do not match and keeps every row')`; `test('the program filter and the search narrow the places')`; `test('a month range with From after To or longer than 12 months is refused')`.
- [ ] **Step 2:** Run — expect FAIL.
- [ ] **Step 3:** Implement per the mockup's monthly view (sticky first column, horizontal scroll inside its own container, legend under the grid, "N months behind" under the place name when `behind > 1`).
- [ ] **Step 4:** Run — expect PASS.
- [ ] **Step 5:** Pint; `git add`. No commit.

### Task 6: Donor reports view

**Files:**
- Create: `resources/views/pages/active-reports/_donor-lanes.blade.php`
- Modify: `⚡index.blade.php` (`donor` filter; `#[Computed] donorLanes(): array{overdue: list<ActiveReportRow>, soon: list<…>, later: list<…>, submitted: list<…>}`)
- Modify: lang en/ar
- Test: `tests/Feature/ActiveReportsTest.php`

**Interfaces:**
- Lanes: `overdue` (state overdue), `soon` (due, `due <= today + 30 days`), `later` (due, beyond), `submitted` (closed donor rows with `due` in the last 12 months, newest first). Sorted by `due` ascending in the open lanes.
- Chips (`show`): '' (Everyone's) · `mine` (`needsMe`); `mine` filters the three open lanes only.
- Card: `data-tracker-card="{{ $row->key() }}"`, link to `$row->url` with `wire:navigate`; donor name from the row (add `public ?string $donorName` to `ActiveReportRow` in this task if not derivable — set in `ActiveReports::donor()`, `null` for monthly).

- [ ] **Step 1:** Tests: `test('donor reports sit in lanes by due date')` (one each: overdue, due in 10 days, due in 60 days, submitted 2 months ago, submitted 14 months ago → last one absent); `test('Mine to send keeps only the viewer’s donor reports')`; `test('the donor filter narrows the lanes')`.
- [ ] **Step 2:** Run — expect FAIL.
- [ ] **Step 3:** Implement per the mockup's donor view.
- [ ] **Step 4:** Run — expect PASS.
- [ ] **Step 5:** Pint; `git add`. No commit.

### Task 7: My reporting duties view and the `/reporting-duties` address

**Files:**
- Create: `resources/views/pages/active-reports/_duties.blade.php`
- Modify: `⚡index.blade.php` (`#[Computed] duties()` = `ReportingDuties::group($this->rows)` after search)
- Modify: `routes/web.php:240-241` → redirect `reporting-duties` to `active-reports.index` with `kind=duties` (keep the route name `reporting-duties.index`)
- Modify: `tests/Feature/NavigationTest.php:84-88` (expect the new target)
- Modify: lang en/ar
- Test: `tests/Feature/ActiveReportsTest.php`

**Interfaces:**
- Groups render in order fill · check · send, empty groups hidden; whole-view empty state "Nothing waits on you" when `total === 0`.
- Line: `data-tracker-duty`, button text: fill multi-month "Open oldest", fill single "Fill in", check "Validate", send "Prepare"; months text "N months open: Jun – Oct" via `trans_choice`.

- [ ] **Step 1:** Tests: `test('my reporting duties group what to fill in, check and send')`; `test('open months of one place show as one line that opens the oldest')`; update NavigationTest `the old My Reporting Duties address leads to My reporting duties`.
- [ ] **Step 2:** Run both files — expect FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run both — expect PASS.
- [ ] **Step 5:** Pint; `git add`. No commit.

### Task 8: Styles, Arabic, and the look check

**Files:**
- Create: `resources/css/report-tracker.css`; Modify: `resources/css/app.css` (add `@import './report-tracker.css';` after `monthly-matrix.css`)
- Modify: `lang/ar/active_reports.php` (complete), `lang/en/active_reports.php` (drop dead keys)
- Test: `tests/Feature/LocalizationTest.php` (run only)

**Interfaces:**
- Class prefix `rt-` (`.rt-doors`, `.rt-door`, `.rt-door.is-duties`, `.rt-switch`, `.rt-cell[data-state]`, `.rt-lane`, `.rt-card`, `.rt-duty`), built only from Desk tokens (`--paper`, `--sunk`, `--ink`, `--ink-2`, `--ink-3`, `--faint`, `--line`, `--line-2`, `--brand`, `--brand-ink`); dark mode comes from the tokens, no `dark:` greys. Logical properties only (RTL). Doors: 3 columns, 1 column under 960px with `.is-duties { order: -1 }`. `prefers-reduced-motion` turns transitions off.

- [ ] **Step 1:** Run `vendor/bin/pest tests/Feature/LocalizationTest.php` — fix any missing Arabic key until PASS.
- [ ] **Step 2:** Write the CSS to match the mockup.
- [ ] **Step 3:** Look check with the "preview an auth-gated page" recipe in `.ai/rules/pages.md` (render `/active-reports`, `?kind=monthly`, `?kind=donor`, `?kind=duties` to `public/__preview.html`): desktop and 390px, light and dark, LTR and Arabic. Ask the user to run `npm run build` if styles are missing. Delete `public/__preview.html` after.
- [ ] **Step 4:** Run all touched test files together: `ActiveReportsTest`, `MonthlyTrackerGridTest`, `ReportingDutiesTest`, `NavigationTest`, `LocalizationTest` — expect PASS.
- [ ] **Step 5:** Record a rule with Boost `record-rule` (glob `resources/views/pages/active-reports/**`, title "Report Tracker: doors, kinds, no hand-recorded reports") summarising decisions 1–6 of the spec, and update the M-02 paragraph in `.ai/rules/reports.md`. `git add`. No commit; ask the user to review the staged files and to run the full suite themselves.
