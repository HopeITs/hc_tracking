---
version: 1
slug: "resources-views-pages-dashboard-index-blade-php"
primary_target: "resources/views/pages/dashboard/⚡index.blade.php"
related_targets: []
---

# Dashboard surface brief

Scope: `/dashboard`, the authenticated home page. Mode: Operate.
Audience: projects & fundraising manager (portfolio altitude) and program officers (their own work). Job: in seconds, see what is slipping today across delivery and money, then drill to the record. Live "today" view, no fiscal filter (executive summary owns fiscal scope). Currencies (USD/EUR/SYP) are never summed. Every string in lang/en + lang/ar; RTL via logical classes. Charts: ApexCharts (already installed).

## Direction contract

THESIS: A triage bento: tile size encodes urgency, so the largest tile is always what needs action today. Refuses the category default of four equal KPI cards over a chart row.

OWN-WORLD: HC red #be1f24 reserved for "late / critical"; secondary grays for structure; funding teal and status good/warn tokens for data only. Soft-shadow rounded-xl panels (existing x-ui.panel language), Somar Sans, tabular numerals, no nested cards.

STORY: The manager sees how many things are late and where, sees money committed vs received per currency, and clicks straight into the filtered record. Officers see their own due tasks in the top strip.

FIRST VIEWPORT: Top row: greeting + date on the start side, "My work" chips (my overdue / due this week tasks, my draft reports) on the end. Row two: Needs Attention queue spanning 7/12 columns with severity-sorted rows (overdue tasks, overdue milestones, late transfers, materialised risks); 5/12 column: Funding Position per currency (committed vs received bars) above a pair of pulse figures (active projects, reports awaiting MEAL review). Below the fold: charts row (project status donut, proposal pipeline bars, received-per-month area per currency), then recent activity.

FORM: Triage Bento, position 6 on the ordered list, seed key f57f65bd. Code-led. Signature interaction: attention queue filter chips (All / Delivery / Funding / Risks) that re-sort the queue in place with a 200ms height/opacity transition; charts restyle live on theme toggle.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
