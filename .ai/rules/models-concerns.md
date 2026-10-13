---
paths:
  - '{app/Models/Hr*.php,app/Support/EmployeeProfile.php,app/Models/Concerns/RemembersHrLookup.php}'
---

# Models Concerns

## Current position = active contract; never read contract pay
An employee's current position comes from their active contract (contracts.is_active = 1); with several active, the latest start_date wins (log a warning). Read it through App\Support\EmployeeProfile, not a new query. Every HR query lists its columns: never select contracts.salary, contracts.currency, the contract type, or `*` (no ->with('position') on HrContract, which loads contracts.*). Resolve ids on `hr` first, then whereIn on tracking tables; no cross-connection whereHas.
