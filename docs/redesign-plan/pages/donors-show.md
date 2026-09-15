# P21 · Donor profile

**Route:** `donors.show` · **Group:** H · Funding · **Wave:** 1 · **Depends on:** 02, 03

## Files you own

- `resources/views/pages/donors/⚡show.blade.php`
- `lang/*/donors.php` (shared with P20)
- `tests/Feature/DonorDirectoryTest.php` (show tests), `tests/Feature/Models/DonorProfileTest.php` only if a model-level assertion changes (it shouldn't)

## Current state

Detail header (name, type, agreement count); stat row: committed per currency, disbursed per currency, active/closed project counts, reports submitted; one panel listing agreements (proposal link, project_num, tranche count, proposal status, budget money). **Contact info not shown**.

## Target layout

1. **Detail header:** name, type badge, country; `actions`: "Edit donor" (reuse the index modal pattern: if the edit modal only exists on the index page, link to `donors.index?edit={id}` only if that page supports it; otherwise add no edit action. Don't duplicate the modal).
2. **Profile grid** (`@5xl:grid-cols-[1fr_2fr]`):
   - **Contact panel:** contact person, email (mailto), phone (tel), country, notes (max 70ch, escaped, line breaks). Empty fields show "Not recorded".
   - **Funding position panel:** per currency `x-ui.capacity-bar` committed (Σ agreements budget) vs received (Σ `net_amount_received` of that donor's transfers with `ar_status = received`, grouped by `amount_received_currency`). Keep the existing per-currency computations; don't re-derive differently.
3. **Receipts over time:** `x-ui.chart type="bar"` monthly received for the last 24 months, **one chart per currency** (tabs when > 1).
4. **Agreements panel** (`x-ui.data-table`): proposal (link) + project_num · proposal status badge · budget money · received money (same currency) · `x-ui.progress-bar` received ÷ budget · tranches count · link to `funding-agreements.show`.
5. **Proposals without an agreement for this donor:** not derivable (a proposal without a donor_proposal row has no donor). **Do not build.** The brief's idea is dropped as unsupported by data.
6. Delivery stats (active/closed projects, reports submitted) move to a compact meta line under the header.

## Tests

- Contact info renders (email as mailto) and "Not recorded" for nulls.
- Capacity bars per currency; agreement row progress.
- Chart per currency, no combined series.

## Acceptance

- [ ] ≤ 8 queries.
- [ ] Conventions §10 checklist.
