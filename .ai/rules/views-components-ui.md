---
paths:
  - '{app/Models/PlanIndicator.php,resources/views/pages/{results,indicators,targets}/**,resources/views/components/ui/framework-node.blade.php}'
---

# Views Components Ui

## The results chain is enforced in forms: filter options, then block
Decided with the user: forms offer only options that fit the chain and saving a mismatch is refused. Results: a parent must be exactly one level up (output→outcome→impact; nothing under an output), a child takes its parent's program, and moving a root's program cascades to its subtree unless an indicator's project or an annual-plan target would be stranded. Indicators: project must be in the result's program; measure locks once targets exist. Targets: PlanIndicator::chainIssuesFor() (indicator project, plan program vs result program, project in plan program, project funded by proposal); no duplicate source+indicator+project; indicator/source lock once reported. Edits reject only issues they INTRODUCE, so pre-existing out-of-step rows (flagged on the page, not auto-fixed) can still have value/period corrected.

## Results chain v2: nothing under an activity; frameworks stay separate
Supersedes "nothing under an output" (T12, contracts §11.2): activity → output → outcome → impact, nothing under an activity; cross-cutting is a root with no children. A result with proposal_id belongs to that proposal's framework: parents, edits and deletes are loaded within the same framework (Result::proposalFramework($id) / programFramework()), and SaveResult/DeleteResult receive the proposal. Program pages read programFramework() only. PlanIndicator::chainIssuesFor() flags an annual-plan target on a proposal-framework indicator (targets.issue_plan_target_on_proposal_framework), so every result eager-load feeding it must select proposal_id (strict mode).
