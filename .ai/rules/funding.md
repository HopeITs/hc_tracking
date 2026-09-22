---
paths:
  - '{app/Livewire/Concerns/**,resources/views/components/funding/**}'
---

# Funding

## Agreement and tranche forms are shared traits
ManagesAgreementForm + x-funding.agreement-modal and ManagesTransferForm + x-funding.transfer-modal are used by funding-agreements/index, transfers/index and proposals/show. Pages pinned to one proposal override agreementProposalScope()/transferProposalScope() so tampered ids cannot cross proposals. New tranches cannot target a closed account (is_active); an existing tranche may keep one. Never re-inline these forms on a page.
