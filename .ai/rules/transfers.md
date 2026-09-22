---
paths:
  - 'resources/views/pages/transfers/**'
---

# Transfers

## Never net overages against shortfalls in the transfers ledger
The transfers ledger reports the shortfall as SUM of each tranche's own loss, computed per row in SQL — never as (total sent − total landed). A first version used the totals difference and announced "Landed in full" over 24,785 USD that genuinely never arrived, because one tranche had arrived 30,000 over on a favourable rate and cancelled four that arrived short. An overage is real but it does not put the missing money back, so it is reported as its own figure ("Arrived over") beside the shortfall. TransferTrackingTest has a regression test named "a tranche that arrived over never cancels one that arrived short".

Only "settled" tranches feed the shortfall: ar_status = received AND net_amount_received IS NOT NULL AND amount_received_currency = amount_transfered_currency. Money still in transit has not lost anything yet, and a tranche that landed in a different currency is never subtracted from what was sent — it is disclosed as a converted-tranche count instead.

The ledger aggregate deliberately ignores the stage filter (filteredQuery(withStage: false)) so the figures keep describing the whole filtered set while a stage narrows only the ladder. Assert stage/sort behaviour on the ladder rows (wire:key="transfer-{id}") — asserting on formatted amounts passes for the wrong reason, because the same number also appears in the ledger figures above.
