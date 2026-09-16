---
paths:
  - 'resources/views/{components/ui/page-header.blade.php,pages/**}'
  - 'resources/views/{components/ui/header-filter-select.blade.php,pages/**}'
---

# Ui

## Keep page actions outside filter grids
The page-header filters slot contains only search and filter controls in the equal-column directory grid. Create and add actions belong in the actions slot, which renders separately at intrinsic width and aligns to the end; never place action buttons inside filters.

## Use the Programs picker for header dropdowns
Every dropdown inside a page-header filters slot uses x-ui.header-filter-select with its current value and wire:model binding. Do not use x-ui.select, flux:select, or a native select in header filters; those render the browser-native popup instead of the shared Programs trigger, menu, option checkmark, and long-list search.
