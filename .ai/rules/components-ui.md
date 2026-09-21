---
paths:
  - 'resources/views/components/ui/**'
  - 'resources/views/components/ui/{logframe-node.blade.php,logframe.blade.php,target-lineage.blade.php}'
  - resources/views/components/ui/framework-node.blade.php
  - resources/views/components/ui/multi-select-popover.blade.php
---

# Components Ui

## Multi-select header filters are hand-rolled; flux-lite has no menu JS
This app serves flux-lite, which registers ui-dropdown, ui-select and ui-options but NOT ui-menu, ui-menu-item or ui-menu-checkbox, and ships no listbox select variant. So `<flux:menu.checkbox>` renders inert and `<flux:select variant="listbox" multiple>` does not exist here. Verified by grepping vendor/livewire/flux/dist/flux-lite.min.js against flux.min.js.

x-ui.header-filter-multiselect therefore drives its own panel with Alpine (x-show) rather than flux:dropdown: a flux menu item closes the popover the moment it is clicked, which is wrong for picking several values. It keeps the data-flux-button / data-flux-menu / data-flux-menu-item attributes and the .program-filter-picker wrapper class purely so the shared CSS in app.css still styles it — verified computed background, colour and border are identical to the Flux picker beside it in both light and dark.

Alpine's open state survives the Livewire round trip each toggle causes, so the panel stays open across selections. Confirmed with real clicks, not synthetic ones — a programmatic .click() does not open a Flux dropdown.

## Reuse the logframe ResultType mark/accent color mapping everywhere results are shown
Impact/Outcome/Output get one fixed visual identity app-wide: mark dot classes 'bg-secondary-dark ring-secondary-dark dark:bg-white dark:ring-white' (Impact), 'bg-primary ring-primary' (Outcome), 'bg-board ring-secondary-light' (Output) — originally defined in logframe.blade.php/logframe-node.blade.php. target-lineage.blade.php (projects show, targets tab) copies this exact mapping plus a matching top accent bar (h-1 bg-primary for Impact, h-0.5 bg-primary/60 for Outcome, none for Output) instead of inventing new colors. Any new UI that surfaces a Result's type should copy this same mapping rather than reinvent one, so Impact/Outcome/Output read consistently across programs/show, results/index and projects/show.

## framework-node takes a prebuilt node array, not a Result
x-ui.framework-node recurses over the node arrays built by results/⚡index.blade.php's `buildNode()`: `{result, depth, order, deletable, measurement, children, rollup, lineage}`. It renders no queries of its own — every count, percentage and RAG split is already on the array — so do not reach for `$result->indicators` or `->childrenRecursive` inside it, or the page goes N+1 per card.

It reuses the shared ResultType identity (mark dot + top accent bar) from logframe-node/target-lineage rather than defining its own, per .ai/rules/components-ui.md. `data-tree-depth` and `data-result-type` on the root div are asserted by tests/Feature/ResultsFrameworkTest.php; keep them.

## Shared searchable multi-select popover for assignee pickers
`x-ui.multi-select-popover` is a new reusable component extracted from the list row's inline assignee picker so the task drawer (`tasks/⚡index.blade.php`) and the calendar page's own task form (`calendar/⚡index.blade.php`) could get the same searchable dropdown instead of an always-expanded checkbox box. It owns the Alpine open/search/position state and the `x-teleport="body"` positioning; callers supply a `trigger` slot and option rows in the default slot.

Two different binding styles live behind the same shell, both intentional:
- **List row** (`tasks/_list-group.blade.php`): no client-side selection state — each option row is `wire:click="toggleAssignee($taskId, $employeeId)"`, an immediate server action per click. The trigger is the avatar cluster; each avatar also carries a small hover-revealed "x" (`wire:click.stop`) for one-click removal without opening the popover — the trigger had to change from a real `<button>` to a `<div role="button">` specifically so a real nested `<button>` (the "x") is valid HTML.
- **Drawer / calendar form**: option rows are plain `<input type="checkbox" wire:model.live="assignedTo" value="...">` — a Livewire-bound array property accumulated before the whole form submits. `.live` (not deferred) is deliberate: without it the trigger's "N selected" label (computed server-side from `$assignedTo` + `$this->userOptions`) would only refresh after the next unrelated Livewire round trip, which reads as broken.

Every option row needs `x-show="optionSearch === '' || $el.textContent.toLowerCase().includes(optionSearch.toLowerCase())"` on itself to participate in the popover's search box — the component doesn't do this filtering for you, matching the same per-item convention `x-ui.header-filter-multiselect` already uses.
