---
paths:
  - 'app/Actions/Risks/**'
---

# Actions Risks

## Framework risks are typed, owner-scoped, copied ones remove-only
Program and proposal framework risks go through SaveFrameworkRisk / DeleteFrameworkRisk (gate manageFramework = results.update). Risks load through the owner scope (Risk::onProgram / onLogframeOf) so foreign ids 404; a risk with copied_from_id is remove-only. Each save calls RiskLibrary::rememberTitles (sameWords match, append, never reactivates); the library only suggests titles, it is no longer a picker. Program risk activity comes from the RecordsActivity created hook; proposals also get proposal.risk_attached.
