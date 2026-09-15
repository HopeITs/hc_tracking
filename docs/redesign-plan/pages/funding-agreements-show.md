# P25 · Funding agreement detail

**Route:** `funding-agreements.show` (`{donorProposal}`) · **Group:** J · Money · **Wave:** 1 · **Depends on:** 02, 03

## Files you own

- `resources/views/pages/funding-agreements/⚡show.blade.php`
- `lang/*/funding_agreements.php` (shared with P24)
- `tests/Feature/FundingAgreementTest.php` (show tests)

## Current state

Header (donor · proposal title, project_num subtitle, links to donor and proposal, proposal status); stat tiles committed + net received per currency; "Tranches" panel: scheduled_date, account (+ closed flag), transfer_date, sent money (`payment_amount`, `amount_transfered_currency`), net received (`net_amount_received`, `amount_received_currency`) + `exchange_rate`, `ar_status` badge. No inline edit (edit on transfers index).

## Target layout

1. **Detail header:** keep; `summary` slot: `x-ui.capacity-bar` budget vs received in the agreement currency (large), plus "Also received in {currency}: {money}" lines for other currencies.
2. **Tranche schedule** (`x-ui.timeline`): one row per tranche; point marker at `scheduled_date`, actual marker at `receive_date` (or `transfer_date` if not received), tone: received good, failed critical, pending past schedule warn/critical via schedule logic. Window = first scheduled − 1 month → last date + 1 month.
3. **Cumulative receipts chart:** `x-ui.chart type="area"` of cumulative `net_amount_received` over `receive_date` for received tranches **in the agreement currency**, with a horizontal annotation line at `budget` (Apex `annotations.yaxis`, passed via `options`).
4. **Tranches table** (`x-ui.data-table`): Scheduled (+ schedule-status) · Account (+ "Closed" chip) · Sent money · Fees money (`fees`, `fees_currency`) · Received / net money + exchange rate meta · Status `enum-badge` · link "Edit on Transfers" → `transfers.index?search={project_num}` (use that page's search key).

## Tests

- Capacity bar in agreement currency; other-currency receipts listed separately.
- Timeline rows per tranche with correct tone for a pending tranche past schedule (`Carbon::setTestNow`).
- Chart cumulative series excludes pending and failed tranches.

## Acceptance

- [ ] ≤ 5 queries.
- [ ] Conventions §10 checklist.
