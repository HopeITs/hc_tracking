# Dark mode redesign — "Graphite"

**Date:** 2026-09-29 · **Status:** approved in brainstorming, awaiting spec review · **Scope:** whole app, dark mode only

## 1. Problem

Dark mode today is built from the brand greys, which are mid-greys, not dark greys:

| Pair (today) | Contrast | WCAG AA |
| --- | --- | --- |
| `#be1f24` brand red on `#58595b` panel | 1.14 : 1 | fails |
| `#dcddde` (`text-secondary-soft`, 688 uses) on `#6d6e71` (`bg-secondary-medium`) | 3.75 : 1 | fails |
| status critical / good / warn on `#58595b` | 2.52 / 3.06 / 3.26 : 1 | fail |
| `#939598` on `#58595b` | 2.33 : 1 | fails |

- Depth is inverted: body is near-black `#262626` (`zinc-800`), panels are lighter mid-grey — reads as grey-on-grey, not dark.
- There is no semantic layer: 2,567 hand-written `dark:` utilities across 131 view files plus 104 `!important` rules in `app.css`. Fixing dark mode currently means editing every page.
- Every layout hardcodes `class="dark"` on `<html>`, and the toggle is a two-state moon/sun.

## 2. Decisions (from brainstorming)

1. **Direction:** "Graphite" — cool charcoal derived from the brand grey hue; depth from lightness steps; brand red used rarely and meaningfully.
2. **Palette freedom:** new dark-only values are allowed **only if derived from the brand colours** (brand grey hue for neutrals, `#be1f24` hue for the dark red).
3. **Light mode must stay pixel-identical.** Only `dark:`-prefixed classes and `.dark`-scoped CSS change.
4. **Approach:** semantic dark tokens + scripted sweep of `dark:` classes + manual review of ambiguous cases. (Rejected: CSS-only remap of brand greys under `.dark` — `secondary-dark` is both a surface and an ink, so a global remap breaks text; Flux-only theming — leaves the 2,567 hand-written classes untouched.)
5. **Extras in scope:** charts/Gantt/data-viz tuning; Light / Dark / System toggle; smooth theme switch; polish (scrollbars, selection, focus, logo, preloader, PWA theme colour, image dimming).
6. **Default for users with no stored choice:** **System** (follow OS). Stored choices are kept.

## 3. Tokens

Added to `@theme` in `resources/css/app.css` (light values are sensible equivalents so the utilities exist, but they are only used behind `dark:`), overridden in the existing `.dark` block.

| Token | Light value | Dark value | Role |
| --- | --- | --- | --- |
| `--color-surface-0` | `#ffffff` | `#151618` | page ground |
| `--color-surface-1` | `#ffffff` | `#1c1d20` | panels, cards, sidebar, header |
| `--color-surface-2` | `#f8f8f9` | `#242528` | inputs, table heads, hover rows |
| `--color-surface-3` | `#ffffff` | `#2d2e32` | modals, menus, popovers, toasts |
| `--color-ink` | `#58595b` | `#ececee` | primary text |
| `--color-ink-muted` | `#6d6e71` | `#a8aaae` | supporting text |
| `--color-ink-faint` | `#939598` | `#8a8c93` | meta, captions, placeholders only |
| `--color-line` | `#dcddde` | `#34353a` | hairline dividers (decorative) |
| `--color-line-strong` | `#939598` | `#4a4b51` | emphasized dividers, threads |
| `--color-field-line` | `#939598` | `#6b6d74` | input borders (≥ 3 : 1, WCAG 1.4.11) |
| `--color-brand` | `#be1f24` | `#d4343a` | primary fill, active marker |
| `--color-brand-hover` | `#a81b20` | `#e04046` | primary fill hover |
| `--color-brand-text` | `#be1f24` | `#ff7b7f` | red text, links, errors |

Existing tokens retuned inside `.dark` only:

| Token | Dark (new) | Contrast |
| --- | --- | --- |
| `--color-status-good` / `-soft` | `#5fd09a` / `#14281e` | 8.1 : 1 on soft, ≥ 7.1 on all surfaces |
| `--color-status-warn` / `-soft` | `#e8b050` / `#2c2313` | 7.9 : 1 on soft |
| `--color-status-critical` / `-soft` | `#ff8a86` / `#381a1c` | 6.9 : 1 on soft |
| `--color-status-active` / `-soft` | `#7db3ec` / `#172637` | 7.0 : 1 on soft |
| `--color-funding` / `-soft` | `#7cc3cf` / `#122629` | 7.9 : 1 on soft |
| `--color-ground` | `var(--color-surface-0)` | |
| `--color-board` | `var(--color-surface-1)` | |
| `--color-board-line` | `var(--color-line)` | |
| `--color-board-head` | `var(--color-surface-2)` | |

Measured contrast of text tokens on surfaces 0 / 1 / 2 / 3:

| Text | s0 | s1 | s2 | s3 |
| --- | --- | --- | --- | --- |
| ink | 15.4 | 14.3 | 13.0 | 11.5 |
| ink-muted | 7.8 | 7.2 | 6.6 | 5.8 |
| brand-text | 7.2 | 6.7 | 6.1 | 5.4 |
| white on brand fill `#d4343a` | 4.83 | | | |
| brand fill vs s1 (non-text) | 3.49 | | | |

`ink-faint` is ≥ 4.4 : 1 on s0–s1; it is reserved for meta/placeholder text and never used for body copy on s2/s3.

**Elevation rule:** each layer is one lightness step up (s0 → s1 → s2 → s3). No drop shadows in dark; raised surfaces (s1 panels, s3 overlays) get a 1px inset top highlight `inset 0 1px 0 rgb(255 255 255 / 0.04)` via a `.dark` component rule. Panels lose their borders in dark; separation comes from the lightness step.

**Brand rule:** red appears only on the primary action, the current nav item marker, and critical states.

## 4. Sweep mapping

A one-off script (kept in the session scratchpad, not in the repo) rewrites `dark:` class tokens in `resources/views/**/*.blade.php`. It touches only tokens that start with `dark:` (including `dark:hover:`, `dark:focus:`, `dark:aria-*:` variants and the `!` suffix, which is preserved).

**Automatic mappings**

| From | To |
| --- | --- |
| `dark:text-secondary-soft` | `dark:text-ink-muted` |
| `dark:text-white` | `dark:text-ink` *(except in a class list that also contains `bg-primary`/`dark:bg-brand` — stays white; flagged)* |
| `dark:hover:text-white` | `dark:hover:text-ink` |
| `dark:text-secondary-medium`, `dark:text-secondary-light` | `dark:text-ink-faint` |
| `dark:text-zinc-400` | `dark:text-ink-muted` |
| `dark:bg-secondary-dark` | `dark:bg-surface-1` |
| `dark:bg-secondary-dark/95` | `dark:bg-surface-1/95` |
| `dark:bg-secondary-medium` | `dark:bg-surface-2` |
| `dark:hover:bg-secondary-medium` | `dark:hover:bg-surface-2` |
| `dark:bg-zinc-800`, `dark:bg-zinc-900` | `dark:bg-surface-0` |
| `dark:border-secondary-medium`, `dark:divide-secondary-medium`, `dark:border-zinc-700`, `dark:border-stone-600` | `dark:border-line` / `dark:divide-line` |
| `dark:border-secondary-light` | `dark:border-field-line` |
| `dark:border-secondary-dark` | `dark:border-surface-1` |
| `dark:ring-secondary-dark` | `dark:ring-surface-1` |
| `dark:ring-secondary-medium` | `dark:ring-line` |
| `dark:text-status-critical`, `dark:hover:text-status-critical` | unchanged (token retuned) |
| `dark:*-white/NN`, `dark:bg-white/[…]` | unchanged (white washes work on charcoal) |

**Manual review list** (script reports every occurrence with file:line; each is decided by reading the element in context):

- `dark:bg-white` (92), `dark:border-white` (40), `dark:ring-white` (13) — inverted/high-emphasis elements.
- `dark:text-secondary-dark` (36) — text sitting on an inverted white surface.
- `dark:bg-secondary-soft` (12), `dark:hover:bg-secondary-soft`, `dark:border-secondary-soft`.
- Opacity variants of brand greys: `dark:bg-secondary-medium/NN`, `dark:hover:bg-secondary-medium/NN`, `dark:bg-secondary-dark/NN`, `dark:hover:bg-secondary-dark/10`.
- `dark:shadow-none` / `dark:hover:shadow-none` — confirm the top-edge highlight replaces them where appropriate.
- `dark:to-neutral-900` gradients and any arbitrary-value `dark:[…]` selectors.
- Any `dark:` token left over that references `secondary-*`, `zinc-*`, `neutral-*`, `stone-*` after the sweep (the script fails if it finds one unmapped and unlisted).

**Light-mode guarantee check:** for every changed file, the script strips all `dark:` tokens from old and new versions of each line and asserts they are byte-identical. Any mismatch aborts the sweep.

## 5. Components

1. **App shell** (`layouts/app/sidebar.blade.php`, `layouts/app/header.blade.php`, `components/navigation/sidebar.blade.php`): body `surface-0`; sidebar and headers `surface-1` with a `line` hairline; active nav item `surface-2` + `ink` + 2px `brand` marker on the logical start edge (`border-s-2` / `before:start-0`, RTL-safe).
2. **Panels / cards** (`ui/panel`, `ui/stat-tile`, `ui/logframe-node`, `ui/framework-node`, `ui/file-card`): `surface-1`, borderless in dark, top-edge highlight.
3. **Tables** (`ui/data-table*`, `ui/tree-rows`, Flux tables, `.admin-directory`): header `surface-2` + `ink-faint` labels; `line` row dividers; hover `surface-2`; selected `brand/10`.
4. **Forms** (`ui/form-*`, `ui/input`, `ui/select`, `ui/multi-select-popover`, `ui/header-filter-*`, `.program-directory-filters`, `.filter-picker`): field `surface-2` + `field-line`; focus keeps the existing neutral 2px outline with 2px offset (per `.ai/rules/auth.md`), coloured `ink-muted` in dark; errors `brand-text`.
5. **Overlays** (`ui/modal`, `ui/confirm-modal`, Flux modal/menu/dropdown/popover, notifications toasts and bell panel): `surface-3`, backdrop `black/60`.
6. **Buttons** (`ui/button`, Flux buttons): primary `brand` fill, hover `brand-hover`, white text; secondary `surface-2` + `ink`; ghost hover `white/6%`. Flux accent variables in `.dark` point at `ink` / `surface-0` for neutral-accent controls.
7. **Badges** (`ui/status-pill`, `ui/enum-badge`, `ui/schedule-status`, `ui/status-strip`): status `-soft` background + status text.
8. **Data visuals:**
   - `resources/js/charts.js`: series and axis colours read from CSS variables at render time; gridlines `line`, axis text `ink-faint`, tooltip `surface-3`. Chart series order stays as in `docs/redesign-plan/01-design-system.md` §2, with `secondary-dark` resolved to `ink-muted` in dark (so series 1 is visible on charcoal).
   - Gantt (`ui/gantt`, `resources/js/gantt/**`, `.dark [data-gantt]` rules): `--gantt-surface*` variables map to `surface-1/2` and `brand/…`; scrollbar uses `line-strong`.
   - Dot-and-thread / timelines (`ui/timeline`, `ui/milestone-timeline`, `ui/project-timeline`, `ui/target-lineage`, `ui/logframe*`): threads `line-strong`, dots `ink-muted`, current item `brand`.
   - `ui/progress-bar`, `ui/progress-ring`, `ui/capacity-bar`: track `surface-2`, fill unchanged tokens.
9. **`app.css` `.dark` rules:** every `.dark …` selector (gantt, notifications, preloader, sidebar nav, admin-directory, filters, search cancel button) is rewritten to use the tokens. Their light-mode counterparts are not edited.

## 6. Toggle, motion, polish

1. **Appearance menu:** the header moon/sun button (`layouts/app/sidebar.blade.php`, mobile and desktop headers) becomes a Flux dropdown with Light / Dark / System bound to `$flux.appearance`. Trigger icon reflects the current mode (sun / moon / computer-desktop). Labels in `lang/en` and `lang/ar`.
2. **Default System:** remove the hardcoded `class="dark"` from all five layouts (`app/sidebar`, `app/header`, `auth/card`, `auth/simple`, `auth/split`). `@fluxAppearance` in `partials/head.blade.php` sets the class before first paint; stored choices are preserved.
3. **Smooth switch:** changing appearance runs inside `document.startViewTransition()` when available (≈ 200 ms cross-fade via `::view-transition-old/new(root)`); instant fallback; instant when `prefers-reduced-motion: reduce`.
4. **Live re-theme:** charts and the Gantt listen for the theme change (a `MutationObserver` on `<html>` `class`) and re-read CSS variables.
5. **Polish:**
   - `.dark { color-scheme: dark; }` — native scrollbars, date pickers, autofill, search-cancel render dark.
   - `::selection` → `brand` at 35%.
   - Scrollbars (`scrollbar-color`) → `line-strong` on transparent.
   - `meta[name=theme-color]` dark value → `#1c1d20`, and it is updated live when the user switches.
   - Preloader (`.hc-preloader`) dark ground → `surface-0`; logo checked on charcoal.
   - `.dark img:not([data-no-dim])` → `filter: brightness(.9)`; logos, file previews and charts carry `data-no-dim`.

## 7. Verification

1. **Light mode identical:** the sweep's line-level check (§4) plus before/after light screenshots of dashboard, projects index/show, proposals, donors, indicators, calendar, tasks, admin users, login.
2. **Dark mode:** screenshots of the same pages in dark, in English and Arabic (RTL); an in-page contrast scan flags any text below 4.5 : 1 (3 : 1 for large text and non-text boundaries). Requires the user to be signed in inside the browser pane.
3. **Toggle:** switch Light → Dark → System in the browser; confirm no flash on reload in each mode, and charts/Gantt re-colour without reload.
4. **Tests (Pest, affected files only — never the full suite):**
   - Layouts no longer render `class="dark"` on `<html>`.
   - The header appearance menu renders Light, Dark and System options.
   - Any existing test that asserts on a class string touched by the sweep is updated and run.
5. **Formatting:** `vendor/bin/pint --dirty --format agent` if any PHP changes.

## 8. Out of scope

- Any light-mode visual change.
- Layout, spacing, typography or copy changes beyond what dark mode needs.
- New components or pages.

## 9. Housekeeping

- No commits. All changes are left staged for the user to review file by file.
- Record a rule with Boost `record-rule` (glob `resources/views/**/*.blade.php` and `resources/css/app.css`): "Dark mode uses the Graphite tokens (`surface-0..3`, `ink*`, `line*`, `field-line`, `brand*`) — never raw `secondary-*`/`zinc-*` greys behind `dark:`."
- `app.css` and `layouts/app/sidebar.blade.php` already have uncommitted work in the tree; edits build on top of it without reverting anything.
