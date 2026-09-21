---
paths:
  - 'resources/views/pages/risks/**'
---

# Risks

## Risks index redesigned to the doc plan: status strip, per-project chart, card grid, fixed delete-modal bug
Followed docs/redesign-plan/pages/risks-index.md target layout. No schema change — still `name`/`impact`/`solution`/`happened`/`project_id`.

Livewire component now has a `filteredQuery(includeProject, includeStatus)` helper so search/project/state filters are shared across the register query, the status-strip counts, the unmitigated count, and the project rollup chart, each able to switch a filter off so the widget driving that filter never disappears its own options.

New: `#[Url] unmitigated` bool toggled by a button next to `x-ui.status-strip` (bound to `status` via wire-model), a collapsible per-project `x-ui.chart` (horizontal stacked bar, top 8 projects by risk count, series Materialised/Watching, respects search+state not project), and a two-column (`@4xl:grid-cols-2`) card grid replacing the old stacked-row list, materialised first then newest (`orderByDesc('happened')->orderByDesc('id')`, was `orderBy('name')`).

Also fixed the delete-confirmation flux:modal bug described in pages.md (`:open="$deletingRiskId !== null"` never opens from a Livewire round trip) — now uses `showDeleteModal` set alongside `deletingRiskId`, with a `cancelDelete()` method, matching the pattern already used on milestones. Risks removed from pages.md's "still outstanding" list.

Tests in tests/Feature/RiskRegisterTest.php were updated for the new markup/state; keep the XSS-escaping and empty-state assertions when touching this page again.

## Watching/materialised split removed by explicit user request (2026-09-21) — see mitigation coverage instead
Supersedes the "Watching" half of the earlier risks-index redesign note. The user does not want a Materialised/Watching state distinction built into the register's design: `happened` is still a real column (kept for the "This risk has materialised" checkbox in the form), but the page no longer organizes itself around a two-way state split.

Changes from the first redesign pass:
- Removed `x-ui.status-strip` and the header `state` dropdown filter entirely. Replaced `#[Url] status` (`''`/`'happened'`/`'watching'`) with a plain `#[Url] materialisedOnly` bool, toggled by a chip button in the page-header summary slot (same visual pattern as the `unmitigated` toggle chip next to it) via `toggleMaterialisedOnly()`.
- `filteredQuery(includeProject, includeMaterialised)` replaced the old `includeStatus` parameter — only ever applies `where('happened', true)`, never a "watching" condition.
- The by-project rollup chart (`projectRollupChart()`) no longer compares Materialised vs Watching. It now compares **mitigated vs unmitigated** risk counts per project (`CASE WHEN solution IS NULL OR solution = '' THEN 0 ELSE 1 END`), still top 8 projects by total risk count, still respecting search + `materialisedOnly` (not the project filter).
- The register card grid only shows a "Materialised" chip when `happened` is true; there is no chip/pill for a risk that hasn't materialised — absence of the chip is the signal, no "Watching" label anywhere on the page.
- `common.watching` lang key is UNTOUCHED — projects/⚡show.blade.php still uses it for its own risk widget, which is out of scope here and wasn't touched.

Don't reintroduce a Materialised/Watching chart or filter here without checking with the user first.

## Risks-by-project chart simplified to a plain risk count, respects every filter including project
Supersedes the mitigated-vs-unmitigated stacked-bar version of this chart from the previous redesign pass — user said the by-project chart "isn't useful as what it is" and asked for a different approach that also respects every active filter.

`projectRollupChart()` was replaced with `projectCountChart()`: a single-series horizontal bar (`distributed`, no `tones` override — falls back to charts.js's `categoricalTones` for one color per project bar), one bar per project = total risk count, top 8 by count. Unlike the old version it is NOT exempted from the project filter — it now runs through the full `filteredQuery()` (search + project + materialisedOnly + unmitigated), so filtering to one project collapses the chart to a single bar. That's intentional: the chart is meant to mirror exactly what the register list below it shows, not to offer an independent cross-project comparison.

`filteredQuery()` gained a third toggle, `includeUnmitigated`, alongside `includeProject`/`includeMaterialised` — `risks()`, `materialisedCount()` and `unmitigatedCount()` all go through it now, each excluding only the one filter they themselves drive.

Also reverted the `stacked` bool this added to `x-ui.chart` in the prior pass (components-ui.md) — with the stacked mitigated/unmitigated chart gone, nothing calls it, so it was removed rather than left as dead capability. If a stacked multi-series bar is needed again later, it needs re-adding.

## Mitigation only applies to happened risks ("challenges"); chart compares challenges vs potential per project
Per explicit user framing: a risk that hasn't happened yet has nothing to mitigate — mitigation/"no mitigation recorded" only makes sense once a risk has actually occurred (a "challenge" for the project). A risk that hasn't happened is a "potential risk," not something needing a solution yet.

Consequences in ⚡index.blade.php:
- `filteredQuery()`'s `includeUnmitigated` branch now always adds `where('happened', true)` before the null/blank-solution check — the "without a solution" toggle/count can never include a potential risk. Button relabelled via new `risks.challenges_without_solution` choice string (replaces `risks.risks_without_mitigation`, which was removed since nothing else used it).
- The register card's mitigation block (`common.mitigation` / `risks.no_mitigation_recorded`) is now wrapped in `@if ($risk->happened)` — a potential risk shows no mitigation section at all, not even a nudge.
- Cards show a "Challenge" chip (`risks.challenge`, filled primary, exclamation-triangle) for `happened = true` and a "Potential risk" chip (`risks.potential_risk`, outline, light-bulb icon) for `happened = false` — every card now carries a state chip (previously only materialised ones did).
- `materialisedCount()`/`toggleMaterialisedOnly()` button relabelled via new `risks.challenges_count` choice string (replaces `risks.materialised_risks`).

Chart (`projectRiskChart()`, replacing the single-series `projectCountChart()` from the prior pass): two-series horizontal bar per project (top 8) — "Challenges" (happened=true, tone critical) vs "Potential risks" (happened=false, tone neutral) — still runs through the full `filteredQuery()` so it honors every active filter including the project filter. Added `riskLandscape()` (challenges/potential totals under current filters) rendered as a manual colored-dot legend above the chart, since `x-ui.chart` always hides ApexCharts' own legend by design — this is what makes the two series legible without relying on the x-axis alone.

`common.materialised` / `common.watching` are still untouched (projects/⚡show.blade.php uses them for its own risk widget) — this page no longer references either key at all, using page-scoped `risks.challenge` / `risks.potential_risk` instead.

## Log/edit risk modal matches strategies' plain field styling; no subheading
Per explicit user request. Two changes to the create/edit modal in ⚡index.blade.php:

1. Removed the `<flux:subheading>` line under the modal's `<flux:heading>` — strategies/⚡index.blade.php's equivalent modal has no subheading either, just the heading in the bordered header block. Removed the now-orphaned `risks.record_what_could_go_wrong_what_it_would_cost_and_how_it_is` lang key (en/ar) since nothing else referenced it.

2. Removed the manual `class="border-secondary-light! bg-white! ... dark:bg-secondary-medium! ..."` override from both `<flux:textarea>` fields (impact, solution). Reason: `flux:input`/`flux:select` (used via x-ui.input/x-ui.select, exactly like strategies' modal) apply a bare `class` attribute to Flux's OUTER wrapper div, not the real `<input>`/`<select>` control (see the flux:input's-bare-class note in views.md) — so the visible input/select box on strategies' page has always rendered with Flux's stock default styling, override or not. `flux:textarea` has no such bug (it puts `class` straight on the `<textarea>`), so risks' textareas were the only fields in this modal actually picking up the manual override, making them look inconsistent with the (unstyled-in-practice) name input and project select in the same form, and with strategies' fields. Dropping the override there — not fixing the underlying x-ui.input/select wrapper bug, which is a shared, site-wide issue out of scope for this page — makes all fields in the risk modal render with the same plain Flux default look, matching strategies.

Don't reintroduce a manual textarea class override here without checking with the user; if the x-ui.input/select wrapper-class bug ever gets fixed site-wide, this page's textareas won't need separate reconciling since they never carried the override in the first place after this change.
