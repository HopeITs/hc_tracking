---
version: 1
slug: "resources-views-pages-proposals-index-blade-php"
primary_target: "resources/views/pages/proposals/⚡index.blade.php"
related_targets: []
---

# Proposals surface brief

Scope: `/proposals`, the proposal pipeline index. Mode: Operate.
Audience: projects & fundraising manager (portfolio money + pipeline altitude), officers finding one proposal fast. Job: see where the money stands, how donors flow into proposals and on into projects, and act on a proposal (advance its stage with an audit note, edit, delete). User asked: money picture first, every connection visible, both quick stage moves and the edit modal. Currencies (USD/EUR/SYP) are never summed or drawn on one scale. Every string in lang/en + lang/ar; RTL via logical classes.

## Direction contract

THESIS: The pipeline as a funding river: donor money visibly flows into proposals and on into projects, and every proposal below is a dossier of its connections. Refuses the category default of a flat table row with count text.

OWN-WORLD: White ground, Somar Sans, tabular numerals. Funding teal for received money, amber-striped for pending, HC red only for the active stage, rejection and overdue. Hairline dividers, no boxed cards; dot-and-thread motif for lifecycle and connections; flow ribbons in translucent teal/gray.

STORY: The manager reads committed/received/pending per currency, sees which donor feeds which proposal and which projects each proposal funds, hovers to trace a chain, clicks a donor or phase to filter, then advances a proposal one stage with a recorded reason.

FIRST VIEWPORT: Page header (title, count, search/status/phase filters, Add proposal). Funding position: huge committed/received/pending numerals per currency over one segmented bar, beside a five-phase dot-thread rail with counts and totals. Then the full-width funding flow (donors, proposals, projects columns with budget-sized ribbons). Dossier rows below the fold.

FORM: Funding Flow, position 4 on the ordered list, seed key 7db74d01 (surface round; user delegated the pick). Code-led. Signature interaction: hover/focus any node or dossier row to trace its whole donor-proposal-project chain while the rest dims; click a donor or phase node to filter the page.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
