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
