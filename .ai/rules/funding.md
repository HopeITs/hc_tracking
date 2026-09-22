---
paths:
  - '{app/Livewire/Concerns/**,resources/views/components/funding/**}'
---

# Funding

## Agreement and tranche forms are shared traits
ManagesAgreementForm + x-funding.agreement-modal and ManagesTransferForm + x-funding.transfer-modal are used by funding-agreements/index, transfers/index and proposals/show. Pages pinned to one proposal override agreementProposalScope()/transferProposalScope() so tampered ids cannot cross proposals. New tranches cannot target a closed account (is_active); an existing tranche may keep one. Never re-inline these forms on a page.

## Guarded deletion and fixed currency in the funding forms
User decision 2026-09-22. Tranches are deleted from their edit form (transfers.delete). An agreement is deleted only while it has no tranches and no donor reports (funding-agreements.delete, agreementDeleteBlocker); its currency cannot change once tranches exist. Pages implement agreementDeleted()/transferDeleted(); funding-agreements/show redirects to the index. An agreement against a proposal not yet approved (ProposalStatus::isApprovedByDonor) is allowed but warned about. Tranche form rejects: receive date on pending, receive before sent, sent/received in the future, exchange rate 0, amounts over the DECIMAL column. Donor form checks donors.create/update, delete checks donors.delete.
