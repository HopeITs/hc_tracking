---
version: 1
slug: "resources-views-pages-timeline-index-blade-php"
primary_target: "resources/views/pages/timeline/⚡index.blade.php"
related_targets: ["resources/views/components/ui/gantt.blade.php","resources/js/gantt/index.js"]
---

# Timeline surface brief

Scope: `/timeline`, the department-wide Gantt. Mode: Operate.
Audience: the projects & fundraising manager at portfolio altitude, and officers finding their own project fast. Job, in the user's words: "an enhanced pro version of MS Project" where every connection and all the data can be seen. Read-only: schedules are still edited on each project's Plan tab (links, baselines, undo live there); the timeline opens the record or the plan.
Neighbour guard: `/dashboard` owns today's triage queue; `/executive-summary` owns fiscal promise vs delivery; `/funding-agreements` owns the money calendar. The timeline owns time, sequence and connection.
Constraints: brief pins the form (MS Project-grade WBS table + Gantt: zoom, outline levels, critical path, predecessors, export). Shared engine `x-ui.gantt` also draws the project Plan tab (editable) and the agreement page; new features are opt-in so those keep working. Chart area stays LTR. Every string in lang/en + lang/ar. Currencies never summed.
Answered by the user: take every idea (Route Map + Money × Work + Now Horizon), and the first screen answers all four: what is late/critical, next 30–90 days, funding vs delivery, overall span & progress.

## Direction contract

THESIS: Every bar is a node in one connected route: select anything and the whole chain that drives it, depends on it, contains it or pays for it lights up across the portfolio while the rest recedes. Refuses the flat, unconnected Gantt with a legend footer, and the KPI-card row above a chart.

OWN-WORLD: The settled world: white board on hairline rules, Somar Sans with tabular numerals, soft-shadow rounded-xl board. Secondary grays carry structure; funding teal is money and draws every funding route; status good/warn for done/in-flight; HC red only for late, slip tails and the critical path; status-active blue is the traced route. Bars are rounded rails with progress fill, summaries are MS Project brackets, points are diamonds.

STORY: The manager reads one signal line (span, % complete, late, critical, due in 30 days, funding), sees the whole portfolio on the horizon band, drags its window to the moment that matters, clicks a project and watches its milestones, driving links, proposals, transfers and reports light up, reads the inspector, and opens the record or its plan.

FIRST VIEWPORT: Page header with count and filters. One board: view tabs (Portfolio · Funding & delivery · Pipeline) with group-by; the signal line as pressable tokens; the horizon band (whole span, packed lanes, today, draggable window); the toolbar (zoom, today/fit, outline levels, filters, display, search, export); then the split WBS table + Gantt, the inspector docking at the end edge when something is selected; a status bar with the key.

FORM: Route Map Gantt, position 7 on the ordered list (lead of the deal), raised by Money × Work (position 2: funding deck + teal funding routes and drop lines) and Now Horizon (position 4: due-soon signal, horizon window, urgency filters). Seed key d4adc39d. Code-led. Signature interaction: path tracing on select. Motion: bars draw in once from their start edge; tracing fades the unrelated in 150ms; the inspector slides in from the end edge.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
