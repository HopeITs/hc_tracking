---
paths:
  - 'resources/views/{components/navigation/**,layouts/app/sidebar.blade.php}'
---

# App

## Keep sidebar navigation route-backed
The application sidebar is persistent from lg upward, uses a fluid 16rem-to-18rem width, and collapses only as a mobile drawer. Add navigation items only when their named routes exist; do not add placeholder links. Keep desktop rows dense while preserving Flux mobile touch targets.

## Sidebar now also collapses to icons on desktop; preferences moved to a top header
Supersedes the earlier "collapses only as a mobile drawer" note. `<flux:sidebar>` now uses `:collapsible="true"` (not `"mobile"`), enabling Flux's built-in desktop icon-only collapse alongside the existing mobile drawer — both are native Flux behavior, no custom JS. A hand-built round toggle bubble (`<ui-sidebar-toggle data-flux-sidebar-collapse>`, absolutely positioned on the sidebar's end edge, `hidden lg:block`) drives it; Flux's own `<flux:sidebar.collapse>` stays for the mobile-close button only (`lg:hidden`). Theme toggle, locale toggle and the user profile menu moved out of the sidebar into a new desktop-only `<flux:header>` in `layouts/app/sidebar.blade.php` (`hidden ... lg:flex`), matching a reference "upper navigation" design — the sidebar keeps its own copies of the theme/locale toggles but now `lg:hidden` (mobile-drawer only), and no longer renders a bottom profile menu at all on desktop. `x-desktop-user-menu` uses `<flux:profile>` (compact avatar+name trigger) now, not `<flux:sidebar.profile>`, since it's used in a header context, not a sidebar row.
