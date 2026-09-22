---
paths:
  - 'resources/views/pages/funding-agreements/**'
---

# Funding Agreements

## One tranche vocabulary; received means net landed
User decision 2026-09-22. TransfersTracking::state() is the single source for tranche state (received|failed|transit|late|upcoming): sent-but-not-landed is transit however far past its date; late is only unsent past its date. Failed is its own stage/figure, never folded into upcoming/transit/late. "Received"/"% landed" everywhere = net_amount_received in the agreement's own currency; a received tranche with NULL net counts as nothing landed and is flagged (isMissingNetAmount), never read as payment_amount. ManagesTransferForm requires net + receive date when status is received. Percentages under 1% show "<1", not 0.
