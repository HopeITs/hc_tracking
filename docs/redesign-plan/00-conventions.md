# 00 · Conventions (binding for every file)

These rules come from the codebase, `CLAUDE.md` and `.ai/rules/**`. Breaking one breaks a test or production behaviour.

## 1. Stack & tooling

- Laravel 13 · PHP **8.5** (isolated) · Livewire 4 single-file pages (`resources/views/pages/<domain>/⚡index.blade.php`, route `Route::livewire(..., 'pages::<domain>.index')`) · Flux 2 (free) · Tailwind 4 CSS-first · Pest.
- Use the project wrappers, **never bare `php`** (that is XAMPP's 8.4 and aborts):
  - PowerShell: `.\php.cmd artisan ...`, `.\composer.cmd ...`
  - Git Bash: `./php.cmd artisan ...`
- Frontend: `npm run dev` while working, `npm run build` before review.
- Run `vendor/bin/pint --dirty --format agent` (via `./php.cmd vendor/bin/pint ...`) after touching PHP/Blade PHP blocks.

## 2. Data access

- **Strict mode is on** (`Model::shouldBeStrict`). Lazy loading throws. Every relation a view touches must be eager-loaded in the `#[Computed]` query, including nested paths (`->with(['project.city', 'milestone.project'])`).
- Aggregate with `withCount`, `withSum`, and grouped queries. **Never query inside a Blade loop.**
- Heavy aggregates for charts go in their own `#[Computed]` method, so filters that don't affect them don't recompute them.
- Morph keys, never class names: `plan_indicators.source_type` is `'yearly_plan'` or `'proposal'`; media owners are `'project'` / `'milestone'`. Don't add morph map entries (`MorphMapTest` asserts exactly four).
- Filters, tabs and view modes that should survive reload/share use `#[Url]` properties, as existing pages do.

## 3. Money

- **Never sum or compare across currencies.** `USD`, `EUR`, `SYP` have no conversion anywhere. Every total, chart series and capacity bar is per currency.
- Always render amounts with `x-ui.money` (it throws without a currency, by design).
- A pivot's `currency` is a raw string; `DonorProposal->currency` is a `Currency` case. Read funding through `donorProposals()`.

## 4. Language, RTL, text

- **No literal UI text.** Every visible string, aria-label, chart label, empty state and tooltip goes through `__('group.key')` / `trans_choice()`, with keys added to **both** `lang/en/<group>.php` and `lang/ar/<group>.php`. `tests/Feature/LocalizationTest.php` fails on missing keys or lost placeholders.
- Put page keys in the page's own group file (the domain file listed in your instruction). Shared component keys go in `lang/*/ui.php` (foundation owner only). **Do not edit `common.php`** in page work; ask the foundation owner.
- Never name a group file after a word Flux translates bare (`results`, `of`, `to`, `files`, `showing`).
- Arabic plurals use explicit ranges: `{0} ...|{1} ...|{2} ...|[3,10] ...|[11,*] ...`.
- Enum labels via `$case->label()`; dates via `->translatedFormat(__('formats.date'))`.
- **Logical classes only:** `ms/me/ps/pe/start/end/text-start/text-end/border-s/border-e/inset-inline-*`. Never `left/right/ml/mr/pl/pr`. Flip directional icons with `rtl:-scale-x-100`.
- HR names: `->name` / `->secondary_name` (follow the interface language).
- Copy is the user's language: controls name their action ("Record reading", not "Submit"); errors say what's wrong and how to fix it.

## 5. Styling

- Tokens only (see `01-design-system.md`). No raw hex in Blade, no arbitrary colour values.
- Custom spacing tokens must be `--spacing-ui-*` (unprefixed names collapse Flux modals).
- Every surface must work in **light and dark** (`dark:` variants driven by `$flux.dark`) and in **RTL**.
- Container queries (`@container`, `@3xl/...`) over viewport breakpoints for page internals, matching existing pages.
- No gradient text, no decorative glass, no coloured side-stripe borders thicker than 1px, no zero-blur offset shadows, no emoji or unicode glyphs as icons (use Flux/Heroicons).
- Touch targets ≥ 44px on mobile (`max-lg:min-h-11`), visible `focus-visible` rings.
- Respect `prefers-reduced-motion` (`motion-reduce:` variants).

## 6. Livewire & Flux

- Keep existing public properties, action method names and `wire:click` signatures unless your file says to change them. Tests assert strings like `wire:click="openEditModal(12)"`.
- Loading states: `wire:loading` / `data-loading:` on the region that changes, never a full-page spinner.
- Blaze stays disabled (`config/blaze.php`); don't re-enable it.
- Authorization and validation stay exactly as they are; a redesign never loosens a policy.

## 7. Test hooks that must survive

| Hook | Where | Asserted by |
| --- | --- | --- |
| `data-app-page-container` | `layouts/app.blade.php` | `DashboardTest` |
| `data-navigation-sidebar`, `data-mobile-navigation-toggle`, `data-mobile-navigation-close`, `data-theme-toggle`, `data-locale-toggle` | shell | `DashboardTest`, `NavigationTest` |
| `data-current` on the active sidebar link | Flux sidebar item | `NavigationTest` |
| `data-tree-depth="N"` on tree nodes | tree component | `MilestoneDirectoryTest`, `ProgramDetailTest`, `ProjectDetailTest`, `ResultsFrameworkTest`, `UiComponentsTest` |
| `data-count-badge` only when count given | `page-header` | `UiComponentsTest` |
| `<html lang=".." dir="..">` | shell layout | `LocalizationTest` |
| escaped user content (`<script>` shown as text) | all pages | `RiskRegisterTest`, `TaskDetailTest` |
| `x-ui.enum-badge` tone classes (`bg-secondary-dark`, `bg-primary`, `border-secondary-light`) | enum-badge | `UiComponentsTest` |
| `x-ui.input` / `x-ui.button` classes | components | `UiComponentsTest` |

If your redesign intentionally changes an asserted string, update that test **in the same change** and say why in your review note. Never delete a test without the project owner's approval.

## 8. Tests for your page

- Every behaviour you add gets a Pest feature test in the existing file for that page (`tests/Feature/<Name>Test.php`; admin in `tests/Feature/Admin/`). No new `tests/Feature/Pages/` directory.
- Test data via factories; test helpers in `tests/Pest.php` return ids (see `.ai/rules/tests.md`).
- Run narrow: `./php.cmd artisan test --compact tests/Feature/ProjectDirectoryTest.php`. Full suite before review: `./php.cmd artisan test --compact` (baseline on 2026-09-15: 816 tests, 810 passed, 6 skipped).

## 9. Git

- Branch: `design-enhancement`. Work in small, page-scoped changes.
- **Do not commit or push.** Leave changes in the working tree; the project owner reviews every file before it enters history.

## 10. Definition of done (every page)

- [ ] Layout matches the instruction file at 1440px, 1024px and 390px widths.
- [ ] Light + dark, English + Arabic (RTL) all checked in a browser.
- [ ] Empty, filtered-empty, loading and validation-error states render.
- [ ] No N+1 (Debugbar/query log checked with 50+ rows) and no lazy-loading exception.
- [ ] All strings translated in en + ar; `LocalizationTest` green.
- [ ] Page tests + `UiComponentsTest` + `NavigationTest` green; Pint clean; `npm run build` succeeds.
