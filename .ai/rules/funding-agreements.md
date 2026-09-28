---
paths:
  - 'resources/views/pages/funding-agreements/**'
---

# Funding Agreements

## One tranche vocabulary; received means net landed
User decision 2026-09-22. TransfersTracking::state() is the single source for tranche state (received|failed|transit|late|upcoming): sent-but-not-landed is transit however far past its date; late is only unsent past its date. Failed is its own stage/figure, never folded into upcoming/transit/late. "Received"/"% landed" everywhere = net_amount_received in the agreement's own currency; a received tranche with NULL net counts as nothing landed and is flagged (isMissingNetAmount), never read as payment_amount. ManagesTransferForm requires net + receive date when status is received. Percentages under 1% show "<1", not 0.

## One definition of "not yet scheduled" and of landed %
Decided 2026-09-22 after four pages disagreed. Unscheduled = budget − Σ payment_amount of every tranche sent in the agreement's own currency, whatever its state: fees, a received tranche missing its net amount, or a failed tranche never turn money back into "unscheduled" (failed is its own figure). Every printed landed/committed share goes through DonorProposal::percentLabel() (floored, "<1" instead of 0) — never inline floor()/round(). Applies to proposals index/show, donors index/show and both funding-agreement pages; FundingWorkflowTest pins it.
