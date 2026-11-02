# UI/UX simplification plan · from the 23 Oct meeting and the 29 Oct rulings

Arabic version: [ui-ux.ar.md](ui-ux.ar.md). The full wave-2 plan lives on `sub-main` in `docs/lifecycle/wave2/README.md`.

None of these items touches `contracts.md` or a status, so they ship on `main` without waiting for the open decisions.

## Already done on main

| Item | Evidence |
|---|---|
| W-15 "Go to → Programs" opened Projects | Program detail is its own page again, back link → `programs.index` (2a4b49ca). |
| W-26 Projects page simpler | Project page redesign (750ed0a9 → 100e46b2). |
| Risks card removed from the desk; "Add panel" hidden when nothing is allowed | Desk wave. |

## Batch 1 · now

| # | Item | User-visible change | Files |
|---|---|---|---|
| W-17 | Back button | Every detail page gets a "Back" control above the title: the in-app referrer, else the register route. | `components/ui/detail-header.blade.php`, `projects/_story-header.blade.php` |
| W-18 | Targets panel rows clickable | Indicator name → indicator, project name → project. | `desk/panels/⚡targets.blade.php` |
| W-19 | Project card: dates first | Start → end under every project name in all lenses; default lens "Dates". | `desk/panels/⚡projects.blade.php` |
| W-11 | Customize sheet without locked panels | The Add list shows allowed panels only. | `pages/desk/_customize-sheet.blade.php` |
| W-21 | Add indicator from the project page | "New indicator" in the project Targets tab opens the indicator form with the project preset. | `projects/_show-targets-tab.blade.php`, `indicators/⚡index.blade.php` |
| W-05 | Final report acceptance UI | "Record final report acceptance" (date, by, optional file) on the proposal page; without it `active → completed` is unreachable. | `proposals/_show-actions.blade.php`, `_show-modals.blade.php` |
| W-16 | Reports entry | "Donor Report Builder" → "Reports"; a donor-reports index (donor, proposal, state filters) under M&E. | `routes/web.php`, `Desk/Destinations.php`, new `pages/donor-reports/⚡index.blade.php` |
| W-28 | Unified status colors | One tone per meaning across every badge: good / warn / critical / active / neutral; critical is not the brand red. Fix the listed inconsistencies. | new `Enums/StatusTone.php`, `Enums/Concerns/HasStatusTone.php`, `ui/enum-badge.blade.php`, `FluxMarkup::statusPill` |
| W-20 | Desk scroll trap | Verify in the browser that the wheel over a panel scrolls the page. | `resources/css/desk.css`, `desk-shell.css` |
| W-22 | Stale docs | Correct `.ai/rules/desk.md` and `docs/redesign-plan/tracking-desk.md`. | docs |

## Batch 2 · after batch 1 review

| # | Item | Why later |
|---|---|---|
| W-29 | One recipe for sections and borders (`x-ui.panel` / `x-ui.section`, 43 divs) | Large, touches most pages; after W-28 so tones do not clash. |
| W-27 | To-do and Notes desk panels | Two new tables; small but not a simplification. |
| W-30 | "Main tabs not in the sidebar" | Waits for Mai to show the case. |

## Execution rules

- Each item = change + a Pest test for the behaviour and its important failure mode.
- No `contracts.md` change, no status write outside the §4 writers.
- `vendor/bin/pint --dirty` before each commit; one commit per item.
