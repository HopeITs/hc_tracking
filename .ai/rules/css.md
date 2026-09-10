---
paths:
  - resources/css/app.css
---

# Css

## Use the approved design tokens
Keep Tailwind configuration CSS-first in resources/css/app.css. The approved palette is primary #be1f24 and secondary dark/medium/light/soft #58595b/#6d6e71/#939598/#dcddde. Preserve the 4px numeric scale and the named spacing aliases defined in @theme.

## Namespace custom spacing scale tokens
Prefix raw spacing scale tokens with `--spacing-ui-*`. Unprefixed names such as `--spacing-xs` and `--spacing-xl` override Tailwind v4 width tokens, which collapses Flux `min-w-xs` / `max-w-xl` modals. Keep semantic aliases (`--spacing-control`, `--spacing-card`, `--spacing-section`, `--spacing-page`) pointing at the namespaced scale.
