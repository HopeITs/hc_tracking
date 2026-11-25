---
paths:
  - 'resources/views/pages/proposals/**'
---

# Proposals

## Proposal status only moves with an audit note
User decision 2026-09-22. The proposals index edit form no longer touches status; a starting status is chosen only on create, which writes the first ProposalStatusNote in the same transaction. Every later move goes through the stage/status modal. Every tranche state on proposal and donor pages reads TransfersTracking::state() (sent money is transit, never overdue); donor reports (DonorReport) feed the proposal deadlines/warnings.

## Set grant dates fills in missing actual dates only (2026-11-06)
Approval records the actual grant dates (ApproveProposal); older donor-approved proposals have none, and donor reports cannot place a period without them. `SetGrantDates` (dialog `ManagesGrantDates`, button "Set grant dates" in `_show-actions` while `Proposal::lacksGrantDates()`) fills in only what is missing: an existing actual start stays (its field is disabled), a grant with both dates is refused (a later end is ExtendProposal's job). It needs `update` on the proposal, starts from the planned dates for the user to confirm, logs `proposal.grant_dates_set`, and touches neither linked projects nor the plan. `?grant_dates=1` opens it, and RedirectProposalToStory / `ProposalStoryLink::story(setGrantDates:)` carry that link onto the project Story (the donor report builder links here). The planned dates are never used as the grant dates without a user saving them (owner decision).
