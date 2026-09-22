---
version: 1
slug: "pages-funding-agreements-index-blade-php-bbdfd8e1"
primary_target: "resources/views/pages/funding-agreements/⚡index.blade.php"
related_targets: []
---

# Funding agreements surface brief

Scope: `/funding-agreements`, the agreement directory. Mode: Operate.
Audience: projects & fundraising manager (where is every promised dollar, what is late, what lands next month, who to chase) and officers finding one agreement fast. Job: see collection health per currency, spot overdue and upcoming tranches, open or edit an agreement, record a new one. Agreements are never deleted (tranches cascade). Currencies (USD/EUR/SYP) are never summed or drawn on one scale. Every string in lang/en + lang/ar; RTL via logical properties.
User feedback on round 1 (Collection Waterfall): the waterfall bars felt heavy, and the agreements started too far down. Keep what is useful (header filters, stage filters, tranche-on-time idea).

## Direction contract

THESIS: The page is one living cash-flow calendar: agreements are lanes, tranches are amount pills on their dates, today is a single red line, and a monthly inflow row shows what lands when. It refuses the waterfall bar chart, the stat-tile KPI row and the flat agreement table.

OWN-WORLD: Proposals world: white ground, Somar Sans, tabular numerals, hairlines, no boxed cards. Funding teal for landed money, amber stripes for pending, HC red only for overdue/failed and the today line. Received rings, pill tranches, sticky lane headers.

STORY: The manager reads one ledger line per currency (committed, received share, pending, overdue, not scheduled) with a single thin collection line, taps a stage to narrow lanes, scans the calendar against today, sees next month's expected inflow, expands a lane for its tranche ledger, then opens or edits.

FIRST VIEWPORT: Page header (title, count, search/donor/currency, Record agreement). Ledger line: huge figures, thin segmented collection line, stage filters inline. Calendar immediately below and filling the viewport: sticky identity column (donor · proposal, budget, ring, projects, accounts), month grid scrolled so today is about a third in, monthly inflow totals row on top.

FORM: Money Calendar, position 7 on the ordered list, seed key 0644ff06 (surface round 2, user locked THE ROLL). Code-led. Signature interaction: calendar opens centred on today with month/quarter zoom; hover or focus a tranche pill for date, amount, status, account; click a lane to expand its tranche ledger inline; click a month's inflow total to highlight that month's tranches.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
