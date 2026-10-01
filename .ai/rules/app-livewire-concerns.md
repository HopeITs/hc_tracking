---
paths:
  - app/Livewire/Concerns/FiltersFinanceRegister.php
---

# App Livewire Concerns

## Register trait hook order and ownership
The trait's mount/updated hooks run after the page's own mount()/updated(). The trait never owns the currency filter (each page does). Year-only figures (`figures=year`) need a selected year; without one they fall back to lifetime.
