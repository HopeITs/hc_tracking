---
paths:
  - '{resources/views/pages/reports/**,app/Livewire/Concerns/Reports/**,app/Support/Reporting/ReportExtraction.php}'
---

# Reporting

## Monthly matrix: one extraction query and the D-79 filter set
The monthly matrix, its CSV export and (slice 2) the monthly-reports list read figures ONLY through App\Support\Reporting\ReportExtraction::for(ExtractionFilter); none builds its own `where`, so screen and file can never differ (Y-05, D-79). Visible filters: Proposal · City · Program · Center · From · To (months, `Y-m`); Donor, Indicator, Pillar sit behind "More filters". Center values: `c{hrCenterId}`, `field-{cityId}` (D-88 "Field work · city"), `unset` (D-82 "Center not set") — never merged. Center-view row = (template place, library indicator); a D-90 shared target is one row per center, its target counted once in totals. From > To is a field error, never swapped.
