# 04 · App shell (sidebar, layout, desktop top bar)

**Owner group:** Shell · **Wave:** 0b (after 01) · **Parallel with:** 05

## Files you own

- `resources/views/components/navigation/sidebar.blade.php`
- `resources/views/layouts/app/sidebar.blade.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/components/desktop-user-menu.blade.php`, `resources/views/components/app-logo.blade.php`
- `lang/*/navigation.php`
- `tests/Feature/NavigationTest.php`, `tests/Feature/DashboardTest.php` (shell assertions only)

Delete `resources/views/layouts/app/header.blade.php` **only after** confirming nothing references it (`grep -r "app.header" resources`).

## Binding rules (`.ai/rules/app.md`, `navigation-css.md`)

- Sidebar persistent from `lg`, fluid width `clamp(16rem, 18vw, 18rem)`, mobile drawer below `lg`.
- Nav items only for **existing named routes**. No placeholders.
- Keep the theme toggle and locale toggle in the sidebar header, with `data-theme-toggle` / `data-locale-toggle`.
- Keep `data-navigation-sidebar`, `data-mobile-navigation-toggle`, `data-mobile-navigation-close`, `data-app-page-container`, `data-current`.
- `<html lang dir>` stays driven by `App\Enums\Locale::current()`.
- Mobile touch targets ≥ 44px; desktop rows dense.

## Current state

Dark `secondary-dark` sidebar, 7 groups (Overview, Strategy, Delivery, Results & Learning, Funding, Administration, Settings) with Heroicons, active item filled primary. Mobile top header with hamburger + profile dropdown. No desktop header, so there's no place for global search.

## Target

### Sidebar
1. **Same groups, same order, same routes.** Visual refresh:
   - Group labels `.type-label`, `secondary-light` on dark.
   - Items 36px high (44px on mobile), icon 18px, label `type-body` 500.
   - Active item: `bg-white/8` fill + a 2px primary **indicator inside the item at inline-start** (rounded, inset 8px top/bottom, not a card border) + white text + primary-coloured icon. Hover `bg-white/5`.
   - Groups collapsible (Alpine `x-collapse`, state in `localStorage` under `hc.nav.<group>`, try/catch) **except** the group that contains the current route, which is always open.
2. **Search trigger** at the top of the nav (below the header): a button styled as an input, "Search…" + `Ctrl K` / `⌘K` hint (detect platform with `navigator.platform`), dispatches `open-command-palette`. See `05-command-palette.md`.
3. Footer: user menu (unchanged behaviour), plus the app version is **not** added.

### Layout (`layouts/app.blade.php`)
- `flux:main` background → `surface-sunken` (light) / `secondary-dark` variant per `01-design-system.md`; page container `max-w-[90rem]` stays, padding `--spacing-page` (16px on mobile).
- Include the command palette component once: `<livewire:command-palette />` (name per 05).

### Mobile header (`layouts/app/sidebar.blade.php`)
- Keep hamburger + profile dropdown. Add a search icon button (44px) before the profile that dispatches `open-command-palette`.

### Desktop top bar
- **Not added.** The sidebar search trigger + Ctrl/⌘K cover global search; breadcrumbs stay in `x-ui.detail-header` on detail pages. This keeps vertical space for data.

## Tests

- `NavigationTest`: add a test that the search trigger renders with `data-command-palette-trigger` on desktop and mobile headers. Keep every existing assertion.
- `DashboardTest`: unchanged assertions must pass.

## Acceptance

- [ ] All existing nav tests green; `data-current` still on the active link.
- [ ] Collapse state persists; the current route's group can't be collapsed.
- [ ] RTL: indicator sits on the right edge; chevrons flip.
- [ ] Keyboard: Tab order sidebar → main; Escape closes mobile drawer (existing handler kept).
- [ ] Light/dark, en/ar checked at 1440 / 1024 / 390.
