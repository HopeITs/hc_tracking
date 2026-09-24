# Notification rules page redesign

Date: 2026-09-24 · Page: `resources/views/pages/admin/⚡notification-settings.blade.php` (route `admin.notification-settings.edit`)

## Goal

A notification rules page that is easy to scan and edit: every rule in one predictable place, very little text, the whole page on about one screen on a desktop.

## History

A first "living timeline" version (a working-day axis with markers, bands and generated sentences) was built and rejected by the user as too hard to use: too long, too much text, controls scattered, timeline confusing. The user chose "clean rule rows" instead (2026-09-24).

## Decisions (user, 2026-09-24)

- Layout: clean rule rows — each rule is one row with its name and a one-line hint on the start side and one control on the end side.
- Saving: sticky save bar that appears only while there are unsaved changes (count, Discard, Save rules); the browser warns before leaving with unsaved changes.
- Due-soon warning days are edited as removable chips with an add field.
- Changed values show a "Use default (X)" link that restores that one rule (replaces the earlier per-section reset).

## Page structure

Page header (title only), then two columns on wide screens (one column below):

- **Start column**
  - **Working week** — seven day tiles (real checkboxes bound with `wire:model="workingDays"`) and a live "N working days" count. The last working day cannot be turned off (the row shakes; a note explains). Tiles show full day names when they fit; Arabic always shows full names.
  - **Deadlines** — a read-only row of labelled steps summarising what happens to a late item (e.g. "7 days before · Due date · Reminder every 3 days · Program manager on day 5 · Management on day 10"), then rows: warn before the due date (chips), remind again every, tell the program manager after, tell management after, escalation window.
- **End column**
  - **Stalled work** — five rows (task in progress, task under review, report in draft, report awaiting approval, proposal with no status change).
  - **Indicators and digests** — off-track threshold (%), daily brief time, weekly digest time (hint names the first working day, as `WeeklyDigestScanner` sends it then).

## Architecture

- **Livewire component**: public properties, validation rules and `NotificationSettings` unchanged; `dueSoonDays` stays a comma-separated string. Additions: `defaultRules()` (config defaults by property), `clientConfig()` (defaults, templates, plural forms, weekday names), `forgetErrors()`, and `save()` writes the normalised values back and dispatches `notification-rules-saved`.
- **Alpine** `notificationRules` in `resources/js/notification-rules.js`: edits the Livewire properties through `$wire` (no request until Save), computes the step row, dirty count and default links. Steppers clamp to the server bounds (management always after the program manager).
- **Blade**: `_rules-week`, `_rules-warnings` and `_rules-row` (stepper or time field) partials next to the page.
- **Plurals**: the server resolves each plural for 1, 2, 3 and 11 (one count per Arabic range) with `:count` left in place; the script picks the form.
- **Styles**: `.rules-*` classes in `app.css` using existing tokens; dark mode and RTL covered.

## Testing

`tests/Feature/Notifications/NotificationSettingsAdminTest.php`: access, saving and validation, normalisation plus the saved event, no event on a failed save, defaults, clearing errors, Arabic plural forms, Arabic weekday labels, and every group rendering. `LocalizationTest` keeps en/ar keys in sync.
