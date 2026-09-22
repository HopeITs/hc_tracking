---
paths:
  - 'resources/views/pages/proposals/**'
---

# Proposals

## Proposal status only moves with an audit note
User decision 2026-09-22. The proposals index edit form no longer touches status; a starting status is chosen only on create, which writes the first ProposalStatusNote in the same transaction. Every later move goes through the stage/status modal. Every tranche state on proposal and donor pages reads TransfersTracking::state() (sent money is transit, never overdue); donor reports (DonorReport) feed the proposal deadlines/warnings.
