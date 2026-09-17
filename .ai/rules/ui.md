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

## detail-header has an optional titleClass override, default unchanged
Added an optional `titleClass` prop (default null) forwarded as `class="{{ $titleClass }}"` on the inner `flux:heading`. Flux merges caller classes after its own (`$attributes->class(...)`), so pass `!`-suffixed utilities to override — e.g. strategies/⚡show.blade.php and milestones/⚡show.blade.php use `title-class="text-3xl! font-bold! leading-none!"` to match the bold 30px title style used on index/directory page headers, while every other page using x-ui.detail-header (donors, funding-agreements, projects, proposals, reports, tasks, annual-plans) omits the prop and keeps the original `size="lg"` look untouched.

## Status/enum-badge chips go in the meta slot when there's no separate actions row
Pages with real header actions (projects, tasks) put `x-ui.enum-badge` in the `x-slot:actions` beside other controls. Milestones/⚡show.blade.php has no actions of its own — it moved the status badge into `x-slot:meta` as the first chip instead, alongside a `common.updated_date` meta line, matching the informational (no-actions) header spirit of strategies/⚡show.blade.php rather than leaving an actions slot with nothing but a badge in it.
