---
paths:
  - '{app/Actions/Proposals/**,resources/views/pages/proposals/**,resources/views/pages/projects/**}'
---

# Projects

## Renewal = new proposal on the same project; no project chain
A renewal never creates a project (D-30). It is a new `concept note` proposal on the same project(s), linked through `proposals.previous_proposal_id` and started with `CloneProposalForRenewal` (which writes through `SaveProposal`). There is no `projects.previous_project_id` and no renewal-project action. Never copy status, actual dates, files, agreement, reports, reviewers, deliveries or targets. Copy the framework only through `CopyResultTree`, never a second tree-copy routine. Last year's achievement is a reference read via `PlanIndicator::previousYearReference()`.
