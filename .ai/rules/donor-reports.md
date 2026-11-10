---
paths:
  - '{database/migrations/**,app/Models/DonorReport.php,resources/views/pages/funding-agreements/**,resources/views/pages/donor-reports/**}'
---

# Donor Reports

## Donor reports: validated mirrors only, snapshot frozen
donor_reports (T19, migration 2026_10_19_122940) adds audience (donor|internal), period_type, period_start/end, snapshot json (schema_version 1), version, responsible_employee_id (HR id, no FK), notes; unique (donor_proposal_id, type, period_start, period_end, version). Figures come only from approved share mirrors (reports.monthly_report_id set) via DonorReportAggregator; ratios only through PlanIndicator::pooledRatio(). Saved pages, CSV and print read the snapshot, never re-query. A submitted report (and its files) is frozen: no edit, delete, regenerate or file change. Submission goes only through MarkDonorReportSubmitted (date ≤ today + a report_submission file for audience=donor). Finance renders only when the snapshot has it AND the viewer holds funding-agreements.view.
