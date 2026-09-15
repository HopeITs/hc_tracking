# 05 · Command palette (Ctrl/⌘ + K)

**Owner group:** Shell · **Wave:** 0b · **Parallel with:** 04 (the 04 owner mounts the component and trigger)

## Files you own

- New Livewire component: create with `./php.cmd artisan make:livewire command-palette --no-interaction`, and move/rename to follow the project's single-file convention if the generator's output differs from `pages::` components. Target file: `resources/views/components/⚡command-palette.blade.php` (anonymous SFC) or the location `make:livewire` produces. Record the final path in this file.
- New lang group: `lang/en/command_palette.php`, `lang/ar/command_palette.php`
- New test: `tests/Feature/CommandPaletteTest.php`

## Behaviour

- Opens on `Ctrl+K` / `⌘+K` (Alpine `@keydown.window`, `preventDefault`) and on the window event `open-command-palette`. Closes on `Escape`, backdrop click, or after navigating.
- Flux `flux:modal` (variant bare/palette styling) with focus trapped in the input; restores focus to the trigger on close.
- Input `wire:model.live.debounce.200ms="query"`. Minimum 2 characters; below that show **Quick links**: the static list of sidebar destinations (same routes, same labels as the sidebar, translated), so the palette is useful before typing.
- Results grouped by entity, max **5 per group**, in this order:

| Group | Model | Match columns | Result line | Meta | Route |
| --- | --- | --- | --- | --- | --- |
| Projects | `Project` | `name` | name | `program.name · city.name` | `projects.show` |
| Programs | `Program` | `name` | name | `strategy.name` | `programs.show` |
| Strategies | `Strategy` | `name` | name | `start_year–end_year` | `strategies.show` |
| Proposals | `Proposal` | `name` | name | status label | `proposals.show` |
| Donors | `Donor` | `name`, `contact_person` | name | type label · country | `donors.show` |
| Funding agreements | `DonorProposal` | `project_num`, `donor.name`, `proposal.name` | `donor · proposal` | project_num | `funding-agreements.show` |
| Tasks | `Task` | `title` | title | status label · due date | `tasks.show` |
| Reports | `Report` | `planIndicator.indicator.name` | indicator name | planned date · status | `reports.show` |

- Program names are HR-mirrored: search the column the directory search uses today (read `pages/programs/⚡index.blade.php` search scope and reuse it).
- Eager-load exactly the relations in the Meta column (strict mode). One query per group, `limit(5)`.
- Users (`admin.users.index`) are **not** searchable in the palette.
- Keyboard: ↑/↓ moves an active index across all groups (`aria-activedescendant`), Enter navigates with `Livewire.navigate(url)`, Home/End jump. Mouse hover sets the active index.
- Each result: `role="option"`, list `role="listbox"`, matched substring wrapped in `<mark>` (escaped: build from `e()` pieces, never `{!! !!}` on user data).
- States: typing (subtle spinner in input via `wire:loading`), no results ("No matches for "{query}"", with the query escaped), error-free.

## Tests (`CommandPaletteTest.php`)

- Renders for authenticated users on the dashboard response (trigger + component markup).
- Query < 2 chars returns quick links, no model queries.
- Query matches a project and a donor by name; result links point at the right `show` routes.
- Max 5 results per group.
- Escapes HTML in names (`<script>` shown as text).
- Guest cannot reach it (dashboard redirects).

## Acceptance

- [ ] Opens with Ctrl+K on Windows and ⌘K on macOS, and from both triggers.
- [ ] Fully keyboard operable; screen reader announces result count (`aria-live="polite"`).
- [ ] Arabic query matches Arabic names; RTL layout correct.
- [ ] No lazy-loading exceptions; ≤ 8 queries per search.
