# P26 · Transfers index

**Route:** `transfers.index` · **Group:** J · Money · **Wave:** 1 · **Depends on:** 02

## Files you own

- `resources/views/pages/transfers/⚡index.blade.php`
- `lang/*/transfers.php`
- `tests/Feature/TransferTrackingTest.php`, `tests/Feature/TransfersTrackingTest.php` (UI assertions only)

## Current state

Model `TransfersTracking` (`transfers_tracking`): `donor_proposal_id, account_id, payment_amount, amount_transfered_currency, scheduled_date, transfer_date, fees, fees_currency, amount_received, exchange_rate, net_amount_received, amount_received_currency, receive_date, ar_status (pending | received | failed)`. Received amounts are nullable. Filters: search (reference/donor/proposal), receipt status, account, reset; "sent on this page" per currency tiles; dense 5-column rows (agreement/date/account | sent | fees | net received | status + edit); create/edit modal with three fieldsets (Sent, Fees, Received); **no delete** ("a money record never disappears").

## Target layout

1. **Header:** keep filters + "Record transfer"; add date range filter on `scheduled_date` (`#[Url] from`, `#[Url] to`).
2. **Summary slot:** `x-ui.status-strip` by `ar_status` (`wire-model` = existing status property), plus per currency, for the **filtered set**: "Sent {money} · Fees {money} · Net received {money}", each grouped by its own currency column, never mixed.
3. **Table** (`x-ui.data-table`, desktop columns; stacked on mobile):
   - Agreement: `donor · proposal` link to `funding-agreements.show` + project_num meta
   - Scheduled: date + `x-ui.schedule-status :planned="scheduled_date" :actual="receive_date" :done="ar_status === Received"`
   - Account: name + "Closed" chip
   - Sent: `x-ui.money` (payment_amount, amount_transfered_currency)
   - Net received: `x-ui.money` or em dash when null
   - Status `enum-badge`
   - Expand button (chevron, `aria-expanded`) revealing a detail row with: transfer_date, receive_date, fees money, gross amount_received, exchange_rate, and the conversion sentence "{sent} → {net received} at {rate}" (no computation, just the stored figures)
   - Edit (keep method names)
4. Modal: keep three fieldsets; add a live read-only line in the Received fieldset: "Fees are recorded separately and are not deducted automatically" (only if that's true in the current save logic; check the component; otherwise omit).
5. Keep the "money never disappears" note as quiet meta under the header.

## Tests

- Existing tests green.
- Add: status strip; date range filter; summary never merges currencies (USD sent, EUR received seeded); expandable detail contains exchange rate; null received shows em dash.

## Acceptance

- [ ] ≤ 7 queries.
- [ ] Conventions §10 checklist.
