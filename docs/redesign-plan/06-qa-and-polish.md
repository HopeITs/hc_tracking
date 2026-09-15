# 06 · QA and polish pass

**Owner group:** QA · **Wave:** 2 (after all pages are `review`)

## Files you own

None by default. File issues against page owners; fix directly only with the owner's agreement.

## Checklist per page (record results in the tracker notes)

1. **Viewports:** 1440, 1280, 1024, 768, 390. No horizontal page scroll; only tables/timelines/kanban scroll inside their own container.
2. **Themes × languages:** light-en, dark-en, light-ar, dark-ar.
3. **States:** empty database, filtered-empty, 1 record, 200+ records (seeded), validation errors in every modal, `wire:loading` visible on slow network (Chrome throttling "Slow 3G").
4. **Accessibility:** keyboard-only pass (tab order, focus visible, modals trap and restore focus), axe DevTools zero serious issues, charts have sr-only tables, progress components have aria values.
5. **Contrast:** status chips and text on tinted surfaces ≥ 4.5:1.
6. **Performance:** query count per page with 200+ records (Debugbar or `DB::listen` in a test); target ≤ 15 queries for index pages, ≤ 20 for dashboard; no lazy-loading exceptions.
7. **Consistency:** same component for the same job across pages (status → `enum-badge`/`rag-chip`, dates vs plan → `schedule-status`, money → `money`/`capacity-bar`). Spacing rhythm and header structure identical across index pages.
8. **Copy:** no English leaking into Arabic, action labels name the action, no "Submit"/"OK" generic buttons.
9. **Money:** grep the diff for any `sum(` or chart series combining currencies.

## Final gates

- [ ] `./php.cmd artisan test --compact` green (≥ baseline 816 tests; new tests added by pages).
- [ ] `vendor/bin/pint --dirty --format agent` clean.
- [ ] `npm run build` succeeds, no console errors on any page.
- [ ] Remove dead code the redesign replaced: old `.admin-directory` CSS once admin pages migrated, `resources/views/dashboard.blade.php` once the dashboard page ships, `pages/milestones/_tree-node.blade.php` once milestones uses `x-ui.tree`.
- [ ] Hand the owner a list of changed files per page; **no commits** (owner reviews first).
