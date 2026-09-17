---
paths:
  - resources/views/components/app-logo.blade.php
  - resources/views/layouts/auth/simple.blade.php
---

# Components

## Brand logo: wordmark expanded, round mark collapsed, start-aligned
x-app-logo renders flux:sidebar.brand with NO `name`. The wordmark SVG already reads "HOPE CENTER Tracking", so passing a name printed the words twice.

Three assets sit at the public root beside favicon.svg (no new directory): logo-tracking.svg (red, #be1f24 + #6d6e71), logo-tracking-white.svg for dark, logo-tracking-icon.svg (self-contained red circle, used collapsed in both themes). They are the designer's files, unmodified — do not inline them as Blade SVGs: both wordmark and icon use generic .cls-1/.cls-2 with OPPOSITE meanings, and inlined <style> blocks are document-global, so the two would overwrite each other's fills.

Collapse and theme are switched on separate elements: outer spans choose wordmark-vs-icon, inner imgs choose red-vs-white. Putting both on one element leaves `dark:block` and `in-data-flux-sidebar-collapsed-desktop:hidden` at equal specificity, where stylesheet order decides the winner.

Collapsed geometry is tight and measured: the 4.5rem rail gives 72px, the collapse toggle takes the end 32px (end-1, size-7), so the mark is size-7 and the brand is forced `justify-start` when collapsed. Centred, a 36px mark overlapped the toggle by 7px; the old Laravel mark cleared it by 1px. Current gap is 3px, verified in LTR and RTL.

## Sidebar brand logo size bumped to size-9/h-9 to match hc-hr's bigger wordmark
Per explicit user direction ("make the tracking logo bigger, same style/position as HR's"), the sidebar wordmark (`h-7`→`h-9`) and collapsed icon mark (`size-7`→`size-9`) were enlarged, referencing hc-hr's sibling project sidebar (`../hc-hr/resources/views/livewire/layout/sidebar.blade.php`, logo at `w-[8.25rem]` in a `h-16` row). Verified in the browser (light/dark, expanded/collapsed) that size-9 still clears the floating collapse-toggle button (`components-navigation.md`'s geometry note used size-7 — that overlap math is now stale for the mark size, though the toggle's own position/sizing is unchanged). Don't shrink the mark back to size-7 without re-checking with the user; don't grow it further without re-verifying the collapsed-rail gap against the toggle.

## The login screen uses the same brand assets as the sidebar
`resources/views/layouts/auth/simple.blade.php` renders the same `logo-tracking.svg` / `logo-tracking-white.svg` pair as the sidebar brand, at `h-16`, with `alt=""` because the adjacent `.sr-only` span already names the app. Per explicit user direction the login logo must match the sidebar logo — do not give the auth layout its own wordmark. An earlier branch carried a separate `x-hope-pt-logo` component and a vendored Alex Brush font for this slot; both were removed when the logos were unified, so don't reintroduce a second brand mark.
