# P2 · Executive Summary

**Route:** `executive-summary.index` · **Group:** A · Command center · **Wave:** 1 · **Depends on:** 02, 03

## Files you own

- `resources/views/pages/executive-summary/⚡index.blade.php`
- `lang/*/executive_summary.php`
- `tests/Feature/ExecutiveSummaryTest.php`

## Binding rules (`.ai/rules/executive-summary.md`): do not break

- No "projects attached to a program" card (always 100%).
- `percentageOf()` floors, caps partial shares at 99, returns 100 only when covered ≥ total, returns null for total 0 (render em dash).
- Year-scoped cards filter `plan_indicators.source_type = 'yearly_plan'` joined to that year's plans.
- Geographic reach is portfolio-wide and labelled "Portfolio", never "FY ####".

## Current state

Header + fiscal-year `flux:select` (`#[Url] year`, `wire:model.live`), four coverage cards (Annual Plan Coverage, Indicators Targeted, Projects Targeted, Geographic Reach) with icon, scope pill, percentage, "covered of total", thin bar, description. No trend, no click-through, no RAG or funding view.

## Target layout

1. **Header:** `x-ui.page-header` + year select in `filters` slot (unchanged behaviour) + `wire:loading` "Updating" line.
2. **Coverage band:** four items in one panel row (2×2 under `@4xl`):
   - `x-ui.progress-ring size="lg"` with the existing percentage (null → em dash), scope pill, "covered of total unit" line, description.
   - **Trend** under the three year-scoped items: `x-ui.sparkline` of the same ratio for the **last 5 fiscal years ending at the selected year**. Compute with one grouped query per metric (group by `yearly_plans.year`), then apply `percentageOf()` per year in PHP. Geographic reach shows **no** trend (it has no year) and says "Portfolio-wide".
   - **Click-through** (whole item is a link):
     - Annual Plan Coverage → `yearly-plans.index?yearFrom={year}&yearTo={year}`
     - Indicators Targeted → `targets.index` filtered to source type yearly plan (use the existing query-string key of that page)
     - Projects Targeted → `targets.index` same filter
     - Geographic Reach → `admin.cities.index`
3. **Portfolio health** panel (left, `@5xl:col-span-3` of 5): `x-ui.status-strip` (static, no wire-model) of indicator RAG counts for the selected fiscal year + `x-ui.chart type="donut"` of the same counts with tones good/warn/critical and a fourth "No data" neutral slice. Window: 1 Jan – 31 Dec of `year`, `asOf` = today if the year is current, else 31 Dec. Load indicators with the relations `ragStatus()` needs (read `Indicator::ragStatus` and eager-load `progress` exactly as `indicators.index` does). Link "View indicators" → `indicators.index`.
4. **Funding snapshot** panel (right, 2 of 5), labelled **"Portfolio"** scope: per currency `x-ui.capacity-bar` committed (`donor_proposal` sum budget by currency) vs received (`transfers_tracking` sum `net_amount_received` by `amount_received_currency`, `ar_status = received`). Link → `funding-agreements.index`.

## Data rules

- Keep the existing coverage computation methods; add `trends`, `ragDistribution`, `fundingSnapshot` as separate `#[Computed]` methods.
- Changing year recomputes coverage, trends, RAG; funding snapshot is not year-dependent (don't recompute on year change: cache via `#[Computed(persist: false)]` default is fine since it doesn't read `year`).

## States

- Year with no plans: rings show 0%/em dash per rules; sparkline em dash when < 2 points.
- No indicators: health panel empty state "No indicators yet" + link to create on `indicators.index`.
- No funding: empty state.

## Tests (`ExecutiveSummaryTest.php`)

- All existing tests stay green (rounding, scoping, "Portfolio" pill).
- Trend: seed plans in 3 consecutive years → sparkline aria-label contains first and last ratio.
- Click-through hrefs present with correct query strings.
- RAG distribution counts render for the selected year.
- Funding snapshot never renders a cross-currency total (USD + EUR seeded).

## Acceptance

- [ ] Rules above intact (tests prove it).
- [ ] Charts update on year change without page reload.
- [ ] Conventions §10 checklist.
