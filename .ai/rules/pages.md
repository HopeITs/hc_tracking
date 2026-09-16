---
paths:
  - 'resources/views/pages/*/⚡index.blade.php'
---

# Pages

## Directory pages share filter styling via CSS hook classes
The directory index pages (programs, strategies) get their filter-bar look from plain CSS hooks in app.css, not from utilities: `.admin-directory` on the page `<section>` (brand palette for Flux controls/tables/buttons), `.directory-filters` on the filter grid (2.75rem tall, soft-tinted controls), and `.filter-picker` / `.filter-menu` on a `flux:dropdown` / `flux:menu` filter. `.program-directory-filters`, `.program-filter-picker` and `.program-filter-menu` are the original programs-page names, kept as `:is()` aliases of the unprefixed ones — use the unprefixed names on new pages. A filter dropdown that needs its own search box only gets one when the option list is long (programs uses `> 6`); short fixed lists like status just render the items with a check icon on the selected one. Note a pre-existing quirk on BOTH pages: in dark mode the search input keeps the light filter background while its text turns white, so typed text is low-contrast — fix it in app.css for both pages at once, not one page.
