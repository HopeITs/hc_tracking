# Report Tracker redesign — design

User request 2026-11-26 (branch `reports-edits`). "Refactor and upgrade the UX design concept of this report
tracker page and make it dynamic and useful and easy to use, easy to read and easy to track." When a user opens
the page they first choose what they want to see, limited to what their role can open; the choice then filters
the page into a view made for that kind of report.

Approved clickable mockup (v5): `docs/superpowers/specs/2026-11-26-report-tracker-mockup.html`
(also https://claude.ai/artifact/MLXAXHFBgAKFQabC3koNgc, private).

## Why

Today `active-reports.index` is one flat table of three unrelated sources (monthly, donor, "report"), with tabs
Needs me / All / Done and filters behind "More filters". Every row looks the same, so the page cannot answer the
questions people actually come with:

- "What do *I* have to do, and what is late?"
- "Which places are behind on their monthly reports, and by how many months?"
- "Which donor report is due next, and who owns it?"

Success: a user lands, sees at a glance how much waits on them, picks a door, and in one look at that view knows
what is late, what is waiting and who owns it. Every cell, card and row opens the report's own page.

## Decisions taken with the user (2026-11-26)

1. **Three doors on one line:** Monthly reports · **My reporting duties** (middle, dark, emphasised) · Donor
   reports. On narrow screens they stack with My reporting duties first.
2. **Door screen, then a switcher.** The first visit shows the doors. After a choice, a compact switcher at the
   top hops between kinds; "All report kinds" goes back to the doors. The last choice is remembered.
3. **One kind only → no door screen.** A user who can open only one kind lands straight in it, with a one-line
   note saying why; no switcher, no back link.
4. **Each kind gets its own view** (below), not one shared table.
5. **"Indicator updates" is dropped from the tracker.** Data check (2026-11-26): every one of the 53 targets
   (4 annual-plan, 49 proposal) is covered by a monthly report template line, and all 37 hand-recorded reports
   sit on those same targets. The tracker would only show a second path to the same figures. They stay visible on
   the Reports page.
6. **Name: "My reporting duties"** for the personal view (not "Waiting on me"). The old `/reporting-duties`
   address, which today redirects to Donor reports, will point to this view instead.

## Goals

1. Door screen: per door, how many wait on me, late / open / done counts, and a small picture (monthly: 6-month
   stacked bars by state; donor: next due date leaf; my duties: the next 3 items).
2. **Monthly reports view:** places × months grid (default the last 6 started months, current month
   highlighted). Each cell is one monthly report in one state: Late (red, "Late · N days"), To validate
   (outlined), Draft (dashed), Validated (soft grey ✓), "—" (no report that month). A place with 2+ late months
   shows "N months behind". Four summary figures above: last month's submitted X of Y with a progress bar, late,
   to validate, current month open. State chips (Open · Needs me · Late · To validate · Validated) dim
   non-matching cells instead of removing rows, so the grid never jumps. Filters: search, program, month range.
3. **Donor reports view:** four lanes by due date: Overdue · Due in 30 days · Later · Submitted. Card: donor,
   title, proposal, owner, due date + countdown. Chips: Everyone's · Mine to send. Filter: donor. No money.
4. **My reporting duties view:** everything waiting on the viewer, grouped by what to do: To fill in · To check ·
   To send to a donor, oldest/late first. Consecutive open months of the same place become one line
   ("5 months open: Jun – Oct", button "Open oldest").
5. Same access as today, nothing added: monthly by preparer/validator (`MonthlyReportPreparers`,
   `MonthlyReport::validatableBy`), donor by `DonorReportPolicy`. Fixed query count whatever the data size.
6. English and Arabic (RTL), light and dark (Graphite), phone to 4K. Red and greys only.

## Non-goals

- No change to the monthly report, donor report or report record pages, their workflows or permissions.
- No change to the Reports page (`reports.index`) or its "Record a report" button. Retiring that path (it can
  double count a figure on a target) is a separate decision for later.
- No new database tables.

## Anti-goals (a failure even if it "works")

- Any number on a door that disagrees with what the view shows after clicking it.
- A door or tab for a kind the user cannot open, or a door that opens onto an empty "not allowed" view.
- Money anywhere on the page.
- The grid reflowing or rows disappearing when a chip is clicked.
- A noticeable wait on click (thunder-speed rule): switching kind or chip must feel instant.

## Approaches considered

1. **One Livewire page with a `kind` URL property (chosen).** `?kind=` is '' (doors), `duties`, `monthly`,
   `donor`; partials per view. One component, one data source (`ActiveReports`), counts shared between doors and
   views, so they cannot disagree. Deep links and Back work.
2. Three separate routes/pages. Cleaner files, but door counts would be computed in a fourth place and could
   drift, and switching kinds becomes a full navigation.
3. Keep the table and add a door overlay. Cheapest, but doesn't give each kind a view of its own, which the user
   chose explicitly.

## How it's built (decided)

- **Data:** `ActiveReports` loses `narrative()` and the `Report` case leaves `ActiveReportType`. It gains what
  the views need: monthly rows carry their template id, period and whether the report was returned; the grid
  reads open **and** validated monthly reports for the month window. A small reader builds the grid
  (places × months) from those rows; door/summary counts come from the same rows.
- **Page:** `pages/active-reports/⚡index` keeps the route and `reports.view` gate. Inside `x-desk.register`
  for the frame; partials `_doors`, `_switcher`, `_monthly-grid`, `_donor-lanes`, `_duties`, each ≤ 750 lines.
- **CSS:** a page stylesheet in `resources/css/` built from Desk tokens (`--paper`, `--ink*`, `--line*`,
  `--brand`), following css.md.
- **Strings:** all in `lang/en/active_reports.php` and `lang/ar/active_reports.php`; plurals with Arabic ranges.
- **Route:** `/reporting-duties` redirects to `active-reports.index?kind=duties`.
- **Tests:** update `tests/Feature/ActiveReportsTest.php` (three types → two; tabs → kinds) and add tests for:
  doors per role, the single-kind skip, door counts equal view counts, grid cells and states, donor lanes,
  duties grouping, the redirect, and the query count staying flat.

## My calls (minor; correct me if wrong)

- Done reports aren't a separate tab any more: validated months show in the grid, submitted donor reports in
  the Submitted lane (last 12 months).
- A monthly draft that MEAL **returned** shows as "Returned" (red outline) in the grid and counts as needing the
  preparer.
- Program managers who are responsible for no donor report and lack funding view see no Donor door (today's
  policy decides; the mockup's role list is illustrative).
- The month window defaults to the last 6 started months; the range filter allows up to 12.

## Answered (2026-11-26)

1. **Last choice is remembered until logout**, in the login session (`session('active_reports.kind')`). A new
   login starts on the doors. No database change.
2. **Phone order:** stacked doors put My reporting duties first; desktop keeps it in the middle.
3. **Desk card** counts: a separate task later, not in this build.
