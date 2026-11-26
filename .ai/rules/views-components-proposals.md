---
paths:
  - '{app/Livewire/Concerns/Proposals/**,resources/views/components/proposals/**}'
---

# Views Components Proposals

## Proposal logframe risks sit on the whole framework
Logframe risks are read with Risk::onLogframeOf() and shown once in _framework-risks (toggled by the 'risks' layer), never per result node or matrix column; legacy result_id rows are listed there too. Copied risks (copied_from_id) are remove-only; writes go through SaveFrameworkRisk/DeleteFrameworkRisk with manageFramework. The form is components/risks/form-modal, shared with results/index.
