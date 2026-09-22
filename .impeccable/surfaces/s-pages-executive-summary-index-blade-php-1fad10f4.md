---
version: 1
slug: "s-pages-executive-summary-index-blade-php-1fad10f4"
primary_target: "resources/views/pages/executive-summary/⚡index.blade.php"
related_targets: []
---

# Executive summary surface brief

Scope: `/executive-summary`, the fiscal-year reckoning. Mode: Operate.
Audience: the projects & fundraising manager at portfolio altitude, plus officers checking whether their program's year is honoured. Job, in the user's own words: promise vs delivery for one fiscal year.
Neighbour guard: `/dashboard` owns today's triage (what is late right now) and `/funding-agreements` owns the Money Calendar (agreement lanes on a horizontal month axis). The executive summary owns fiscal scope and must NOT become a third attention queue or a second time axis.
Constraints: the four coverage measures keep their exact math from `.ai/rules/executive-summary.md` (`percentageOf()` floors and caps at 99; year-scoped cards filter `plan_indicators.source_type = 'annual_plan'`; geographic reach stays portfolio-wide). USD/EUR/SYP are never summed or drawn on one scale. Percentage indicators are levels, not accumulations, so target values across mixed measures are never added — attainment is a count distribution, never a summed figure. Every string in `lang/en` + `lang/ar`; RTL via logical properties. Header filters live in `x-ui.page-header` slots.
Answered by the user this round: page job = promise vs delivery; coverage percentages keep their math but lose their four-equal-cards form; every headline figure carries a prior-year delta; planned beneficiaries (men / women / boys / girls) are first-class.
Carried defect: the year picker rendered its first option while the component summarised `now()->year`, so the page showed 2027 and computed 2026. The rebuild binds the picker to the year actually summarised.

## Direction contract

THESIS: Every figure on this page is half of a pair and none of them is allowed to stand alone — the start column is what the fiscal year promised, the end column is what it delivered, and one continuous variance spine runs between them carrying each row's shortfall. It refuses the four-equal-coverage-cards grid it replaces, the KPI stat-tile row, the chart wall, and a second attention queue.

OWN-WORLD: The settled world, unchanged — white ground, Somar Sans with tabular numerals, hairline rules, no boxed cards and no shadow used as structure. Tiny uppercase tracked Somar Sans labels, never a borrowed mono face. Funding teal `--color-funding` is money; `--color-status-good` is a promise met; `--color-status-warn` is a promise part-met; HC red `--color-status-critical` is reserved for shortfall, unmet and overdue only. Measures are flat rules, never rounded pills; an unmet commitment is a dashed ghost track, never an em dash. The spine is a single 1px rule with each row's gap struck across it.

STORY: The manager reads one struck-balance line stating the year, scans down the spine to find the row whose gap is widest, sees instantly whether it is targets, money, milestones or reports, opens that row in place to see which programs and currencies own the shortfall, reads planned beneficiaries and city reach as the year's stated ambition, and leaves knowing the one number to raise in the board meeting.

FIRST VIEWPORT: Page header (title, fiscal-year picker in the filters slot). Under it the struck balance at specimen scale — targets honoured, money landed, and the delivery share, each with its prior-year delta on a thin rule, not in tiles. Then the spread opens: COMMITTED and DELIVERED as the two column heads with the variance spine between them, and the rows descending — indicator targets against reported actuals, money per currency (scheduled against landed), milestones due against reached, reports owed against submitted. The coverage rail closes the spread as one hairline strip of four measures. Beneficiaries and city reach follow as the year's stated reach. No primary action: this page is read, not written to.

FORM: The Commitment Ledger, position 1 on the ordered list, seed key f8a5340e (surface round 1, code-led, user locked it with no steer; the roll's lead was The Fiscal River and the user took this alternate). Signature interaction: clicking a ledger row opens it in place into the rows that produced the gap — per program for targets, per currency for money — drawn on the same spine at the same scale, so the shortfall decomposes without leaving the page. The page's one authored moment is the spread striking its balance on load: each row's variance mark draws out from the spine in sequence, once.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
