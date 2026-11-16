# Sectioned Modal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the step wizard from every `x-ui.modal` and replace it with one centered dialog that shows all its sections. Forms with 5+ sections also get a section index.

**Architecture:**
- The wizard lives in one place: `x-ui.modal` + `resources/js/modal-shell.js` + `resources/css/desk-shell.css`. It is removed there.
- `x-ui.modal` counts its top-level `x-ui.form-section` blocks on the server. When there are 5 or more, it marks the dialog `modal-shell-indexed` and renders an index nav. Alpine fills and drives the nav from the DOM: no extra Livewire round trips.
- `x-ui.form-section` always puts its heading above its fields. It gains an `optional` flag and a `data-invalid-count` attribute.
- A per-form pass then tunes the 12 former wizard forms.

**Tech Stack:** Laravel 12 / Livewire 4 / Flux UI / Alpine / Tailwind v4, Pest. PHP runs through `.\php.cmd`, never bare `php`.

**Spec:** `docs/superpowers/specs/2026-11-16-sectioned-modal-design.md`
**Mockup (reference look):** `C:\Users\youss\AppData\Local\Temp\claude\88c34cb1f755d2bb\733a4e73-a42c-439f-8cac-473a6f219ec1\scratchpad\sectioned-modal-mockup.html`

## Global Constraints

- **Commits:** never commit. Leave every change uncommitted; the user reviews each file. This replaces the skill's "Commit" steps.
- **Tests:** never run the full suite. Run only the files named in each task: `.\php.cmd artisan test --compact <file>`.
- **Dev server:** never start a dev server. The user's `composer run dev` owns :8000/:5173. If styles don't update, ask the user.
- **Frozen markup:** no change to validation, Livewire actions, `wire:model` names, routes, permissions or `data-test` hooks.
- **Thresholds:**
  - `IndexMinimumSections = 5`
  - indexed width `--pop-w-indexed: 960px`
  - other form dialogs keep `--pop-w: 760px`
  - `sm` dialogs keep 440px
  - index column `200px`
  - full-screen sheet below `40rem`
- **Colours:**
  - red only for errors, the required `*` and the primary Save
  - green "done" uses `--color-status-good`
  - dark mode uses Graphite tokens only: dialog `surface-3`, index `surface-2`
  - light mode outside the dialog must stay pixel-identical (`.ai/rules/css.md`)
- **Direction:** logical properties only (`inset-inline-start`, `margin-inline-*`, `border-inline-end`), so Arabic mirrors.
- **Motion:** smooth scroll unless `prefers-reduced-motion: reduce`, which scrolls instantly.
- **Lang:** every new key goes in both `lang/en/common.php` and `lang/ar/common.php`.
- **Formatting:** after PHP or lang edits, run `vendor/bin/pint --dirty --format agent`.

## Review Focus

1. **A proposal variant drops below 5 sections.** Payroll, renewal and edit each have fewer sections. The index must then vanish and the dialog fall back to 760px. Test in Task 3: a 4-section modal renders no index and no `modal-shell-indexed`.
2. **Livewire re-renders while the form is open.** Example: a `wire:model.live` donor/program change shows or hides a section. The index must update its entries, it must not jump the scroll, and the current entry must not reset. Browser check in Task 6, using proposal → change donor.
3. **Arabic / RTL.** The index must sit on the right, chips scroll from the right, and the error footer reads right-to-left. Browser check in Task 6 on `/ar`.
4. **Enter inside a Flux select/combobox search or multiselect popover** in an indexed form must still pick an option. Only plain single-line inputs are held back. Task 3 limits the Enter rule to `input` elements whose `type` is text/number/date/email/tel/url/search and that are not inside `[role="combobox"]`, `[role="listbox"]` or `[data-flux-select]`. Browser check in Task 6.
5. **An error arrives in a section scrolled out of view, or one that was just revealed.** The footer count, the index badge and `revealFirstError` must all point to it. Browser check in Task 6: save an empty proposal, then click "show me".

---

### Task 1: Remove the wizard from the modal shell; add the error footer

**Files:**
- Modify: `resources/views/components/ui/modal.blade.php`
  - remove the stepper `<ol data-modal-steps>`
  - remove the Back/Next buttons
  - remove `x-bind:data-wizard` and `x-bind:data-step`
  - the submit `<fieldset>` loses its `x-show`
  - add the error button
  - rewrite the header comment
- Modify: `resources/js/modal-shell.js`
  - drop the `wizard`, `step`, `total`, `labels` and `invalid` state, and the `go`/`next`/`back`/`stepIsValid`/`currentSection` methods
  - drop the wizard branches in `onEnter`, `revealFirstError` and `close`
  - add the error count
  - rewrite the header comment
- Modify: `resources/css/desk-shell.css`
  - delete lines 395–443 (the step-wizard block, including the `.report-form-modal` step rules and the `data-step` rules)
  - delete the `:has(.modal-shell-frame[data-wizard])` height rules at lines 463–466
  - remove `:not(:has(.modal-shell-frame[data-wizard]))` from line 461
  - rewrite the "a wizard also shares one height" comment
- Modify: `lang/en/common.php`, `lang/ar/common.php`
  - add `show_me` ("show me" / "اعرضها")
  - delete `steps`: its only user is the stepper
- Test: `tests/Feature/Desk/ModalWizardTest.php` (rewrite the content and keep the filename; renaming needs the user's OK)

**Interfaces:**
- Produces: the Alpine component `modalShell(titleId, submitAction)` keeps its signature. It gains:
  - `errorCount: number`
  - `readErrors(): void`, which counts `[aria-invalid="true"]` inside `.modal-shell-body`
  - `revealFirstError(): void`, which keeps its name
- Produces: the footer button `<button type="button" class="modal-shell-error" data-modal-errors x-show="errorCount > 0" x-cloak x-on:click="revealFirstError()">`. Its text is `trans_choice('common.fields_need_attention', …)` + " — " + `__('common.show_me')`. Alpine renders it via `x-text`, and the two strings are passed in as `@js` data.

- [ ] **Step 1: Rewrite the test file**

```php
test('an x-ui.modal with many sections is never a step wizard', function () {
    $sections = collect(range(1, 6))->map(fn ($i) => "<x-ui.form-section title=\"S{$i}\">f</x-ui.form-section>")->implode('');
    $html = Blade::render('<x-ui.modal wire:model="open" title="T" submit="save">'.$sections.'<x-slot:actions><button data-save>Save</button></x-slot:actions></x-ui.modal>');

    expect($html)->toContain('modal-shell-centered')
        ->not->toContain('data-modal-steps')
        ->not->toContain('data-modal-next')
        ->not->toContain('data-modal-back')
        ->not->toContain('data-wizard')
        ->toContain('data-save')
        ->toContain('data-modal-errors');
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `.\php.cmd artisan test --compact tests/Feature/Desk/ModalWizardTest.php`
Expected: FAIL, because `data-modal-steps` is present.

- [ ] **Step 3: Make the shell, JS, CSS and lang edits listed under Files**

In `modal-shell.js`:
- `readErrors()` runs from the existing `MutationObserver`. Widen its `attributeFilter` to `['data-invalid', 'aria-invalid']`.
- `onEnter` becomes a no-op placeholder that Task 3 fills in. Keep the `x-on:keydown.enter` binding.

Style `.modal-shell-error` in `forms.css`, next to `.modal-shell-meta`:
- red text (`--color-status-critical`, and `brand-text` in dark)
- 600 weight, 13px
- no border; hover tint `--color-status-critical-soft`
- `inline-flex`, gap 0.5rem
- focus-visible outline matching `.modal-shell-close`

When errors show, hide the "* Required" meta: `x-show="errorCount === 0"` on the meta div.

- [ ] **Step 4: Run the shell tests**

Run: `.\php.cmd artisan test --compact tests/Feature/Desk/ModalWizardTest.php tests/Feature/ModalSheetTest.php tests/Feature/UiComponentsTest.php`
Expected: PASS.

- [ ] **Step 5: Format and leave uncommitted**

Run `vendor/bin/pint --dirty --format agent`. Do not commit.

---

### Task 2: Form sections always show their heading on top; add `optional` and `data-invalid-count`

**Files:**
- Modify: `resources/views/components/ui/form-section.blade.php`
  - new prop `'optional' => false`
  - root gets `data-invalid-count="{{ $invalidCount }}"`, always rendered, `0` when clean
  - when `optional`, the heading appends `<span class="form-section-optional">{{ __('common.optional') }}</span>` and the root gets `data-optional`
  - rewrite the comment: the heading is always above the fields; the index replaces the rail
- Modify: `resources/css/forms.css`
  - delete the `@container modal (width >= 56rem)` block (lines ~464–509) so the side rail never applies
  - add `.form-section + .form-section { border-top: 1px solid <same colour as the deleted rule> ; padding-top: 1.25rem; }` at all widths, with its `.dark` twin using `--color-line`
  - add `.form-section-optional`: 11px, 600 weight, `--color-secondary-medium` text, `--color-secondary-soft` pill background, radius 9999px, padding 1px 8px. Dark: `ink-muted` on `surface-2`.
  - keep `.form-section-hint` but drop its `margin-inline-start: 17px` only if the dot alignment breaks; verify visually in Task 6
- Modify: `lang/en/common.php` / `lang/ar/common.php`: add `optional` ("Optional" / "اختياري") if it is not already there (grep first)
- Test: `tests/Feature/UiComponentsTest.php` (add to the form-section tests near line 634)

**Interfaces:**
- Produces: `<x-ui.form-section title="…" :optional="true" :fields="[…]">`. The root `section.form-section` carries `data-invalid-count` and an optional `data-optional`. Task 3 reads both.

- [ ] **Step 1: Write the failing tests**

```php
test('a form section marked optional says so in its heading', function () {
    $xpath = formXPath(Blade::render('<x-ui.form-section title="Notes" :optional="true"><p>f</p></x-ui.form-section>'));

    expect($xpath->query('//section[@data-optional]')->length)->toBe(1)
        ->and(trim($xpath->query('//*[contains(@class, "form-section-optional")]')->item(0)->textContent))->toBe('Optional');
});

test('a form section reports how many of its fields failed', function () {
    // same error-bag setup as the existing "2 fields need attention" test at line ~649
    expect($xpath->query('//section[@data-invalid-count="2"]')->length)->toBe(1);
});

test('a clean form section reports zero failed fields', function () {
    $xpath = formXPath(Blade::render('<x-ui.form-section title="D"><p>f</p></x-ui.form-section>'));
    expect($xpath->query('//section[@data-invalid-count="0"]')->length)->toBe(1);
});
```

- [ ] **Step 2: Run them and confirm they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/UiComponentsTest.php`
Expected: the 3 new tests FAIL.

- [ ] **Step 3: Implement the component, CSS and lang changes above**

- [ ] **Step 4: Run the tests**

Run: `.\php.cmd artisan test --compact tests/Feature/UiComponentsTest.php tests/Feature/ProjectDirectoryTest.php`
Expected: PASS. ProjectDirectoryTest:735 reads `form-section-heading`, which still exists.

- [ ] **Step 5: Format and leave uncommitted**

---

### Task 3: The section index (5+ sections)

**Files:**
- Modify: `resources/views/components/ui/modal.blade.php`
  - in `@php`, count the top-level sections in `$slot->toHtml()` with `preg_match_all('/<section\b[^>]*\bclass="[^"]*\bform-section\b/', …)`
  - set `$indexed = $count >= 5`
  - when indexed, add the class `modal-shell-indexed` to `$modalAttributes`
  - wrap the body in `<div class="modal-shell-main">`
  - render the index nav before the body and the chips nav above `.modal-shell-main`, both only `@if ($indexed)`
  - pass `$indexed` as the 3rd argument of `modalShell(...)`
- Modify: `resources/js/modal-shell.js`
- Modify: `resources/css/forms.css` (index, chips, main row) and `resources/css/desk-shell.css` (the `modal-shell-indexed` width rule inside the existing `@media (width >= 40rem)` block)
- Modify: `resources/css/tokens.css` or the `desk-shell.css` root block next to `--pop-w` (line 20): add `--pop-w-indexed: 960px;`
- Modify: lang `common.php` en/ar:
  - `sections_in_this_form`: "On this form" / "في هذا النموذج"
  - `required_filled`: ":filled of :total required filled" / "تم ملء :filled من :total من الحقول المطلوبة"
- Test: `tests/Feature/Desk/ModalWizardTest.php`

**Interfaces:**
- Consumes: from Task 2, `section.form-section[data-invalid-count][data-optional]` and `.form-section-heading`. From Task 1, `errorCount` and `revealFirstError()`.
- Produces: `modalShell(titleId, submitAction = null, indexed = false)`, with:
  - state `entries: Array<{label: string, optional: boolean, errors: number, done: boolean}>`
  - `current: number` (0-based)
  - `filled: number`, `required: number`
  - methods `readSections(): void`, `jump(index: number): void`, `onEnter(event): void`
- Produces markup:
  - `<nav class="modal-shell-index" data-modal-index aria-label="{{ __('common.sections_in_this_form') }}">`, holding a `<template x-for>` of `<a href="#" x-on:click.prevent="jump(i)" :aria-current="i === current ? 'true' : null" :class="{ 'is-current', 'is-done', 'is-invalid' }">` with a dot, the label, an optional tag and an error badge (`sr-only` text from `fields_need_attention`), plus the progress text and bar
  - `<nav class="modal-shell-chips" data-modal-chips>` with the same entries as chips

- [ ] **Step 1: Write the failing tests**

```php
function modalWithSections(int $count): string
{
    $sections = collect(range(1, $count))->map(fn ($i) => "<x-ui.form-section title=\"S{$i}\">f</x-ui.form-section>")->implode('');

    return Blade::render('<x-ui.modal wire:model="open" title="T" submit="save">'.$sections.'</x-ui.modal>');
}

test('a modal with five sections gets the section index and the wide size', function () {
    expect(modalWithSections(5))->toContain('data-modal-index')->toContain('data-modal-chips')->toContain('modal-shell-indexed');
});

test('a modal with four sections has no section index', function () {
    expect(modalWithSections(4))->not->toContain('data-modal-index')->not->toContain('modal-shell-indexed');
});

test('a closed modal with an empty body has no section index', function () {
    expect(Blade::render('<x-ui.modal wire:model="open" title="T"></x-ui.modal>'))->not->toContain('data-modal-index');
});
```

- [ ] **Step 2: Run them and confirm they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/Desk/ModalWizardTest.php`
Expected: the 2 positive tests FAIL.

- [ ] **Step 3: Implement the Blade counting and markup**

- [ ] **Step 4: Implement the JS**

- `readSections()`:
  - runs on init and from the existing `MutationObserver`; widen its `attributeFilter` with `data-invalid-count`, and listen for `input`/`change` on the body to refresh `done`/`filled`
  - builds `entries` from top-level `section.form-section`
  - `done` means every enabled, visible `[required], [aria-required="true"]` control in the section has a non-empty value (for a checkbox: `checked`)
  - only reassigns arrays when a joined key changes, as today
- `current`: one `IntersectionObserver` on the sections with `root = .modal-shell-body` and `rootMargin: '0px 0px -60% 0px'`; the top-most intersecting section wins, and the last one wins when the body is scrolled to its end. Re-observe when the section list changes.
- `jump(i)`:
  - `body.scrollTo({ top: section.offsetTop - body.offsetTop - 4, behavior })`
  - then focus the section's first enabled field with `preventScroll`
  - also `scrollIntoView({ inline: 'nearest' })` the matching chip
- `onEnter(event)`:
  - when `indexed`, and the target is an eligible plain input (Review Focus 4), and no Ctrl/Meta key is held: `preventDefault()`
  - when Ctrl/Meta is held on any field: `preventDefault()`, then `this.$root.requestSubmit()` if the frame is a `<form>`
  - when not indexed: do nothing (native submit)
- Skip `IntersectionObserver` setup when `indexed` is false.

- [ ] **Step 5: Implement the CSS**

Follow the mockup.
- `.modal-shell-main`: `display: flex`; `min-height: 0`; `flex: 1`
- `.modal-shell-index`:
  - `flex: 0 0 200px`
  - `border-inline-end: 1px solid`
  - `--color-board-head` background, own `overflow-y: auto`
  - padding `18px 10px 18px 16px`
- the thread line, then the entries:
  - current: white chip + shadow
  - done dot: `--color-status-good` with a ✓
  - invalid: `--color-status-critical`, with a count pill
- `.modal-shell-chips`: hidden by default, `display: flex` with horizontal scroll and hidden scrollbar
- container/viewport rule: below `48rem` dialog width, use `@media (width < 48rem)`, since the dialog is the viewport there: hide the index, show the chips
- dark twins with Graphite tokens
- in `desk-shell.css`: `[data-flux-modal] > dialog.modal-shell-indexed:not([data-flux-flyout]):not([data-flux-modal-overflow]) { width: min(var(--pop-w-indexed), calc(100vw - 32px)); }`

- [ ] **Step 6: Run the tests**

Run: `.\php.cmd artisan test --compact tests/Feature/Desk/ModalWizardTest.php tests/Feature/UiComponentsTest.php tests/Feature/ModalSheetTest.php`
Expected: PASS.

- [ ] **Step 7: Format and leave uncommitted**

---

### Task 4: Per-form pass — the two indexed forms

**Rule for every form in Tasks 4–5:** a section that contains no `required` control gets `:optional="true"`. No fields are added, removed or renamed. No folds: the one fold the spec named, proposal "Starting point", holds a required control on create, so it stays an ordinary section.

**Files:**
- Modify: `resources/views/components/proposals/form-modal.blade.php`
  - `:optional="true"` on the Concept note section (line ~318) and the Notes section (line ~388)
  - Locations (line ~241): optional only if it holds no `required` control; read it first
- Modify: `resources/views/pages/indicator-library/_index-entry-modal.blade.php`
  - move the **Name** section (it holds `autofocus` on `entry.name_ar`) above **Placement**, so the first field the user sees is the one that gets focus
  - `:optional="true"` on **Definition**
- Test: `tests/Feature/ProposalFormTest.php`, `tests/Feature/IndicatorLibraryTest.php` (add one test each)

- [ ] **Step 1: Write the failing tests**
  - ProposalFormTest: open the create modal as a role that sees money, then assert `assertSeeHtml('data-modal-index')` and that the HTML holds 2 `data-optional` sections.
  - IndicatorLibraryTest: open the entry modal, then assert the Name heading appears before the Placement heading: `strpos` of the two `__()` titles in the rendered HTML.

- [ ] **Step 2: Run them and confirm they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/ProposalFormTest.php tests/Feature/IndicatorLibraryTest.php`

- [ ] **Step 3: Make the edits**

- [ ] **Step 4: Run them, plus the related proposal tests**

Run: `.\php.cmd artisan test --compact tests/Feature/ProposalFormTest.php tests/Feature/IndicatorLibraryTest.php tests/Feature/ProposalRenewalPagesTest.php tests/Feature/ProposalPipelineTest.php`
Expected: PASS.

- [ ] **Step 5: Leave uncommitted**

---

### Task 5: Per-form pass — the ten stacked forms

Apply the Task 4 rule. These sections are known to have no required control and get `:optional="true"`:

| File | Sections |
|---|---|
| `pages/milestones/⚡show.blade.php` (related modal) | Placement |
| `pages/admin/_accounts-modal.blade.php` | Address |
| `components/reports/form-modal.blade.php` | Narrative, Summary |
| `components/donors/form-modal.blade.php` | Contact, Follow-up |
| `pages/projects/_index-project-modal.blade.php` | Beneficiaries (line ~246), if it holds no required control |

Read these four and mark only sections with no required control:
- `funding/transfer-modal.blade.php`: every section has a required field, so expect no change
- `funding/agreement-modal.blade.php`
- `milestones/_index-milestone-modal.blade.php`, `calendar/_index-milestone-modal.blade.php`
- `proposals/_plan-modals.blade.php`

Also remove the `.report-form-modal` class from `components/reports/form-modal.blade.php` if, after Task 1, no CSS rule references it any more (`grep -rn report-form-modal resources`).

**Test:** `tests/Feature/DonorDirectoryTest.php`. Add one test: the donor modal renders 2 `data-optional` sections and no `data-modal-index`.

- [ ] **Step 1: Write the failing test**
- [ ] **Step 2: Run it and confirm it fails**
- [ ] **Step 3: Make the edits**
- [ ] **Step 4: Run the affected page tests**

Run:

```
.\php.cmd artisan test --compact tests/Feature/DonorDirectoryTest.php tests/Feature/MilestoneShowTest.php tests/Feature/MilestoneDirectoryTest.php tests/Feature/CalendarTest.php tests/Feature/ReportDirectoryTest.php tests/Feature/ReportDetailTest.php tests/Feature/FundingAgreementTest.php tests/Feature/TransferTrackingTest.php tests/Feature/FundingWorkflowTest.php tests/Feature/ProposalPlanTest.php tests/Feature/ProjectDirectoryTest.php tests/Feature/RiskLibraryTest.php tests/Feature/TaskDirectoryTest.php
```

Expected: PASS.

- [ ] **Step 5: Leave uncommitted**

---

### Task 6: Rule record, browser verification, hand-off

**Files:**
- `.ai/rules` via the Boost `record-rule` tool:
  - glob: `resources/views/components/ui/modal.blade.php, resources/js/modal-shell.js, resources/views/components/ui/form-section.blade.php`
  - title: "x-ui.modal never steps"
  - note: "No step wizard in any modal (user 2026-11-16, reverses desk-shell v2 decision 6). 5+ top-level form sections → server adds modal-shell-indexed (960px) and the section index; fewer → stacked sections at 760px. Sections without a required control take :optional. Enter does not submit indexed forms; Ctrl/⌘+Enter does."
- Modify: `docs/superpowers/specs/2026-10-27-desk-shell-v2-design.md` decision 6: add one line, "Superseded 2026-11-16 by 2026-11-16-sectioned-modal-design.md."

Steps:

- [ ] **Step 1: Record the rule and add the spec note**

- [ ] **Step 2: Check that the user's dev server is running**

Use Boost `get-absolute-url` for the URL. If assets look stale, ask the user to restart `composer run dev`. Never start one.

- [ ] **Step 3: Browser check in the built-in browser, desktop width, English, light**

1. Open the proposal create modal. Check:
   - the index is visible
   - scrolling moves the current entry
   - clicking "Budget" jumps there and focuses its field
2. Save with the form empty. Check:
   - each section shows its red count
   - the footer says "N fields need attention — show me" and jumps to the first error
3. Change the donor (a live field). Check that the index survives without a scroll jump (Review Focus 2).
4. Press Enter in the proposal name: nothing is saved. Press Enter inside the donor select search: an option is picked. Press Ctrl+Enter: the form is submitted (Review Focus 4).
5. Type something, then press Esc: the discard prompt appears.

- [ ] **Step 4: Repeat the key checks**

1. Dark mode (`resize_window colorScheme: dark`).
2. Arabic (`/ar`): the index is on the right (Review Focus 3).
3. Mobile preset: the chips row is visible, the Save button is full width, and nothing scrolls sideways.

- [ ] **Step 5: Check the remaining forms**

1. Donor modal: no index, 760px wide, optional tags on Contact and Follow-up.
2. Transfer modal: 4 stacked sections.
3. Screenshot each form for the hand-off.

- [ ] **Step 6: Re-run every test file named in Tasks 1–5 once together**

- [ ] **Step 7: Hand off**

List the files changed, the per-form `optional` marks, and any spec deviation: "no folds". Ask the user to review the changes and then run the full suite themselves with `.\php.cmd artisan test --compact`. Do not commit.
