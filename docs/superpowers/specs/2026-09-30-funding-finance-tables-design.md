# Funding pages, table first — design spec

Date: 2026-09-30 · Branch: `Security-handeling-youssef` · Changes stay uncommitted for review.

## Goal

The financial manager can answer any money question from the Funding group in the sidebar by reading a table: every amount visible and exact, every column sortable, every dimension filterable (including year), totals always on screen, and the filtered rows exportable. Nothing about money sits behind a chevron, a hover, a rounded figure or a picture.

## Why (what is wrong today)

1. Every page opens on a visual (shrink bars, cash-flow calendar, funding flow, dial, account cards); the rows come after it or inside it.
2. Fees, exchange rate, gross and net received, sent and received dates are hidden behind a per-row chevron (Transfers), an expanding lane (Agreements), a `<details>` block (donor page) or a tooltip.
3. Amounts are rounded or shortened ("1.2M", no decimals) outside those hidden blocks.
4. No page has a real table: no sortable headers, no totals row, no "outstanding" column.
5. Filtering is thin: no year or date range anywhere, no donor or currency filter on Transfers, status lives in clickable figures. No export.
6. Accounts is a card grid with no way to read what landed where without leaving the page.

## Scope

In scope — the five Funding sidebar items and the detail pages behind them:

| # | Page | File |
| --- | --- | --- |
| 1 | Transfers | `pages/transfers/⚡index.blade.php` |
| 2 | Funding agreements | `pages/funding-agreements/⚡index.blade.php` |
| 3 | Accounts | `pages/admin/⚡accounts.blade.php` |
| 4 | Donors | `pages/donors/⚡index.blade.php` |
| 5 | Proposals | `pages/proposals/⚡index.blade.php` |
| 6 | Agreement detail | `pages/funding-agreements/⚡show.blade.php` |
| 7 | Donor detail | `pages/donors/⚡show.blade.php` |
| 8 | Proposal detail, Funding tab only | `pages/proposals/⚡show.blade.php` |

Out of scope:

- The create/edit modals (`x-funding.agreement-modal`, `x-funding.transfer-modal`, `x-donors.form-modal`) and their traits: unchanged.
- The other seven tabs of the proposal detail page, its header and its warnings.
- Database schema: no migration. No new Composer or npm package.
- A USD-equivalent or any cross-currency total.
- A separate account statement page (the Transfers table filtered to one account is the statement).
- Logging exports in the activity log (the log is model-event based today; can follow as its own change).
- The executive summary and dashboard.

## Decisions (made with the user, 2026-09-30)

1. **Table first, visuals optional.** Every page opens on the table. The existing visual is kept, unchanged, behind a `Table | Overview` switch.
2. **CSV export**, no new package. Exports every row the filters match, not only the visible page.
3. **Currencies are never added together.** One totals line and one footer row per currency.
4. **All four reading priorities count**: received vs outstanding, payment schedule, fees and exchange loss, per bank account.
5. **As many filters as possible, year included.**
6. **Year on Agreements, Donors, Proposals and Accounts does both**: by default it adds "Due in {year}" and "Received in {year}" columns beside the lifetime figures; a `Whole agreement | {year} only` switch recalculates every tranche-based figure for that year instead.
7. **Transfers can be grouped** by agreement, donor, account or month, with a subtotal per group.
8. **Per-account reading = Transfers filtered to that account.** No new page.
9. **Transfers opens newest scheduled date first.**

## Page anatomy (identical on all five index pages)

Top to bottom:

1. **Header** — `x-ui.page-header`. Title and count badge. `filters` slot: search plus the long-list pickers (at most three controls, all `x-ui.header-filter-select`). `actions` slot: `Table | Overview` switch (`x-ui.view-toggle`), `Export CSV`, then the page's primary action.
2. **Filter rows** — in the header's `summary` slot, always visible, never collapsed. One labelled row per dimension, as pills: year, status (with live counts), currency, flags, and on Transfers the date basis and group-by. Date range and amount range are inline inputs. A single `Reset filters` appears when anything is set.
3. **Totals line** — one line per currency: large typographic figures with a small label above each, no boxes. Reflects every active filter.
4. **Register** — the table. Footer: one totals row per currency, summing exactly the rows the filters match.
5. **Pagination** — 25 rows by default; 25 / 50 / 100 selector.

Rules:

- Pills for lists of eight values or fewer; a picker only for long lists (donor, proposal, agreement, account, country).
- Each pill row selects one value at a time (one status, one currency, one flag); choosing the active pill again clears it. Rows combine with each other.
- Every filter, the sort, the page size, the grouping and the view live in the URL (`#[Url]`), so a filtered table can be bookmarked and shared. Values arriving from the URL are normalised in `mount()`; an unknown value falls back to the default.
- In table view every total respects every filter, status included, so the totals line and the footer always agree with the rows. Status pill counts ignore only the status filter, so the other statuses stay countable. The Overview view keeps its documented behaviour (its ledger ignores the stage filter — see `.ai/rules/transfers.md`).
- The same filters drive both views.
- Overview queries run only in Overview view; table queries only in table view.

## Formatting (every table)

- Amounts: exact, two decimals, thousands separators, tabular numerals, right-aligned, `dir="ltr"` so they read the same in Arabic. Never shortened.
- Currency: its own narrow column, so an amount column holds numbers only.
- Negative differences: a true minus sign and the critical colour. Overages: a plus sign and the funding colour.
- Empty: an em dash. A received tranche with no net amount reads "Not recorded" in the warn colour (it is not zero and not a dash).
- Dates: `formats.date`, translated; sorting uses the raw date.
- Percentages: always `DonorProposal::percentLabel()` (floored, "<1" instead of 0).
- Amount columns sort by currency first, then amount, so figures in different currencies are never interleaved.
- Status: a pill with a dot, in the shared tranche vocabulary (`TransfersTracking::state()`): Upcoming, Overdue, In transit, Received, Failed.

## Table behaviour

- Header row sticks under the app bar while the page scrolls. The first column sticks while the table scrolls sideways (only below the width where all columns fit).
- Sortable headers are buttons with `aria-sort`; first click sorts descending for amounts and dates, ascending for text; second click reverses.
- Rows are 40px, separated by hairlines, highlighted on hover. No outer card, no zebra.
- Below 768px each row becomes a stacked label/value block (the `data-label` pattern already used in the agreements page); the totals line wraps.
- A row's own name is plain text; its detail page opens from the eye button placed first in the actions cell. Links to related records inside a row stay underlined links, each rendered only with that page's view permission.
- `wire:loading` dims the table body while a filter round trip runs.
- No rows: `x-ui.empty-state` with a reset button; a page with no records at all keeps its current first-record empty state.
- Light and dark (Graphite tokens), English and Arabic (RTL).

## Shared kit

PHP:

| Unit | Purpose |
| --- | --- |
| `app/Livewire/Concerns/FiltersFinanceRegister.php` | URL-bound `view`, `year`, `figures`, `from`, `to`, `currency`, `sortBy`, `sortDir`, `perPage`; normalisation; `sort()` toggle; page reset on any change; `yearOptions()` (from the earliest to the latest tranche date). Each page declares its sortable columns as a whitelist of key → SQL expression and its default sort. |
| `app/Support/CsvExport.php` | `download(filename, headings, rows)` → streamed response. UTF-8 with BOM (Excel, Arabic). Cells starting with `=`, `+`, `-` or `@` that are not numbers are prefixed with `'` (formula injection). Amounts as plain decimals, dates as `Y-m-d`. Rows are read lazily. |
| `TransfersTracking` query scopes | `inState()`, `settled()`, `landedShort()`, `missingNet()`, `datedWithin(basis, from, to)`. One SQL definition of the tranche states, matching `state()`. Replaces the `stageSql()` copy in the Transfers page and the fragments in the Agreements page. |
| `DonorProposal::scopeWithMoney(?int $year)` | Adds the agreement money columns as SQL aggregates: scheduled, received (net, own currency), in transit, overdue, failed, next due, tranche count, net-missing count, and — when a year is given — due in year and received in year. Used by the Agreements table, grouped for Donors and Proposals, and by the exports, so a sorted, exported or totalled figure is always the same figure. |

Blade, in `resources/views/components/funding/`:

| Component | Purpose |
| --- | --- |
| `x-funding.filter-row` | A label and its controls on one line. |
| `x-funding.pills` | Pill group bound to a Livewire property; optional count per pill; `role="radiogroup"`. |
| `x-funding.date-range` | From / to date inputs. |
| `x-funding.totals` | Per-currency line of labelled figures with optional tone and note. |
| `x-funding.register` | Table shell: caption, sticky head, body, `foot` slot, phone stacking, loading state. |
| `x-funding.register.th` | Sortable column header. |
| `x-funding.register.group` | Group header row with its subtotal cells. |

Reused as they are: `x-ui.page-header`, `x-ui.view-toggle`, `x-ui.header-filter-select`, `x-ui.input`, `x-ui.button`, `x-ui.enum-badge`, `x-ui.empty-state`. `x-ui.data-table` is not reused: it is a framed card without a footer, sticky head or group rows, and four other pages depend on its current look.

Each page's existing visual moves, markup unchanged, into a partial beside it (`_overview.blade.php`), following the `_list-group.blade.php` convention.

CSS: one small block in `resources/css/app.css` for the register (sticky head and first column, phone stacking). Existing `money-*`, `cal-*`, `ledger-*` rules stay for the Overview view.

## Definitions (unchanged, restated so every table agrees)

- **Received** = `net_amount_received` of received tranches that landed in the agreement's own currency. A received tranche with no net amount counts as nothing landed and is flagged.
- **Scheduled** = Σ `payment_amount` of every tranche in the agreement's own currency, whatever its state.
- **Not scheduled** = budget − scheduled (never below zero).
- **Outstanding** = budget − received (never below zero).
- **Shortfall** = Σ of each settled tranche's own loss; an overage never cancels it and is shown as its own figure.
- **Settled** = received, net recorded, landed in the currency it was sent in.
- **Due in {year}** = Σ `payment_amount` of tranches whose scheduled date is in that year. **Received in {year}** = Σ net received of tranches whose receive date is in that year.
- **{year} only** mode: every tranche-based figure is computed from tranches scheduled in that year. Budget stays the whole agreement and is labelled so; Not scheduled and Outstanding are hidden in this mode because they only mean something for the whole agreement. Rows with no tranche in the year are hidden in both modes.

## Pages

### 1. Transfers

Header pickers: search (donor, proposal, reference), donor, account.

Filter rows:

| Row | Controls |
| --- | --- |
| Year | All, then each year that has a tranche |
| Dates | Date is: Scheduled / Sent / Received · From · To. On this page a year pill is a shortcut that sets From and To to that calendar year; typing a custom range clears the year pill. |
| Status | All, Upcoming, Overdue, In transit, Received, Failed — with counts |
| Currency | All, USD, EUR, SYP (currency sent) |
| Flags | Landed short, Net not recorded, Landed in another currency, No account |
| Amount | Min · Max (amount sent) |
| Group by | None, Agreement, Donor, Account, Month |

An agreement deep link (`?agreement=`) shows as a removable chip, as today.

Columns: Status · Donor · Proposal and agreement reference · Account · Scheduled · Sent on · Received on · Currency · Amount sent · Fees · Exchange rate · Gross received · Net received · Difference · Days · Edit.

- Fees and Net received print their own currency code when it differs from the currency sent.
- Difference = net − sent, only for settled tranches; otherwise a dash. A tranche that landed in another currency shows a "Converted" tag instead of a difference.
- Days: "12 days overdue", "in transit 5 days", "landed in 4 days".

Totals line, per currency sent: Expected · Sent · Received (net) · Fees · Shortfall · Arrived over (only when above zero) · Outstanding. Notes under it for tranches that landed in another currency or have no net recorded, with their amounts.

Default sort: scheduled date, newest first, undated last. A "Needs attention first" order (the current default: overdue, failed, short, unrecorded, in transit, rest) stays available as a pill beside the status row.

Grouping: rows are ordered by group, then by the chosen sort. Each group opens with a header row carrying its label, row count and subtotal per currency for Amount sent, Fees, Net received and Difference. Subtotals come from one grouped query over all filtered rows, so a group split across two pages shows its true subtotal on both. Month grouping uses the chosen date basis.

URL: keeps `search`, `account`, `agreement`, `stage`, `edit`. Adds `donor`, `currency`, `year`, `dateBasis`, `from`, `to`, `min`, `max`, `flag`, `group`, `sortBy`, `sortDir`, `perPage`, `view`. Retires `arStatus` (an old bookmark with `arStatus=received|failed` maps to the matching `stage`) and `sort` (old values map to `sortBy`). Nothing in the app links with `arStatus` or `sort`.

Overview view: today's ledger lines and ladder.

### 2. Funding agreements

Header pickers: search, donor, proposal.

Filter rows: Year (+ `Whole agreement | {year} only`) · Status (existing stages with counts: Overdue, Failed, Upcoming, Not scheduled, In transit, Fully received) · Currency · Flags (Donor report overdue, Net not recorded, Tranche in another currency, No tranches yet) · Budget min / max.

Columns: Donor · Proposal (with its status badge) · Reference · Currency · Budget · Scheduled · Received · % received · In transit · Overdue · Failed · Not scheduled · Outstanding · Next due · Tranches (count, linking to Transfers filtered to this agreement) · Donor reports (overdue count, or next due date) · View · Edit. With a year chosen: Due in {year} · Received in {year}.

Totals line and footer, per currency: Budget · Scheduled · Received · In transit · Overdue · Failed · Not scheduled · Outstanding.

Default sort: next due date, soonest first, none last (today's order).

URL: keeps `search`, `donor`, `currency`, `stage`. Adds `proposal`, `year`, `figures`, `flag`, `min`, `max`, `sortBy`, `sortDir`, `perPage`, `view`.

Overview view: today's collection ledger and cash-flow calendar.

### 3. Accounts

Header pickers: search (name, bank, last four digits — as today), country.

Filter rows: Year · State (All, Open, Closed) · Currency · Flags (Has transfers, No transfers, Money in transit).

Columns: Account · Bank · Country · Currency · Account number (masked or full, by the existing `showsFullAccountNumbers` rule) · State · Opened · Closed · Transfers (count, linking to Transfers filtered to this account) · Received · In transit · Upcoming · Last receipt · View transfers · Edit · Close. With a year chosen: Received in {year}.

Received, In transit and Upcoming are one line per currency when an account took money in more than one.

Totals line and footer, per currency: Received · In transit · Upcoming, plus counts of open and closed accounts.

Default sort: account name.

Overview view: today's stat tiles, donut and cards.

### 4. Donors

Header pickers: search (name, country, contact), country.

Filter rows: Year (+ mode switch) · Relationship (All, Funding now, Prospects) · Type (All, Bilateral, Foundation, Individual, Corporate, Unclassified) · Currency · Flags (Has overdue transfer, Follow-up due).

One row per donor per currency, so amounts stay in aligned columns and export one to one; a donor's name repeats on its second line in the muted colour. A prospect has one row with dashes. The count badge counts donors.

Columns: Donor (with type badge) · Country · Contact (person, email, phone) · Agreements · Currency · Committed · Received · % received · Outstanding · Overdue (amount and count) · Next transfer (date and amount) · Last received · Follow-up (next step and due date, critical when overdue) · View · Edit · Delete (prospects only, as today). With a year chosen: Due in {year} · Received in {year}.

Money columns and their queries are skipped without `funding-agreements.view`; transfer columns without `transfers.view` (existing rule).

Totals line and footer, per currency: Committed · Received · Outstanding · Overdue.

Default sort: donor name. The existing `sort` values (`name`, `agreements`, `next_due`, `follow_up`) map to `sortBy`.

Overview view: today's pulse strip, wallet, upcoming transfers and donor list.

### 5. Proposals

Header pickers: search, donor.

Filter rows: Year (+ mode switch) · Status (eight statuses, with counts) · Phase (five) · Currency · Flags (No agreement yet, Has overdue transfer, Donor report overdue).

One row per proposal per currency, as for donors; a proposal with no agreement has one row with dashes.

Columns: Proposal · Status · Phase · Planned start · Planned end · Donors · Agreements · Currency · Committed · Received · % received · Outstanding · Overdue · Next due · Projects · View · Edit. With a year chosen: Due in {year} · Received in {year}.

Money columns and queries are skipped without the finance permissions; the table then shows the proposal columns only.

Totals line and footer, per currency: Committed · Received · Outstanding · Overdue.

Default sort: planned start, newest first.

Overview view: today's pipeline, funding position, funding flow and dossier list.

### 6. Agreement detail

Header and the attention list stay, in both views.

Table view, top to bottom:

1. Totals line: Budget · Scheduled · Received · Fees and exchange loss · In transit · Overdue · Not scheduled · Outstanding.
2. Tranches: the Transfers columns without Donor and Proposal, always open, with footer totals, "Add tranche", edit per row and CSV export. Sorted by scheduled date, oldest first (a payment schedule).
3. Donor reports: Title · Type · Due · Submitted · State · actions (as today).
4. Connected records (proposal, funded projects, accounts, other agreements of this donor) as a compact two-column list.
5. Activity.

Overview view: today's dial, tranche journeys and timeline.

### 7. Donor detail

Header, contact block and the overdue list stay, in both views.

Table view: totals line per currency (Committed · Received · Outstanding · Overdue); Agreements table (the Agreements columns without Donor); All tranches table (the Transfers columns without Donor) replacing the `<details>` ledger, with CSV export; Donor reports table.

Overview view: today's money calendar and agreement cards.

### 8. Proposal detail — Funding tab

The tab's visuals move behind the same switch inside the tab. Table view: totals line per currency, Agreements table (without Proposal), Tranches table (without Proposal), both with footers. Everything else on the page is untouched.

## Export

- An `Export CSV` button on every table (the five index pages, and the tranche tables on the agreement and donor detail pages).
- Exports all rows matching the current filters, in the current sort, with every column of the table plus the raw state. Headings are in the current language.
- File name: `{page}-{Y-m-d}.csv`, e.g. `transfers-2026-09-30.csv`.
- The export action re-checks the page's view permission and the money permissions server-side, and omits the same columns the table omits.
- Full account numbers are exported only to users who see them on screen.

## Access

No permission changes. The `Financial` role already holds every view involved. "Hide money, keep names" (`.ai/rules/pages.md`) applies to tables and exports alike: without the permission the query is not run, not merely hidden.

## Tests

- No existing test is deleted. Tests that assert on a visual (`data-ledger`, `data-bead`, `data-ledger-figure`, calendar lanes…) are pointed at the Overview view; tests of filters, permissions and money definitions keep passing against the table.
- New, per page: every filter narrows the rows; each sortable column orders them; totals line and footer equal the filtered rows per currency and never mix currencies; year columns and `{year} only` mode; export returns all filtered rows, not one page, and honours permissions; an unknown URL value falls back to the default; money columns are absent without permission.
- Transfers, additionally: group subtotals are whole-group even across pages; the regression "a tranche that arrived over never cancels one that arrived short" holds for the table totals.
- `FundingWorkflowTest` stays the pin for the shared definitions; it gains a check that the Agreements, Donors and Proposals tables print the same received, outstanding and percentage for the same data.
- Only the affected test files are run, never the whole suite.

## Build order

Each phase ends with its pages working, its tests green and nothing committed until reviewed.

| Phase | Delivers |
| --- | --- |
| 0 | Shared kit: trait, CSV export, model scopes, Blade components, CSS. Tested on its own. |
| 1 | Transfers (the template every later page follows). Review the look here before going on. |
| 2 | Funding agreements |
| 3 | Accounts |
| 4 | Donors |
| 5 | Proposals |
| 6 | Agreement detail, donor detail, proposal Funding tab |
| 7 | Pass over all eight pages: light / dark, English / Arabic, phone width, keyboard, query counts; record the new rules with `record-rule`. |
