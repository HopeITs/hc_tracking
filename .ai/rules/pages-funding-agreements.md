---
paths:
  - '{database/migrations/**,app/Models/DonorReport.php,resources/views/pages/funding-agreements/**}'
---

# Pages Funding Agreements

## donor_reports: reports owed to a donor per agreement
Added 2026-09-22 (migration 2026_09_22_131731). donor_reports: donor_proposal_id FK cascade (like transfers_tracking), title, type (DonorReportType narrative|financial, default narrative — interim/final lives in the title), due_on, submitted_on nullable. Managed only on funding-agreements/show, gated by funding-agreements.update, scoped to the agreement (scopedReport). Overdue = unsubmitted after due_on; attention warns 30 days ahead. Donor contact counts as stale after 90 days (CONTACT_STALE_DAYS in app/Livewire/Concerns/FundingAgreements/ReadsAgreementAttention.php, used by the show page).
