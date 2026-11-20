---
paths:
  - '{app/Actions/Proposals/**,resources/views/pages/proposals/**,resources/views/pages/projects/**}'
---

# Projects

## Renewal = new proposal on the same project; no project chain
A renewal never creates a project (D-30). It is a new `concept note` proposal on the same project(s), linked through `proposals.previous_proposal_id` and started with `CloneProposalForRenewal` (which writes through `SaveProposal`). There is no `projects.previous_project_id` and no renewal-project action. Never copy status, actual dates, files, agreement, reports, reviewers, deliveries or targets. Copy the framework only through `CopyResultTree`, never a second tree-copy routine. Last year's achievement is a reference read via `PlanIndicator::previousYearReference()`.

## Templates to confirm includes placement choices (Y-04, D-77 and D-86)
report-templates/index has three disjoint status buttons: Choose a report (pending placements), New templates, and Lines added. All counts follow the same search and city filters. Pending choices are visible and writable only by the place's responsible MEAL officer or the MEAL Manager slot holder with report-templates.manage. Decisions are locked and stale repeats are refused.

A free place creates its first template. An occupied place waits for an explicit Join or Create decision. Shared library indicators always gain a pending target share. Only selected extra indicators are added, and later prefill runs preserve that selection. Submitted and validated monthly reports are frozen. Drafts acquire confirmed additions only through their normal refresh/build path. Project template tabs accept a template id only from that project's own template list.
