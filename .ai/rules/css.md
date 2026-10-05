---
paths:
  - resources/css/*.css
---

# Css

## Use the approved design tokens
Keep Tailwind configuration CSS-first in resources/css: app.css is only the import manifest and @source list; tokens and @theme live in tokens.css, and each feature has its own partial that states its layer (own classes in @layer components, Flux overrides in @layer utilities, unlayered only where a comment says why). The approved palette is primary #be1f24 and secondary dark/medium/light/soft #58595b/#6d6e71/#939598/#dcddde. Preserve the 4px numeric scale and the named spacing aliases defined in @theme.

## Namespace custom spacing scale tokens
Prefix raw spacing scale tokens with `--spacing-ui-*`. Unprefixed names such as `--spacing-xs` and `--spacing-xl` override Tailwind v4 width tokens, which collapses Flux `min-w-xs` / `max-w-xl` modals. Keep semantic aliases (`--spacing-control`, `--spacing-card`, `--spacing-section`, `--spacing-page`) pointing at the namespaced scale.

## Status/funding/board tokens are approved for status, data-viz and board surfaces only
`--color-status-good/-warn/-critical` (+`-soft`), `--color-funding` (+`-soft`), `--color-ground`, `--color-board`, `--color-board-line`, `--color-board-head` were added to `@theme` (light) and `.dark` (per `docs/redesign-plan/01-design-system.md` §2/§6.1), each with a `.dark` override. Use them only for record status, data visualization and panel/board surfaces — never as a brand color, on buttons, or in navigation. `.type-heading`/`.type-meta`/`.type-label` utility classes were added in a new `@layer components` block (01 §3); prefer them over repeating the same text-xs/uppercase/tracking utility combinations inline. No raw hex outside `app.css` — always reference the CSS variable/Tailwind token.

## Dark mode uses the Graphite tokens, never raw greys
Dark mode uses the Graphite tokens (`surface-0..3`, `ink`/`ink-muted`/`ink-faint`, `line`/`line-strong`, `field-line`, `brand`/`brand-hover`/`brand-text`, `shadow-raised`) — never raw `secondary-*`/`zinc-*`/`neutral-*`/`stone-*` greys behind `dark:` or inside `.dark` rules (spec docs/superpowers/specs/2026-09-29-dark-mode-graphite-design.md). They live in `@theme` with light equivalents that are only referenced behind `dark:`, and get their real values in the `@layer theme .dark` block; light mode must stay pixel-identical. Surfaces step one lightness level per layer (page s0 → panels/sidebar/header s1 → inputs/table heads/hover s2 → modals/menus/popovers/toasts s3). Red appears only on the primary action, the current nav marker and critical states; red text in dark is `brand-text` (or `status-critical` for alarms). `ink-faint` is for meta/placeholder text only, never body copy on s2/s3.

## Flux dark overrides sit in @layer utilities
Flux colours itself with its own `dark:` utilities, which a `@layer components` rule can never beat and an unlayered rule would beat app-wide. So the Graphite Flux overrides (modals, menus, tooltips, fields, table columns/cells, invalid fields) sit in an `@layer utilities` block after Tailwind's, using `:where(.dark)` selectors at the specificity of the Flux class they replace. Side effect: they also beat any non-`!` page `dark:` utility on those Flux parts — use `!` in a view only when a page must differ.
