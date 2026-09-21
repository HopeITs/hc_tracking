---
paths:
  - 'resources/views/components/ui/**'
  - resources/views/components/ui/multi-select-popover.blade.php
  - resources/views/components/ui/chart.blade.php
---

# Components Ui

## Multi-select header filters are hand-rolled; flux-lite has no menu JS
This app serves flux-lite, which registers ui-dropdown, ui-select and ui-options but NOT ui-menu, ui-menu-item or ui-menu-checkbox, and ships no listbox select variant. So `<flux:menu.checkbox>` renders inert and `<flux:select variant="listbox" multiple>` does not exist here. Verified by grepping vendor/livewire/flux/dist/flux-lite.min.js against flux.min.js.

x-ui.header-filter-multiselect therefore drives its own panel with Alpine (x-show) rather than flux:dropdown: a flux menu item closes the popover the moment it is clicked, which is wrong for picking several values. It keeps the data-flux-button / data-flux-menu / data-flux-menu-item attributes and the .program-filter-picker wrapper class purely so the shared CSS in app.css still styles it — verified computed background, colour and border are identical to the Flux picker beside it in both light and dark.

Alpine's open state survives the Livewire round trip each toggle causes, so the panel stays open across selections. Confirmed with real clicks, not synthetic ones — a programmatic .click() does not open a Flux dropdown.

## Shared searchable multi-select popover for assignee pickers
`x-ui.multi-select-popover` is a new reusable component extracted from the list row's inline assignee picker so the task drawer (`tasks/⚡index.blade.php`) and the calendar page's own task form (`calendar/⚡index.blade.php`) could get the same searchable dropdown instead of an always-expanded checkbox box. It owns the Alpine open/search/position state and the `x-teleport="body"` positioning; callers supply a `trigger` slot and option rows in the default slot.

Two different binding styles live behind the same shell, both intentional:
- **List row** (`tasks/_list-group.blade.php`): no client-side selection state — each option row is `wire:click="toggleAssignee($taskId, $employeeId)"`, an immediate server action per click. The trigger is the avatar cluster; each avatar also carries a small hover-revealed "x" (`wire:click.stop`) for one-click removal without opening the popover — the trigger had to change from a real `<button>` to a `<div role="button">` specifically so a real nested `<button>` (the "x") is valid HTML.
- **Drawer / calendar form**: option rows are plain `<input type="checkbox" wire:model.live="assignedTo" value="...">` — a Livewire-bound array property accumulated before the whole form submits. `.live` (not deferred) is deliberate: without it the trigger's "N selected" label (computed server-side from `$assignedTo` + `$this->userOptions`) would only refresh after the next unrelated Livewire round trip, which reads as broken.

Every option row needs `x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"` on itself to participate in the popover's search box — the component doesn't do this filtering for you, matching the same per-item convention `x-ui.header-filter-multiselect` already uses.

## x-ui.chart gained an opt-in `stacked` bool prop
Added for the risks-by-project rollup chart (horizontal stacked bar, Materialised/Watching series) — the component previously had no way to stack a multi-series bar chart, only `distributed` (per-category color on a single series). Passes through to ApexCharts as `chart.stacked`. Defaults to false, so every existing caller (dashboard's donut/grouped-bar charts) is unaffected. `tones` still maps one color per series by index for a stacked/grouped bar — it does not recolor per category unless `distributed` is also set.
