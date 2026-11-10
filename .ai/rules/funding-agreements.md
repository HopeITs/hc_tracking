---
paths:
  - 'resources/views/pages/funding-agreements/**'
---

# Funding Agreements

## One tranche vocabulary; received means net landed
User decision 2026-09-22. TransfersTracking::state() is the single source for tranche state (received|failed|transit|late|upcoming): sent-but-not-landed is transit however far past its date; late is only unsent past its date. Failed is its own stage/figure, never folded into upcoming/transit/late. "Received"/"% landed" everywhere = net_amount_received in the agreement's own currency; a received tranche with NULL net counts as nothing landed and is flagged (isMissingNetAmount), never read as payment_amount. ManagesTransferForm requires net + receive date when status is received. Percentages under 1% show "<1", not 0.

## One definition of "not yet scheduled" and of landed %
Decided 2026-09-22 after four pages disagreed. Unscheduled = budget − Σ payment_amount of every tranche sent in the agreement's own currency, whatever its state: fees, a received tranche missing its net amount, or a failed tranche never turn money back into "unscheduled" (failed is its own figure). Every printed landed/committed share goes through DonorProposal::percentLabel() (floored, "<1" instead of 0) — never inline floor()/round(). Applies to proposals index/show, donors index/show and both funding-agreement pages; FundingWorkflowTest pins it.

## The register leads with three states, then one clean row (2026-11-09)
funding-agreements/index opens on three headline figures — **Needs attention / On track / Not started** — that are disjoint, exhaustive and each a filter (`health`, `toggleHealth()`, `healthCounts()`), so they always add up to the page's count badge. Attention = a late tranche, a failed tranche, or an unsubmitted donor report past its due date. `App\Support\AgreementHealth` (PHP, reads the selected `late_transfers_count` / `failed_transfers_count` / `overdue_reports_count`) and `applyHealth()` / `whereNeedsAttention()` (SQL) test the same three facts, so a row's badge can never disagree with the filter that selected it. Mirrors the reports/index pattern (.ai/rules/reports.md).

The default sort is `urgency` (not a column heading): attention first, then what is running, then what has not started, each by next due date. A page holds 25 rows, not 5 (`registerPageSizes()` is overridden on this page only).

The row is **agreement · status word · budget+currency · landed+share · next due**, via `AgreementMoney::registerColumns()` and the `amount` / `received_share` cells. The wider `AgreementMoney::columns()` is unchanged and still used by donors/show and proposals/show. Every other figure — scheduled, in transit, overdue, failed, not scheduled, outstanding, tranche count, reports — lives on the agreement's own page. The per-currency totals line prints budget, received and outstanding; overdue and failed appear only when there is some.

Search and the donor picker live in the register bar (`x-slot:search` / `x-slot:filters`) and filter live; everything else stays in the Filters dialog, which keeps its draft-then-apply behaviour (shared with transfers/donors/proposals/accounts — do not make it live for one page). A stage or flag choice that would return nothing renders greyed with `aria-disabled` and no handler: still a tab stop, still says why (`finance_tables.nothing_matches_this_here`), but it cannot cost an empty page. `x-funding.pills` takes an optional `disabled` key for this.

Not a bug, checked 2026-11-09: the Overview's lanes are paginated at 15 with a working pager, and each lane's detail panel is collapsed behind its own toggle — text extraction shows the hidden panels, which reads as a wall of repeated "no tranches scheduled yet".

## One word per idea across the Funding pages (2026-11-09)
The same net figure was printed as "Landed (net)" in the agreements ledger and "Received (net)" on the transfers register, so a person moving between the two pages met two names for one number. **Received** is now the noun everywhere: `funding_agreements.landed` → "Received", `landed_net` → "Received (net)" (identical to `finance_tables.received_net`), `percent_received` → ":percent% received", `received_gross_from_donors` → "Gross received" (identical to `finance_tables.gross_received`). `not_scheduled_yet` now matches `finance_tables.not_scheduled`, and `no_tranches_scheduled_yet` matches `no_tranches_scheduled`. FundingVocabularyTest pins each of these pairs in both locales.

**"Landed" survives as a verb, never as a label.** "has not landed yet", "landed in another currency", "nothing landed yet", "Landed in 5 days" stay: in a sentence it means *arrived*, which is the distinction the money model rests on (gross sent vs net arrived). Only the figure captions were unified.

Arabic fixes in the same pass: `stage_unscheduled` was «غير مجدولة بالكامل», which reads as "not scheduled at all" while the filter means *partly* scheduled — now «لم تُجدول بالكامل». `health_moving` was «تسير كما هو مخطط», too long for a table cell — now «على المسار».

A row prints its external project number only when it has one; "No external project number" on most rows is not information. The other pages that still print the placeholder (transfers, donors/show, proposals/show, the tranche table) were left alone.
