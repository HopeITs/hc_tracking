---
paths:
  - 'resources/views/components/ui/**'
---

# Components Ui

## Multi-select header filters are hand-rolled; flux-lite has no menu JS
This app serves flux-lite, which registers ui-dropdown, ui-select and ui-options but NOT ui-menu, ui-menu-item or ui-menu-checkbox, and ships no listbox select variant. So `<flux:menu.checkbox>` renders inert and `<flux:select variant="listbox" multiple>` does not exist here. Verified by grepping vendor/livewire/flux/dist/flux-lite.min.js against flux.min.js.

x-ui.header-filter-multiselect therefore drives its own panel with Alpine (x-show) rather than flux:dropdown: a flux menu item closes the popover the moment it is clicked, which is wrong for picking several values. It keeps the data-flux-button / data-flux-menu / data-flux-menu-item attributes and the .program-filter-picker wrapper class purely so the shared CSS in app.css still styles it — verified computed background, colour and border are identical to the Flux picker beside it in both light and dark.

Alpine's open state survives the Livewire round trip each toggle causes, so the panel stays open across selections. Confirmed with real clicks, not synthetic ones — a programmatic .click() does not open a Flux dropdown.
