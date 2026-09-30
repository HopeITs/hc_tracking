# Modal redesign — design spec

Date: 2026-09-24 · Branch: `youssef-interfaces-edits` · Changes stay uncommitted for review.

## Goal

Every create/edit modal (and every small dialog) in hc-tracking looks and behaves the same, looks pixel perfect in light/dark and English/Arabic, never overflows or overlaps, and uses dropdowns that are identical to the header filter picker.

## Scope

In scope:
- ~36 create/edit form modals across 25 files, plus the tasks drawer (`tasks/⚡index.blade.php`).
- All small dialogs: delete confirmations, archive, unlink, file upload, status change.
- File-preview viewers (proposals/reports/tasks show) and the indicator inspect viewer: shared header + close only; their content layout is unchanged.

Out of scope:
- Settings dialogs (`settings/⚡two-factor-setup-modal`, `settings/⚡delete-user-modal`, `settings/⚡security`).
- Inline composers and list-row pickers outside modals (tasks list rows, board, calendar day cells) — they keep `x-ui.multi-select-popover`.
- Any backend change beyond what a form strictly needs; Livewire property names, actions, validation rules and `data-test` hooks are preserved.

## Decisions (made with the user, mockups screens 1–3)

1. **Long forms (≥ 2 sections): section rail layout (B).** Wide dialog (64rem). Section names sit in a left rail joined by a thin thread with a dot per section (the app's dot-thread motif). Rail hints can be live (e.g. "92 days", "1,150 of 1,500 broken down"). A section's dot and hint turn primary red when any of its fields has a validation error ("N fields need attention"). Verified in mockup: the Project form fits a 1366×768 laptop viewport (648px) with no scroll, error message included.
2. **Short forms (2–5 fields):** no rail, one column (short fields two per row), narrow dialog.
3. **Fields with 2–4 fixed options: one-click segmented control (A).** Dropdowns for 5+ options or long lists.
4. **Confirmations: same shell as forms (A).** Small red warning mark beside the title, consequence text, footer with Cancel + red action button. Wording unchanged.
5. **All dropdowns = the header filter picker**: 44px tinted trigger, chevron-up-down, same menu, same check mark, search box when > 7 options.
6. **No scroll strategy:** header and footer pinned; body scrolls only when the viewport is genuinely too short (phones, very short windows).
7. **Phone:** dialog becomes a full-height sheet; rail folds to stacked dot + hairline headings; 44px tap targets; footer buttons two-up full width.
8. **Tasks drawer stays a drawer**, restyled internally with the same header/footer/fields/segmented controls.

## Components (resources/views/components/ui/)

| Component | Purpose |
| --- | --- |
| `x-ui.modal` | Shell around `flux:modal` (keeps native `<dialog>`, focus trap, Esc, backdrop, `wire:model` boolean binding). Props: `size` (`sm` confirm 28rem · `md` short 36rem · `lg` 48rem · `xl` rail 64rem), `title`, `description`, `submit` (wraps body in `<form wire:submit>`), `close` (action for X/Cancel/Esc/outside click). Slots: body, `actions` (footer right), `footerMeta` (footer left, e.g. "* Required"). Flex column, `max-height: calc(100dvh - 2rem)`, pinned header/footer, scrolling body. Own close button in the header (Flux's absolute one disabled). Full-height sheet below `sm`. |
| `x-ui.confirm-modal` | Preset of `x-ui.modal size="sm"`: warning mark, title, message, Cancel + action. `tone`: `danger` (red button) / `neutral` (archive, unlink). |
| `x-ui.form-section` | Rail section: `title`, `hint` (static or Alpine `x-text` for live values), `fields` (array of error keys → red dot + attention hint). Container query folds it to a stacked heading below ~900px dialog width. |
| `x-ui.form-input` | 44px filter-look input: text/number/email/tel/date; `prefix`/`suffix` (USD, %); `required` → red asterisk; `help`; error message resolved from the `wire:model` name. |
| `x-ui.form-textarea` | Same box, no resize handle, 2–3 rows. |
| `x-ui.form-select` | Built on `flux:dropdown` + `flux:menu` + `flux:menu.item` and the existing `.filter-picker` / `.filter-menu` CSS, so it is identical to the header filter by construction. Options as `<option>` children (same convention as `x-ui.header-filter-select`, so a `flux:select` migrates mechanically). Search box when > 7 options. `placeholder` for the empty state; an `<option value="">` is a real choice (e.g. "Top level"). `disabled` + `lockedReason`. Deferred by default via `$wire.$set(prop, value, false)` with the trigger label and check mark updated client-side; `wire:model.live` makes it live for dependent fields. Values are sent as strings, same as the native select they replace. |
| `x-ui.form-multiselect` | Same dropdown with `<flux:menu keep-open>`; red check on selected rows (like `x-ui.header-filter-multiselect`); trigger reads names for 1–2, "N selected" for 3+. |
| `x-ui.form-segmented` | 2–4 options, one click, `role="radiogroup"`/`aria-checked`, optional dot/icon per option, same 44px box. |
| `x-ui.form-file` | Drop-or-browse zone → chosen file row with live upload progress (Livewire upload events) and remove; Save disabled while uploading. Lists allowed types and max size. |

Shared Add-task modal: the four identical 91-line add-task modals (milestones/programs/projects/proposals show) become one shared Blade component.

## Styling

- Form dropdowns reuse the existing `.filter-picker` / `.filter-menu` rules in `resources/css/app.css` — no second copy. Menu width is at least the trigger width in forms.
- One new CSS block for form controls, segmented control, file zone, rail and the modal shell, using only approved tokens (primary, secondary-*, board-*). Dark mode and RTL covered there. No raw hex outside app.css.
- The existing global `[data-flux-modal]` backdrop/shadow/enter-animation rules stay; the shell's own padding replaces Flux's `p-6`.

## Behaviour guarantees

- Esc, click-outside, the X and Cancel all run the same close action (today some modals reset state on close and others don't).
- First field autofocused on open.
- Failed submit: scroll to and focus the first invalid field; section dot turns red.
- Save shows a loading state and is disabled during submit/upload (no double submit).
- Menus live in the top layer: never clipped by the dialog; flip upward near the viewport bottom; keyboard: ↑/↓, Enter, type-to-search, Esc returns focus to the trigger.
- All `data-test` hooks and Livewire bindings preserved.

## Rollout (each phase verified before the next)

0. Foundation: components, CSS, component tests. **Pilot: Add/Edit project** end to end, verified in the real app, screenshots to the user (light/dark, EN/AR, 1366×768, phone). **User approves the pilot before mass rollout.**
1. Long rail forms: project, milestone (milestones index + calendar), calendar task, funding agreement, transfer, target, indicator, result, risk, annual plan (index), report, proposal (index).
2. Short forms: donor, strategy, program, annual plan (show), admin account, admin create user, admin assigned users, proposal details, proposal status (show + dossier), proposal stage, agreement report, indicator progress, add subtask, file upload ×4, shared add-task modal ×4.
3. Confirmations (~20 delete + archive + unlink) → `x-ui.confirm-modal`.
4. Tasks drawer internals; shared header for preview/inspect viewers.
5. Full sweep: every dialog in light/dark, EN/AR, 1366×768 and phone.

## Testing & verification

- New Pest tests in `tests/Feature/UiComponentsTest.php` for each component: label + required asterisk, error state, select trigger shows the resolved label and a check on the selected option, search only when > 7 options, segmented `aria-checked`, confirm tones.
- Existing page feature tests (ProjectDirectoryTest, RiskRegisterTest, …) must stay green; run the affected ones after each phase. User runs the full suite at the end.
- Blade compile check (`bisect_blade.php`) on every changed view; never the `@php(...)` shorthand. Pint on any PHP touched.
- Real-browser checks in the built-in browser after the user signs in once (Claude may not type passwords).

## Delivery

- Work on `youssef-interfaces-edits`, all changes left uncommitted for the user's review.
- At the end, record the modal/field conventions in `.ai/rules` via `record-rule`.
