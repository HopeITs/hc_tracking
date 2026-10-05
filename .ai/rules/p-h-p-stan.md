---
paths:
  - 'tests/PHPStan/**'
---

# P H P Stan

## Livewire traits used only by Blade pages need a tests/PHPStan host
PHPStan cannot read the ⚡ single-file Livewire pages, so a trait in app/Livewire/Concerns used only by them is reported trait.unused and never analysed. Each such trait has an abstract host class in tests/PHPStan/ (extends Livewire\Component, uses the trait); add one for any new trait. phpstan.neon analyses only tests/PHPStan/ from tests/. Members the page must provide (page properties, #[Computed] values read as properties) are declared on the trait with @property / @property-read, not left implicit.
