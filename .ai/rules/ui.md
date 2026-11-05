---
paths:
  - 'resources/views/{components/ui/page-header.blade.php,pages/**}'
  - 'resources/views/{components/ui/header-filter-select.blade.php,pages/**}'
  - resources/views/components/ui/detail-header.blade.php
---

# Ui

## Keep page actions outside filter grids
The page-header filters slot contains only search and filter controls in the equal-column directory grid. Create and add actions belong in the actions slot, which renders separately at intrinsic width and aligns to the end; never place action buttons inside filters.

## Use the Programs picker for header dropdowns
Every dropdown inside a page-header filters slot uses x-ui.header-filter-select with its current value and wire:model binding. Do not use x-ui.select, flux:select, or a native select in header filters; those render the browser-native popup instead of the shared Programs trigger, menu, option checkmark, and long-list search.

## Every page title uses .page-title (user decision 2026-11-04, supersedes the old titleClass sizes)
One title style everywhere, matching HR's h1: the `page-title` utility in resources/css/typography.css (34px `--text-h1`, line height 1.2, bold 700, `--color-heading` #414042 / desk-ink in dark, 28px under 640px, 1.3 line height in Arabic). The Desk greeting, x-desk.register, the project story and programs show put `class="page-title"` on their h1; x-ui.page-header and x-ui.detail-header pass `page-title!` to their flux:heading. Do not size titles per page: detail-header's `titleClass` prop is only for non-size extras (e.g. funding-agreements' `text-balance`). The `.dk-eyebrow` above a title is 12px caps in `--faint` (#939598) with a 4px gap; its 2.7:1 contrast is a deliberate user choice.

## Status/enum-badge chips go in the meta slot when there's no separate actions row
Pages with real header actions (projects, tasks) put `x-ui.enum-badge` in the `x-slot:actions` beside other controls. Milestones/⚡show.blade.php has no actions of its own — it moved the status badge into `x-slot:meta` as the first chip instead, alongside a `common.updated_date` meta line, matching the informational (no-actions) header spirit of strategies/⚡show.blade.php rather than leaving an actions slot with nothing but a badge in it.
