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
