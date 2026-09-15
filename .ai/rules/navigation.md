---
paths:
  - 'resources/views/components/{navigation/sidebar.blade.php,ui/panel.blade.php,ui/stat-tile.blade.php,ui/data-table.blade.php,ui/timeline.blade.php}'
---

# Navigation

## Sidebar is light-themed; cards use soft shadows (supersedes the earlier flat/no-shadow board guard)
Per explicit user direction (screenshot-driven redesign), the persistent sidebar (`components/navigation/sidebar.blade.php`) is now light in light mode (`bg-white`, gray group labels, red `bg-primary` active pill) and keeps the original dark look only under `dark:`. Card-like surfaces — `x-ui.panel`, `x-ui.stat-tile`, `x-ui.data-table`, `x-ui.timeline` — now use `rounded-xl`/`rounded-full` radii and a soft `shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)]` (dark mode: `shadow-none`, border only) for elevation. This intentionally supersedes 01-design-system.md's "no shadows on boards" Operations Board guard — that plan predates this direction change. New card-shaped components should match this same radius/shadow pair for consistency; don't reintroduce the flat-no-shadow style without checking with the user first.
