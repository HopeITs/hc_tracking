---
version: 1
slug: "resources-views-pages-activity-index-blade-php"
primary_target: "resources/views/pages/activity/⚡index.blade.php"
related_targets: []
---

# Activity Log — Surface Brief

Scope: `resources/views/pages/activity/⚡index.blade.php` (Livewire full-page component). Mode: Operate.

Audience: the projects & fundraising manager and program officers at HC Tracking, checking daily "what happened across the org" — program delivery and funding events together — for oversight, accountability, and catching problems early.

Job: scan a live, trustworthy record of everything that happened (who did what, to what, when) across programs and funding, filter it down fast, and jump straight to the source record. Frequency: checked often, sometimes as a first stop in the day.

Constraints (user-confirmed): keep the existing filter set (search, module, actor, date range) and URL query-string behavior; keep the "open record" / "record gone" link behavior; keep server-side pagination. i18n (`lang/`) and accessibility must keep working. Preserve the sidebar shell and brand palette/typeface.

## Direction contract (superseded — see history below)

**THESIS:** The activity log is a reconciled statement, not a social feed: entries read like ledger rows grouped by date under a calm running-totals header, the register an NGO manager already trusts from donor reports and bank statements — refusing both the plain timeline-table default and the earlier wire-desk control-room register.

**OWN-WORLD:** A light "statement summary" strip (`bg-board`/`bg-board-head`/`border-board-line` — the app's existing table-surface tokens) holding four calm running totals (today, last 7 days, busiest module, most active) in restrained type, no flashy accent numerals. Below it, a real table per day (`x-ui.data-table`, reused as-is): a `bg-board-head` section band per day ("Today · 14 entries"), then hairline-divided rows — an immutable `#id` reference column, time, a bordered module tag (text only, no filled color chips), the sentence, a "Linked to" program column, and a right-aligned "Open record" action. Somar Sans throughout; tabular-nums on every numeric/time column; mobile collapses each row into label/value pairs via the data-table component's built-in `@3xl` breakpoint.

**STORY:** The manager opens the page, reads the calm totals strip, then scans the ledger the way they'd scan a bank statement or donor report — trustworthy, dated, referenced — filters to a module/actor/date range, and opens any entry's `#ref` to the underlying record. A program link on a row threads delivery and funding without extra clicks.

**FIRST VIEWPORT:** Page header + filters, then the statement summary strip (single light bordered row, four cells), then the first day-section's table header and opening rows — no dark hero, no illustration filler.

**FORM:** User-directed switch away from the assigned "wire desk" direction to the "Audit Ledger" alternate offered in the same direction round (bank/financial-statement ledger candidate, seed key 5087eccc) — chosen by the user from a visual compare-card round after the wire desk build shipped.

**FINISH:** unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance.

## Build path

Code-led (no image generation tool available in this session/harness) — stated, not asked.

## History

- v1 (shipped, then superseded): "Ops Wire Desk" — dark control-room pulse strip + bulletin-card feed. Kept in git history; superseded 2026-09-24 at the user's request to see the Audit Ledger alternate instead.
- v2 (current): "Audit Ledger" as described above.

## Unresolved decisions

- None outstanding — module tag palette reuses the existing funding/warn/neutral tone tokens already established on v1.
