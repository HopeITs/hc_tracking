# P24 · Funding agreements index

**Route:** `funding-agreements.index` · **Group:** J · Money (same owner as P25–P26) · **Wave:** 1 · **Depends on:** 02 (`capacity-bar`)

## Files you own

- `resources/views/pages/funding-agreements/⚡index.blade.php`
- `lang/*/funding_agreements.php`
- `tests/Feature/FundingAgreementTest.php`

## Current state

Model `DonorProposal` (`donor_proposal`: `donor_id, proposal_id, budget, currency, project_num`). Filters: search (reference/donor/proposal), donor, currency, reset; "committed on this page" stat tiles per currency; rows: donor · proposal link, project_num, tranche count, proposal status, budget money; create/edit modal (donor, proposal, budget, currency, project_num); **no delete** (info banner: transfers cascade).

## Target layout

1. **Header:** keep filters + "Add agreement"; keep the no-delete info as a quiet `type-meta` note under the header (not a banner).
2. **Summary slot:** per currency (respecting filters, **whole filtered set, not just the page**): `x-ui.capacity-bar` committed vs received. One grouped query each for committed and received. Label changes from "on this page" to "matching filters".
3. **List** (`x-ui.data-table`):
   - Agreement: `donor.name · proposal.name` (links to donor and agreement show) + project_num meta
   - Proposal status `enum-badge`
   - Budget `x-ui.money`
   - Received: `x-ui.money` of Σ `net_amount_received` where `ar_status = received` **and** `amount_received_currency = agreement currency`; plus `x-ui.progress-bar` received ÷ budget. Received in another currency → separate "Also received: {money}" meta line, not added.
   - Tranches: "{received}/{total} received" (two `withCount`s)
   - Next scheduled: earliest `scheduled_date` of pending tranches with `x-ui.schedule-status`
   - Edit action (keep method names)

## Tests (`FundingAgreementTest.php`)

- Existing tests green.
- Add: summary uses whole filtered set; received excludes pending/failed and other currencies; "also received" line; next scheduled tranche.

## Acceptance

- [ ] ≤ 7 queries.
- [ ] Conventions §10 checklist.
