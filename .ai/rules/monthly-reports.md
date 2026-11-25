---
paths:
  - 'resources/views/pages/monthly-reports/**'
---

# Monthly Reports

## Monthly-reports list filters read through ReportExtraction
Both tabs (My reports, To validate) filter by Proposal · City · Program · Center · From / To (Y-05, D-79) only through ReportExtraction::for(ExtractionFilter)->reports(openEndUnbounded: true): the page builds no place/proposal/period `where`; it intersects the tab's own scope (preparer templates / validatableBy) with the extracted ids. An open To lists months still to come. The big figures read every filter except the pressed figure (status / show), so a click lists exactly that many. Place helpers (center values c{id}|field-{city}|unset, center options, labels) live once in App\Livewire\Concerns\FiltersReportPlaces, shared with the matrix. Legacy ?month= and ?project= are translated on mount. A report row links to the matrix for its place; a matrix cell links here with from=to=its month and status=all.

## Monthly report "Update risks" line
Optional line (monthly_reports.updates_risks). Lists ALL logframe risks (proposal_id set, no location row) of the report's proposals via App\Support\MonthlyReportRisks::for, every month. Ticks pre-fill from risks.happened; saves go only through SaveMonthlyReportRisks (MonthlyReportGuard update lock), which writes monthly_report_risks AND risks.happened. Turning the line off keeps saved rows. The section renders outside the empty-month branch of ⚡show.
