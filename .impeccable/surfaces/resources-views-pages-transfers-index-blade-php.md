---
version: 1
slug: "resources-views-pages-transfers-index-blade-php"
primary_target: "resources/views/pages/transfers/⚡index.blade.php"
related_targets: []
---

# Transfers surface brief

Scope: `/transfers`, the payment-tranche ledger. Mode: Operate.
Audience: the projects & fundraising manager (what is late, what landed short, where did the money go) and officers recording and tracing one tranche. Jobs, ranked by the user: chase → reconcile → trace. User pinned: the gap between what a donor sent and what actually landed must be visible, not hidden in a field.
Constraints: the shared `ManagesTransferForm` + `x-funding.transfer-modal` are never re-inlined; header search / receipt-status / account filters survive in `x-ui.page-header` slots; USD, EUR and SYP are never summed or drawn on one scale; a tranche is never deleted; must hold at hundreds of rows (paginate, no N+1). Every string via `lang/en` + `lang/ar`; RTL through logical properties.
Neighbour guard: `/funding-agreements` already owns the Money Calendar (agreement lanes on a horizontal time axis). Transfers must not become a second time axis.

## Direction contract

THESIS: Every tranche is one measured bar drawn at the full amount the donor promised, with the fee bite and the exchange bite physically cut out of it and only what truly landed left solid — so the page ranks payments by what they actually delivered. It refuses the status table, the KPI stat-tile row, the kanban of statuses, and a second horizontal time axis.

OWN-WORLD: The settled funding world, unchanged — white ground, Somar Sans with tabular numerals, hairlines, no boxed cards, no shadows used as structure. Funding teal `--color-funding` is money that landed; amber `--color-status-warn` is fees and pending; HC red `--color-status-critical` is reserved for overdue, failed and short-landing only. Labels are tiny uppercase tracked Somar Sans, never a borrowed mono face. Measure bars are flat rules, not rounded pills; an unreceived tranche is a ghost measure with a dashed track, never an em dash.

STORY: The manager reads one ledger line per currency — sent, fees, exchange gap, landed, shortfall — sees the shrink as a single hairline, sorts the ladder by shortfall to find money that misbehaved, scans measure bars whose short ends are self-evident, expands one row for the arithmetic that produced the gap, then edits or opens the agreement.

FIRST VIEWPORT: Page header (title, live count, search / receipt status / account, Record transfer). Under it a per-currency ledger line at specimen scale: Sent · Fees · Exchange gap · Landed, with the shortfall percentage and one thin segmented shrink hairline — a line, never tiles. Then a sort row (shortfall · date · amount) and the ladder filling the rest: per row, donor · proposal and a three-node scheduled → sent → landed date thread on the start side; a measure bar spanning the remaining width on the end side, drawn at the promised amount with the fee and exchange bites cut from it. Primary action sits in the header actions slot.

FORM: The Shortfall Ledger, position 5 on the ordered list, seed key 29fe9f5a (surface round 1, code-led, user locked it with no steer). Signature interaction: clicking a row expands it in place into the arithmetic on a leader line — sent, minus fees, at this rate, equals what landed, into this account — and the measure bar grows its segment labels; the sort control re-ranks the ladder with the bars re-measuring rather than re-rendering.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
