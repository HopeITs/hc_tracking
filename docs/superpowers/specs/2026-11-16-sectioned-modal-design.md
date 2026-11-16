# Sectioned modal (replaces the step wizard) — design

User request 2026-11-16 (branch `real-workflow-bugs`). "Wizard UX is not good at all in all types of forms,
either long or short." Every modal form that is a step wizard today becomes one centered dialog that shows all
its sections at once, and the UX must be as good as we can make it. A clickable mockup was approved
("Yes, write design"):
`C:\Users\youss\AppData\Local\Temp\claude\88c34cb1f755d2bb\733a4e73-a42c-439f-8cac-473a6f219ec1\scratchpad\sectioned-modal-mockup.html`.

This reverses decision 6 of `2026-10-27-desk-shell-v2-design.md` ("Long forms become step wizards"). The
"popups, never side drawers" part of that decision still holds.

## Why

- A wizard hides most of the form. People can't see what is coming, can't check what they typed earlier
  without going Back, and can't save early even when the required fields are already filled.
- Every step costs a click. A 3-section donor form takes three screens to save a name and a type.
- An error found on save throws the user onto another step, and they lose their place.

Success: every create/edit form opens with every field visible. Save is always in the same place. After a
failed save the user goes straight to each problem. No form feels longer than it is.

## Goals

1. Remove the wizard from the shared modal shell, so no `x-ui.modal` can ever become a wizard again.
2. Long forms get a **section index** that follows the scroll, jumps on click, and shows progress and errors.
3. Better errors everywhere: a footer summary with a count that jumps to the first problem, plus per-section
   error counts.
4. A per-form pass over all 12 former wizard forms: grouping, field pairs, order, optional parts marked or
   folded.
5. Works in English and Arabic (RTL), light and dark (Graphite), on a phone and on a 4K screen.

## Non-goals

- No change to validation rules, Livewire actions, permissions, routes or data.
- Pages that are step flows but not modals keep their steps. That means the annual plan monthly update
  (`annual-plans/_update-form.blade.php`) and the annual plan creation wizard (settled, see memory). **My call**
  (open question 4 was not answered), so confirm.
- Confirmation dialogs and small one-section forms only take on the shell's new error footer and nothing else.

## Anti-goals (a failure even if it "works")

- A long form that is one endless wall of fields with no sense of where you are.
- Enter in a text field saving a half-filled proposal by accident.
- Losing typed data on ✕ / Esc / backdrop.
- Light mode changing anywhere outside these dialogs.
- Any form slower to open or type in than today. Thunder speed rule: no extra Livewire round trips, and the
  index is pure Alpine.

## Approaches considered

| | Approach | Trade-off |
|---|---|---|
| **A (picked)** | Central change in `x-ui.modal` / `modal-shell.js` / CSS, plus a section index for long forms, plus a per-form polish pass | One place to fix and every form benefits. The per-form pass is still needed for quality. |
| B | Rebuild each of the 12 forms by hand with its own layout | Most control, but it drifts apart over time and is the most work. |
| C | Two-pane forms with a fixed left menu that shows only one section at a time | This is really a wizard again, because it still hides fields. |

The user picked A by approving the mockup.

## The design

### 1. Dialog frame (all `x-ui.modal`)

- Centered popup, same as today: header and footer pinned, only the body scrolls. Full-height sheet below
  `40rem`, as today.
- **No fixed height.** The dialog grows with its content up to `100dvh - 48px`, then the body scrolls. Remove
  `--pop-h` for form dialogs and the `report-form-modal` wizard height.
- **Width:**
  - Forms **with** the index: `min(960px, 100vw - 32px)`, a new `--pop-w-indexed` token.
  - All other form dialogs keep `--pop-w` (760px).
  - `sm` confirmations keep 440px.

  **My call** (open question 3), so confirm.
- Remove the stepper `<ol>`, Back / Next, `data-wizard`, `data-step` and every `.modal-shell-body[data-step=…]`
  rule. The submit `<fieldset>` is always visible.

### 2. Sections

- Every `x-ui.form-section` shows its heading **above** its fields: dot + title + optional "Optional" tag + a
  one-line lead (the existing `hint`). The heading stays visible however wide the dialog gets.
- The old side "rail" variant (≥56rem container) is dropped. The index takes over that job on long forms.
- New prop `optional` (bool). It shows the "Optional" tag in the heading and in the index.
- New prop/slot use: rarely used parts of a section can sit in a `<details>` fold. Use it per form only where
  the field is rare (e.g. proposal "Starting point").
- Sections are separated by a hairline and spacing, not cards, so they don't look boxy inside a dialog.
- `form-section` exposes `data-invalid-count="N"` so the index can show counts without a server change.

### 3. Section index (long forms only)

- Shown when the body holds **5 or more** top-level sections (`IndexMinimumSections = 5`). Today that is the
  proposal form (9) and the indicator library entry (5). Forms with 3–4 sections show the sections stacked,
  without an index. **My call** (open question 2), so confirm.
- Placement: a column on the inline-start side of the body, `200px`, `surface` tint, its own scroll.
  Logical properties only, so it mirrors in Arabic.
- One entry per section: dot + name (+ "optional").
  - **Current section:** bold, white chip. It follows the scroll through an `IntersectionObserver`, and the
    last section wins at the bottom.
  - **Done:** green tick dot once every `[required]` control in that section has a value.
  - **Error:** red dot and a red count badge (from `data-invalid-count`) after a failed save.
- Clicking an entry scrolls the body to that section (smooth, or instant under `prefers-reduced-motion`) and
  focuses its first enabled field.
- Under the list: "N of M required filled" with a thin progress bar. It counts `[required]` /
  `[aria-required="true"]` controls in the body, client-side only.
- Under `40rem`, or when the dialog is too narrow for the column (container query), the index becomes a
  sticky row of chips under the header that scrolls sideways. The current chip is filled and error chips are
  red.
- Accessibility: `<nav aria-label="{{ __('common.sections_in_this_form') }}">`, entries are links with
  `aria-current="true"` on the current one, and the counts have an `sr-only` text such as "2 fields need
  attention".

### 4. Saving and errors

- Save validates the whole form on the server, as today. It never runs a client-side step check.
- When errors come back:
  1. The first invalid field is scrolled to the center and focused. This is the existing `revealFirstError`
     minus the step switch.
  2. The footer shows a red button: "⚠ N fields need attention — show me". Clicking it runs
     `revealFirstError` again. The count comes from `[aria-invalid="true"]` in the body and is updated
     after each Livewire update.
  3. The index shows the counts per section.
- **Enter key**:
  - In a single-line field of a form **with the index**, Enter does not submit. Ctrl/⌘+Enter saves.
  - Forms without the index keep native Enter-to-save.
  - Textareas are never affected.

  **My call**, because it blocks accidental saves of long create forms.
- Uploads in flight still disable the submit actions (`busy`), unchanged.
- The discard guard is unchanged (✕ / Cancel / Esc / backdrop ask once when dirty).

### 5. Per-form pass (all 12 former wizards)

| Form | File | Sections |
|---|---|---|
| Proposal create/edit | `components/proposals/form-modal.blade.php` | 9 → index |
| Indicator library entry | `pages/indicator-library/_index-entry-modal.blade.php` | 5 → index |
| Transfer / tranche | `components/funding/transfer-modal.blade.php` | 4 |
| Project | `pages/projects/_index-project-modal.blade.php` | 3 |
| Milestone (index) | `pages/milestones/_index-milestone-modal.blade.php` | 3 |
| Milestone related (show) | `pages/milestones/⚡show.blade.php` (`showRelatedModal`) | 3 |
| Milestone (calendar) | `pages/calendar/_index-milestone-modal.blade.php` | 3 |
| Plan milestone / final report / plan task | `pages/proposals/_plan-modals.blade.php` | 3 / 2 / 3 |
| Account | `pages/admin/_accounts-modal.blade.php` | 3 |
| Report | `components/reports/form-modal.blade.php` | 3 |
| Agreement | `components/funding/agreement-modal.blade.php` | 3 |
| Donor | `components/donors/form-modal.blade.php` | 3 |

For each form, check and fix only what applies:

- Section order follows the user's real job (what they know first), and the most important field comes first
  and has autofocus.
- Related short fields sit in pairs (two columns from the modal container's `@xl` width, one column on a
  phone). Long text and pickers take the full row.
- Truly optional sections get `optional`. Rare fields go in a fold.
- Headings and leads are short and plain, in both `lang/en` and `lang/ar`.
- Nothing about which fields exist, their names or their `wire:model` changes. The `data-test` hooks stay.

The 2-section forms (targets, risks, results, indicators) and non-sectioned forms pick up the new section
heading style and error footer automatically and get no per-form rework.

### 6. Visuals

- The mockup is the reference for spacing, chips, dots, the error button and the phone layout.
- Colours use the existing tokens only. Red appears only on errors, the required `*` and the primary Save.
  Green (`status-ok` token) appears only for the "done" tick.
- Dark mode uses Graphite tokens. The dialog is `surface-3` and the index is `surface-2`, following
  `.ai/rules/css.md`. Light mode must not change outside the dialog.

## Constraints (from project rules)

- `.ai/rules/views.md`: modal bodies stay behind their `@if ($flag)`. Shared modals take `:open`.
- `.ai/rules/pages.md`: modals bind with `wire:model` booleans. Records are pages, and modals are only for
  small create/edit forms and confirmations.
- `.ai/rules/css.md`: Graphite tokens behind `dark:`, `--spacing-ui-*` prefix, and Flux overrides in
  `@layer utilities`.
- `.ai/rules/donors.md`, `funding.md` and `reports.md`: shared form components are never re-inlined on
  pages.
- Comments in `modal.blade.php`, `modal-shell.js`, `desk-shell.css` and `form-section.blade.php` that
  describe the wizard must be rewritten.
- Record the decision with `record-rule` (glob `resources/views/components/ui/modal.blade.php,
  resources/js/modal-shell.js`): "x-ui.modal never steps; 5+ sections get the index."
- No commits without the user's say-so. The user runs `npm run build` / `composer run dev` if styles don't
  show. Never start a second dev server.

## Tests

- Rewrite `tests/Feature/Desk/ModalWizardTest.php` (rename to `ModalShellTest.php` only with the user's OK,
  since tests are not deleted without approval). Assert:
  - no `data-modal-steps` / `data-modal-next` / `data-modal-back`
  - the submit actions render
  - a 5-section modal renders the index nav and a 3-section modal does not
  - `optional` renders the tag
  - `form-section` renders `data-invalid-count`
- Keep `TaskDirectoryTest` "one screen, not a step wizard" as is.
- Run the existing feature tests of each of the 12 forms' pages, affected files only (never the full suite),
  to prove the `data-test` hooks and saves still work.
- Browser check on the user's running dev server: proposal (index, errors, phone chips, Arabic RTL, dark),
  donor (no index), and one 4-section form (transfer).

## Left to the builder

- The exact Alpine wiring (one `IntersectionObserver` and one `MutationObserver`, as today).
- How the "done" check reads Flux selects and multiselects: whatever control carries the value.
- Which fields go in folds, judged per form under the rules above. List them in the hand-off so the user
  can review.
- New lang keys, en + ar: `common.sections_in_this_form`, `common.optional`, `common.required_filled`
  (":filled of :total required filled"), `common.show_me`. Reuse `common.fields_need_attention`. Remove
  `common.steps` if nothing else uses it.

## Calls made without an answer (please check)

1. The index appears only on forms with 5+ sections (open question 2).
2. Indexed forms are 960px wide (open question 3).
3. The annual plan page step flows stay as they are (open question 4).
4. Enter doesn't save on indexed forms; Ctrl/⌘+Enter does.
5. The side "rail" section layout is dropped in favour of headings above fields everywhere.
