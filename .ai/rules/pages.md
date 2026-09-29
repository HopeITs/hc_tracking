---
paths:
  - 'resources/views/pages/**'
---

# Pages

## Use the shared Programs-style page header
Every authenticated page directory/index header must use x-ui.page-header. Keep the title bold at the shared component scale, omit descriptive subtitle copy, show a live count badge when the page has a meaningful visible-record count, and place page search/filter/action controls in the component slots so responsive layout stays consistent.

## Bind flux:modal with wire:model, never :open
A `<flux:modal :open="$someId !== null">` never opens from a Livewire round trip. The expression is evaluated when the dialog first renders; a later patch flips the attribute but nothing calls showModal() on the `<dialog>`, so the component state changes and the user sees nothing. Confirmed in the browser on annual-plans and risks.

Bind the modal to a boolean with `wire:model` instead, and set that flag in the same action that sets the id: annual-plans/index carries `deletingPlanId` for the record plus `showDeleteModal` for the dialog. A Livewire test asserting the id alone passes while the UI is dead, so assert the boolean too.

Still outstanding on the delete confirmations in admin/accounts, donors, proposals, targets and tasks. Fixed on indicators: all four of its dialogs now bind a boolean (`showIndicatorModal`, `showDeleteModal`, `showProgressModal`, `showInspectModal`), and IndicatorDirectoryTest asserts those booleans rather than the ids alone. Risks fixed 2026-09-21 (see .ai/rules/risks.md).

## Directory pages share filter styling via CSS hook classes
The directory index pages (programs, strategies) get their filter-bar look from plain CSS hooks in app.css, not from utilities: `.admin-directory` on the page `<section>` (brand palette for Flux controls/tables/buttons), `.directory-filters` on the filter grid (2.75rem tall, soft-tinted controls), and `.filter-picker` / `.filter-menu` on a `flux:dropdown` / `flux:menu` filter. `.program-directory-filters`, `.program-filter-picker` and `.program-filter-menu` are the original programs-page names, kept as `:is()` aliases of the unprefixed ones — use the unprefixed names on new pages. A filter dropdown that needs its own search box only gets one when the option list is long (programs uses `> 6`); short fixed lists like status just render the items with a check icon on the selected one. Note a pre-existing quirk on BOTH pages: in dark mode the search input keeps the light filter background while its text turns white, so typed text is low-contrast — fix it in app.css for both pages at once, not one page.

## Open a record's show page with an eye button, not a linked title
User decision (2026-09-22): on list/board/calendar views the record's own name or title is plain text, and its show page opens from a ghost eye button placed first in the actions group: `<x-ui.button tone="ghost" size="sm" icon="eye" :href="route('x.show', $record)" wire:navigate :aria-label="__('common.view_name', ['name' => ...])" />`. Applied to donors, proposals, funding-agreements and reports; milestones and tasks views were deliberately left unchanged at the user's request. Links to *related* records inside a row (e.g. a proposal name inside a donor row) stay as underlined links. Whole-tile links (programs cards, annual-plan year cells) were left as they are.

## Bind x-ui.header-filter-select :value to the RESOLVED value, not the raw property
x-ui.header-filter-select picks its trigger label with firstWhere('value', $value) and falls back to `$options[0]` when nothing matches. So a filter whose empty/unknown state resolves server-side to a real default (e.g. `#[Url(except: '')] public string $year = ''` plus a `selectedYear()` that falls back to now()->year) renders the FIRST option while the page computes a different one.

This shipped broken on executive-summary: the picker showed 2027 while every figure on the page was 2026. Fixed by exposing the resolved year as a `#[Computed] fiscalYear()` and passing `:value="(string) $this->fiscalYear"` while `wire:model.live` still writes the raw `year`.

Rule: whenever the component resolves the bound property before using it, pass the resolved value to `:value`. A Livewire test asserting only the computed value passes while the trigger lies, so also assert the rendered label (assertSeeHtml('&lt;span class="truncate"&gt;'.$expected.'&lt;/span&gt;')). Filters whose '' genuinely means "All" and render an explicit All option are unaffected.

## Preview an auth-gated page in a browser without signing in
To look at a rendered page when you have no session (and must not type a password), render it server-side and serve the HTML:

1. Boot the HTTP kernel in a standalone script (`$kernel->bootstrap()` BEFORE any Eloquent call, or Model::resolveConnection() throws on a null resolver), log in the first User, `$kernel->handle(Request::create('/the-path'))`, write `getContent()` to `public/__preview.html`.
2. The rendered head points at APP_URL (`http://localhost/...`) and the Vite dev server (`http://[::1]:5173`). Rewrite both to the running ports with sed, or CSS/JS 404s and the page renders unstyled.
3. Open http://localhost:8000/__preview.html. Livewire round trips do not work, but any `#[Url]` property can be driven from the query string (e.g. `?openRow=targets`) to render an expanded/filtered state.
4. Delete the file from public/ afterwards.

Use C:\php85\php.exe, not XAMPP's PHP. Good for layout, typography, contrast, light/dark and responsive checks; not for interaction.

## Hide money, keep names
Money data (amounts, budgets, agreements, transfers, donor reports, bank data, Agreement/MOU files) is queried and rendered only for holders of funding-agreements.view / transfers.view / accounts.view — skip the query server-side, Blade @can alone is not enough. Proposal/donor names stay as plain text; links to proposals.show, donors.show, funding-agreements.show or transfers.index render only with that page's view permission (user decision 2026-09-29).
