# Projects index · sign-off checklist

Tick every box before setting the tracker row to `review`. Paste the reviewer notes at the bottom.

## Behaviour
- [x] Stage rail filters by status; re-activating the selected segment clears it.
- [x] Rail counts respect search/city/program/owner (and the lens) but not status.
- [x] Triage lenses are single-select, quiet at zero, and their counts ignore the active lens; Clear filters resets `lens`.
- [x] Sort works for all four options; invalid `?sort=` falls back to name.
- [x] Board/timeline/table toggle keeps filters and sort; invalid `?view=` falls back to board.
- [x] Create and edit still work end to end; toasts appear; no delete anywhere.
- [ ] Modal live hints (duration, breakdown sum) update while typing and never block saving. *(needs a signed-in browser)*

## Visual
- [x] Table columns in the specified order and widths; the enum badge is the row's only status indicator.
- [x] The only large red area is the selected stage segment (+ primary button).
- [x] No gradients, no coloured side stripes, no nested cards inside cards.
- [x] `tabular-nums` on dates, %, beneficiaries, signal counts.
- [x] Rows drop to 60% opacity on every filter/sort/page change.
- [x] Primary `#be1f24` and the grey scale only — no other hue anywhere on the page.

## States (README §8)
- [x] Empty DB · filtered-empty · no dates · no weighted milestones · missing owner · missing city (covered by tests and by the null-safe markup).
- [ ] 120-char name · max-int beneficiaries · modal validation errors, checked visually. *(needs a signed-in browser)*

## Responsive (README §9)
- [ ] 1440 · 1024 (Signals + Beneficiaries folded into meta) · 390 (stacked, 44px targets, scrollable rail with selection in view). *(needs a signed-in browser)*

## Themes & language
- [ ] Light-en · Dark-en · Light-ar · Dark-ar screenshots taken and checked. *(needs a signed-in browser)*
- [x] RTL: logical classes only (`grep -nE "\b(ml|mr|pl|pr|left|right)-" resources/views/pages/projects/⚡index.blade.php` returns nothing); the date arrow uses `flux:icon.arrow-long-right` with `rtl:-scale-x-100`; `.type-label` drops uppercase in Arabic.

## Accessibility
- [x] Rail segments, lens tiles and the view toggle are buttons with `aria-pressed`; each rail segment's accessible name carries its status and count.
- [x] Progress bars name their project and expose `aria-valuenow` (via `x-ui.progress-bar`).
- [x] The table has an `sr-only` caption, `<th scope="col">` and a visually hidden actions header.
- [ ] Keyboard-only run through README §7 tab order; axe DevTools 0 serious/critical. *(needs a signed-in browser)*

## Quality gates
- [x] `./php.cmd artisan test --compact tests/Feature/ProjectDirectoryTest.php` green — 27 tests (13 existing + 14 new).
- [x] `LocalizationTest`, `UiComponentsTest`, `NavigationTest` green.
- [x] Full suite green (940 tests, 934 passed, 6 skipped).
- [x] `./php.cmd vendor/bin/pint --dirty --format agent` clean.
- [x] `npm run build` succeeds.
- [x] 13 queries per render, measured with `DB::listen` and asserted at ≤ 17 by a test.
- [x] Nothing committed; changed files listed in the review note.

## Review note
```
Changed files:
- resources/views/pages/projects/⚡index.blade.php
- lang/en/projects.php
- lang/ar/projects.php
- tests/Feature/ProjectDirectoryTest.php
- docs/redesign-plan/pages/projects-index/checklist.md

Deviations from spec (with reason):
- Palette and visual language: the cockpit plan and 01-design-system §6 called for the
  Operations Board surfaces (board/ground/head tokens) and the status tone map
  (status-good green, status-warn amber, funding teal). The project owner asked mid-build
  for the page to take its spirit from the milestones directory and to use only the
  primary #be1f24 and shades of grey. So: panels, stat tiles, the segmented view toggle,
  card and diamond-marker styling all follow pages/milestones, lifecycle state is shown
  with x-ui.enum-badge, and "needs attention" is the only coloured state (primary). Every
  status is still carried by words, never by colour alone.
- Board view renders one x-ui.panel per lifecycle state (the milestones _list pattern)
  instead of a hairline card grid, so the status bands stay honest under pagination.
- View toggle and sort sit in their own toolbar row between the tiles and the content,
  which is where the milestones page puts them. "Add project" stays in the page-header
  actions slot, as .ai/rules/ui.md requires.
- The README's slippage line ("Show them" / count_active_past_end_date / show_all_projects)
  was not built: the cockpit plan §4.3 replaces it with the four triage lenses, so those
  three copy keys were not added.
- projects.headline_count was removed from both lang files: nothing references it any more
  (copy.md asked for exactly this check).
- projects.duration_days keeps its plural form. The modal hint cannot call trans_choice in
  the browser, so the four distinct Arabic range forms are rendered with the count left as
  a marker and Alpine picks the right one client-side.
- x-ui.project-timeline was not touched; the projects timeline is built page-locally and
  reuses that component's arithmetic and the existing programs.timeline_* copy.

Screenshots:
- Not taken. The browser pane is open on /projects but the app requires a sign-in and I do
  not enter credentials; the page owner needs to sign in for the visual, responsive,
  dark-mode, RTL and axe passes.
```
