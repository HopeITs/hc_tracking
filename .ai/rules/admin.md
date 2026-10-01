---
paths:
  - '{resources/views/pages/transfers/**,resources/views/pages/funding-agreements/**,resources/views/pages/donors/**,resources/views/pages/proposals/**,resources/views/pages/admin/⚡accounts.blade.php}'
---

# Admin

## Funding pages open on the table
Funding pages (indexes, agreement/donor detail, proposal Funding tab) open on the table view via SwitchesFundingView. The old visuals live in `_overview` / `_show-overview` partials (or an @if on the proposal page) behind `view=overview`. Tests that assert those visuals call `->set('view', 'overview')`; never delete them.
