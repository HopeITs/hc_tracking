# P20 · Donors index

**Route:** `donors.index` · **Group:** H · Funding (same owner as P21) · **Wave:** 1 · **Depends on:** 02

## Files you own

- `resources/views/pages/donors/⚡index.blade.php`
- `lang/*/donors.php`
- `tests/Feature/DonorDirectoryTest.php`

## Current state

Search (name), "Add donor" modal (name, type, contact_person, email, phone, country, notes), rows: name, type badge, agreements count; edit; delete disabled with lock tooltip when agreements exist. 15/page.

Fields: `donors.name, type (DonorType: bilateral, foundation, individual, corporate; nullable), contact_person, email, phone, country, notes`.

## Target layout

1. **Header:** search + new filters `type` (`#[Url]`) and `country` (`#[Url]`, options from distinct countries); "Add donor".
2. **Summary slot:** `x-ui.status-strip` of donors by type (+ "Unspecified"), `wire-model="type"`.
3. **Donor profile grid** (`@3xl:grid-cols-2 @6xl:grid-cols-3`), one sheet per donor:
   - Name (link `donors.show`) + type badge + country
   - Contact: contact person, email (`mailto:` link), phone (`tel:` link); missing contact → neutral "No contact recorded"
   - Committed per currency: `withSum` is per-currency-unsafe, so load `donorProposals` grouped: one query `DonorProposal::selectRaw('donor_id, currency, sum(budget) total')->whereIn('donor_id', $pageIds)->groupBy('donor_id', 'currency')`, keyed in PHP; render up to 3 `x-ui.money` lines
   - Agreements count
   - Actions: edit, delete/lock (keep method names)
4. Pagination unchanged (15).

## Tests (`DonorDirectoryTest.php`)

- Existing tests green.
- Add: type/country filters; committed per currency with USD+EUR donor shows two amounts and no total; contact links rendered.

## Acceptance

- [ ] ≤ 5 queries.
- [ ] Conventions §10 checklist.
