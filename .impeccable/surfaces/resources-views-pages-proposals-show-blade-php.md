---
version: 1
slug: "resources-views-pages-proposals-show-blade-php"
primary_target: "resources/views/pages/proposals/⚡show.blade.php"
related_targets: []
---

# Proposal dossier surface brief

Scope: `/proposals/{id}`, one proposal's whole dossier. Mode: Operate.
Audience: the projects & fundraising manager asking "is this grant healthy?" daily, and officers who need one connected record fast. Job: read the grant's condition in seconds, then reach any connected record — agreement, tranche, project, task, target, status move — without leaving the page. User asked for every connection visible and for the page not to be a table. Currencies are never summed across USD/EUR/SYP. Every string in lang/en + lang/ar; RTL via logical classes. Live data is thin in places (all six tasks undated, one target points at a project this proposal does not fund) — the page states those gaps rather than hiding them.

## Direction contract

THESIS: A grant has four vital signs — money landed, work done, time spent, promises reported — and this page takes all four readings at once, then opens the evidence behind any one of them in place. Refuses the category default of a stat-tile grid over a tab strip.

OWN-WORLD: Inherited whole from the pipeline page. White ground, Somar Sans, tabular numerals. Funding teal `#2b5f6b` for money received, amber `#9a6412` for pending, HC red `#be1f24` only for the live stage, overdue money and an unmet promise. Hairline rules, no boxed cards, no shadowed panels; dot-and-thread for lifecycle and connections. Figures are typographic, never tiles.

STORY: The manager lands, reads four readings across one shared rule, sees the red one, opens it in place, finds the overdue tranche and its date, then follows the thread out to the project or target it belongs to — or advances the status with a recorded reason, without ever changing page.

FIRST VIEWPORT: Breadcrumb, name at display scale, status and phase chips, Change status. The seven-stop lifecycle rail full width with the live stop in red. Then the bench: four readings — 40% money landed, 2/6 work done, day 264/365, 0/3 promises reported — as oversized tabular figures on one continuous hairline, each over its own 3px spark-bar, the failing one in red. No boxes anywhere in the viewport.

FORM: The Vitals Bench, position 4 on the ordered list, seed key d8125cef (surface round; user locked the dealt lead). Code-led — the environment's image key returned credit_balance_exhausted, so no comp exists and none rides to the finish review. Signature interaction: a vital opens as an inline drawer beneath the bench, its bar becoming the drawer's header rule, while the other three readings stay legible and un-dimmed; the connection columns below filter to the opened vital's chain.

FINISH: unreviewed and undocumented is unfinished; this build ends with the detector pass, the finish review, the verdict, and DESIGN.md.
