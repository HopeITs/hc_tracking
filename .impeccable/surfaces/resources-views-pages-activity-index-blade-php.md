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

## Direction contract

**THESIS:** The activity log is not a scrollable audit table — it's the organization's wire desk: a live feed of dated bulletins grouped by module, read the way a newsroom rundown or ops desk reads its wire, refusing the generic "GitHub commit list" / plain timeline-table default this category always ships.

**OWN-WORLD:** A dark control-room header strip (secondary-dark ground) holding glanceable cross-check stat dials — today's activity count, per-module counts, top actors — in the brand red as the single hot accent against the grays. Below it, a bright working feed on white: bulletins grouped by day under teletype-style date rules, each bulletin a compact card with a module tag (colored chip), actor, sentence, timestamp, and a connection line when the event links to funding/program counterparts (e.g. a transfer bulletin shows the project/milestone it funded inline). Somar Sans throughout; condensed caps for module tags and section date-rules; monospace-leaning tabular figures for counts/timestamps only.

**STORY:** The manager opens the page and instantly reads the org's pulse for today (dial strip), then scans the wire for anything notable, filters to a module/actor/date range, and drills into any bulletin to the underlying record. Connections between money and delivery are visible without extra clicks.

**FIRST VIEWPORT:** Sticky header strip (page title + filters) directly above the dial strip (3–5 stat dials in a row, brand-red numerals on dark ground). Below the fold-line, the day-grouped bulletin wire begins immediately — no empty hero, no illustration filler.

**FORM:** Assigned direction (index 5 of 7 self-derived grounded candidates: bank ledger, ship's log, ATC flight-strip board, courtroom exhibit log, newsroom wire/assignment desk, hospital chart, lab notebook), raised with one borrowed discipline from the declined "night-flight instrument six-pack" challenger (seed key 5087eccc): the header's glanceable cross-check dial ritual. All other catalog challengers (flash-scrawl sleeve, doujin catalog, cathode-gauze access control, cloud quarry, sneaker box stacks) declined on audience identification — none read as an NGO program/fundraising ops tool.

**FINISH:** unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance.

## Build path

Code-led (no image generation tool available in this session/harness) — stated, not asked.

## Unresolved decisions

- Exact per-module color chips beyond the red/gray anchor (semantic extension) — chosen during build from existing `config('notifications.modules')`.
