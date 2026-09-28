---
paths:
  - 'resources/views/{components/navigation/**,layouts/app/sidebar.blade.php}'
  - 'resources/views/{components/navigation/sidebar.blade.php,components/desktop-user-menu.blade.php,layouts/app/sidebar.blade.php}'
---

# App

## Keep sidebar navigation route-backed
The application sidebar is persistent from lg upward, uses a fluid 16rem-to-18rem width, and collapses only as a mobile drawer. Add navigation items only when their named routes exist; do not add placeholder links. Keep desktop rows dense while preserving Flux mobile touch targets.

## Sidebar now also collapses to icons on desktop; preferences moved to a top header
Supersedes the earlier "collapses only as a mobile drawer" note. `<flux:sidebar>` now uses `:collapsible="true"` (not `"mobile"`), enabling Flux's built-in desktop icon-only collapse alongside the existing mobile drawer — both are native Flux behavior, no custom JS. A hand-built round toggle bubble (`<ui-sidebar-toggle data-flux-sidebar-collapse>`, absolutely positioned on the sidebar's end edge, `hidden lg:block`) drives it; Flux's own `<flux:sidebar.collapse>` stays for the mobile-close button only (`lg:hidden`). Theme toggle, locale toggle and the user profile menu moved out of the sidebar into a new desktop-only `<flux:header>` in `layouts/app/sidebar.blade.php` (`hidden ... lg:flex`), matching a reference "upper navigation" design — the sidebar keeps its own copies of the theme/locale toggles but now `lg:hidden` (mobile-drawer only), and no longer renders a bottom profile menu at all on desktop. `x-desktop-user-menu` uses `<flux:profile>` (compact avatar+name trigger) now, not `<flux:sidebar.profile>`, since it's used in a header context, not a sidebar row.

## Only Security is linked from nav — three separate places, keep them in sync
By explicit user request, navigation does not link to the profile/appearance settings pages — the "Settings" sidebar group was removed, and the "Settings" menu items were dropped from both profile dropdowns. Since 2026-09-28 (user request) both profile dropdowns carry exactly one settings link, "Security" (`security.edit`, data-test="security-menu-item"), above Log out, so people can reach sign-in codes, the authenticator app and trusted devices. Do not add Profile/Appearance back without asking. The settings routes/pages themselves (config/fortify.php's `home`, routes/settings.php, resources/views/pages/settings/**) were NOT touched and still work by direct URL — only the nav entry points were removed.

There are THREE separate places this had to change, and they're easy to let drift back out of sync since they're not one shared component:
1. `components/navigation/sidebar.blade.php` — the "Settings" `<flux:sidebar.group>` (was the last group, after Administration).
2. `components/desktop-user-menu.blade.php` — the shared profile-dropdown component, used both in the desktop top header (`layouts/app/sidebar.blade.php`) and (per `.ai/rules/app.md`) previously in the sidebar itself; its `<flux:menu.item>` for Settings was removed, leaving only the logout form.
3. `layouts/app/sidebar.blade.php`'s own separate "Mobile User Menu" `<flux:header>` dropdown (NOT `x-desktop-user-menu` — a second, independently hand-rolled dropdown for the `lg:hidden` mobile top bar) — its Settings `<flux:menu.radio.group>` was removed too, along with the now-redundant surrounding `<flux:menu.separator />`.

If Settings navigation is ever restored, all three need the item added back, not just one.
