---
paths:
  - 'app/Actions/Results/**,app/Livewire/Concerns/Proposals/ManagesFrameworkBoard.php,resources/views/components/proposals/_framework-picker.blade.php'
---

# Components Proposals

## Picker copies program risks, never links them
The "Start from program framework" picker also lists the proposal programs' risks (program_id set, proposal_id null). CopyResultTree::copySelection(..., $riskIds) copies ticked ones into the proposal logframe (proposal_id, program_id null, copied_from_id set); ids outside the proposal's programs are ignored. A risk counts as already here when copied_from_id matches or the same words (CopyResultTree::riskIsHere: name, or name_en when filled). Counts carry 'risks'.
