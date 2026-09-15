---
paths:
  - resources/css/app.css
---

# Css

## Use the approved design tokens
Keep Tailwind configuration CSS-first in resources/css/app.css. The approved palette is primary #be1f24 and secondary dark/medium/light/soft #58595b/#6d6e71/#939598/#dcddde. Preserve the 4px numeric scale and the named spacing aliases defined in @theme.

## Namespace custom spacing scale tokens
Prefix raw spacing scale tokens with `--spacing-ui-*`. Unprefixed names such as `--spacing-xs` and `--spacing-xl` override Tailwind v4 width tokens, which collapses Flux `min-w-xs` / `max-w-xl` modals. Keep semantic aliases (`--spacing-control`, `--spacing-card`, `--spacing-section`, `--spacing-page`) pointing at the namespaced scale.

## Status/funding/board tokens are approved for status, data-viz and board surfaces only
`--color-status-good/-warn/-critical` (+`-soft`), `--color-funding` (+`-soft`), `--color-ground`, `--color-board`, `--color-board-line`, `--color-board-head` were added to `@theme` (light) and `.dark` (per `docs/redesign-plan/01-design-system.md` §2/§6.1), each with a `.dark` override. Use them only for record status, data visualization and panel/board surfaces — never as a brand color, on buttons, or in navigation. `.type-heading`/`.type-meta`/`.type-label` utility classes were added in a new `@layer components` block (01 §3); prefer them over repeating the same text-xs/uppercase/tracking utility combinations inline. No raw hex outside `app.css` — always reference the CSS variable/Tailwind token.
