---
paths:
  - 'resources/views/components/funding/**'
---

# Components Funding

## Register hooks
Tests and the guided tour depend on these hooks: `data-register` (table view only), `data-register-totals`, `data-finance-filters` (must NOT contain `data-register` — Overview tests assert its absence). Header cells start with `<th scope="col"`; sort buttons use the literal `sortRegister('key')`.
