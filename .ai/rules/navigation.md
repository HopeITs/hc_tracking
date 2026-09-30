---
paths:
  - 'resources/views/components/{navigation/sidebar.blade.php,ui/panel.blade.php,ui/stat-tile.blade.php,ui/data-table.blade.php,ui/timeline.blade.php}'
---

# Navigation

## Sidebar is light-themed; cards use soft shadows (supersedes the earlier flat/no-shadow board guard)
Per explicit user direction (screenshot-driven redesign), the persistent sidebar (`components/navigation/sidebar.blade.php`) is now light in light mode (`bg-white`, gray group labels, red `bg-primary` active pill) and keeps the original dark look only under `dark:`. Card-like surfaces — `x-ui.panel`, `x-ui.stat-tile`, `x-ui.data-table`, `x-ui.timeline` — now use `rounded-xl`/`rounded-full` radii and a soft `shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)]` for elevation. Dark mode (Graphite, 2026-09-29) supersedes the earlier "dark: `shadow-none`, border only": card roots are borderless (`dark:border-transparent`) with the `dark:shadow-raised` top-edge highlight, and separation comes from the surface step (page `surface-0`, cards `surface-1`). The sidebar's dark current item is no longer the red pill: it is `surface-2` + `ink` with a 2px `brand` marker on the logical start edge (`before:start-0`), hidden in the collapsed rail. This intentionally supersedes 01-design-system.md's "no shadows on boards" Operations Board guard — that plan predates this direction change. New card-shaped components should match this same radius/shadow pair for consistency; don't reintroduce the flat-no-shadow style without checking with the user first.
