---
paths:
  - 'resources/css/*.css'
  - 'resources/css/desk*.css'
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

## Light mode may change for the Desk redesign (supersedes "pixel-identical")
The "light mode must stay pixel-identical when restyling dark" notes in css.md and views.md predate the Tracking Desk redesign (branch tracking-desk-ux-update), which intentionally changes light mode to match the artifact. Desk styles live in resources/css/desk.css: every class is prefixed dk-, element selectors are scoped under .dk-scope, and artifact variables are aliases of tokens.css tokens so Graphite .dark works without a second block. Pages not yet redesigned keep their old light look until their stage replaces them.

## Critical as small text needs its own token in dark
`--color-status-critical` was lightened for dark everywhere except critical: `good` went #1d7a4c → #5cc690, `warn` → #e3ad52, `funding` → #79bccb, but `critical` stayed at the brand red #d4343a. On the dark page ground (#151618) that is **3.75:1** — fine for a dot or a bar (non-text, 3:1) and for large type, but it fails the 4.5:1 normal-text threshold at the 14px/600 a status word is set in.

So `--color-status-critical-text` (light `var(--color-primary)` 6.2:1 on white, dark #ff5a60 5.9:1 on the page ground) is the token for critical **type**; `--color-status-critical` stays the token for dots, bars, rings and other non-text marks. Added 2026-11-09 for the funding-agreements register's status word and headline figure.

Not yet applied elsewhere: every other `text-status-critical` at body size in dark has the same 3.75:1 — the detail page's attention list, the overdue and failed lines in the calendar lanes, the overdue amounts in the register cells. Switching those is a one-token change per site, worth doing as its own pass.
## Desk lists cannot take their own padding or margin
desk.css resets `.dk-scope ul/ol { margin:0; padding:0 }` and `.dk-scope p { margin:0 }` UNLAYERED, so it beats any padding/margin a `@layer components` class sets on a ul, ol or p (computes to 0 silently). Put side padding on the `li` items, and space blocks with a parent `gap`, never with margin on the ul/p. Found on the program Results tab (2026-11-06).

## Dark Desk has no glow; tabs look like page tabs; pins are pushpins
User decisions 2026-11-10: dark mode has no ambient red/green glow behind the Desk (`.dark [data-desk]::before { content: none }`). Desk tabs match the project page's tabs (.dk-pjtabs): words with icon on the page ground, one hairline, the tab in front marked by full ink and a brand underline; no boxed track. The tab row is transparent at rest and takes the ground once the page scrolls (scroll-driven animation). Sidebar pinning uses the `pushpin` Desk icon, never a star.

## Plain Desk ground and pill-track tabs (supersedes "Dark Desk has no glow; tabs look like page tabs")
User decisions 2026-11-10, replacing the earlier glow and tab notes: the Desk has no ambient glow in light or dark (no [data-desk]::before mesh). Desk tabs are a pill track: open tabs sit in one rounded var(--sunk) rail, and the tab in front is a raised var(--paper) pill with its icon in --brand-ink. Do not return to underline tabs. The tab row is pulled up over main's 28px top padding so it sits flush under the top bar, and the frames start at --top-h + --tabs-h, so no page slides under it. Pins stay pushpins.
