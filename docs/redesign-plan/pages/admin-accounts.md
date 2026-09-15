# P29 · Admin · Accounts

**Route:** `admin.accounts.index` · **Group:** K · Admin · **Wave:** 1 · **Depends on:** 02 (`data-table`)

## Files you own

- `resources/views/pages/admin/⚡accounts.blade.php`
- `lang/*/accounts.php`
- `tests/Feature/Admin/AccountDirectoryTest.php`

## Current state

Bank/receiving accounts (`accounts`: `name, opened_at, closed_at, is_active`). Search + open/closed filter + "Open account"; row list: name, opened/closed dates, transfer count, open/closed badge, edit, close (no delete: FK-protected). Create/edit modal: name + opened date. Close-confirmation modal.

## Target layout

1. `x-ui.page-header`: search + state filter, "Open account".
2. **Summary slot:** `x-ui.status-strip` Open (good) / Closed (neutral), `wire-model` = existing state property.
3. **One** `x-ui.data-table`: Account name · Opened (date) · Closed (date or em dash) · Transfers (count) · **Received through this account**: per currency `x-ui.money` of Σ `net_amount_received` (`ar_status = received`, grouped by `account_id, amount_received_currency` in one query) · Pending tranches (count, warn chip when > 0: `withCount` where `ar_status = pending`) · State chip · Actions (edit, close; keep method names and the close confirmation).
4. Closing an account with pending tranches: **do not change behaviour**. If the current close action allows it, show a warn line inside the close modal "{n} pending tranches use this account" (read-only info).
5. Remove per-file class maps and `admin-directory` wrapper for this page.

## Tests (`Admin/AccountDirectoryTest.php`)

- Existing tests green (close flow, no delete).
- Add: received per currency; pending count; close modal warning text when pending tranches exist.

## Acceptance

- [ ] ≤ 5 queries.
- [ ] Conventions §10 checklist.
