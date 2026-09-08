---
paths:
  - 'database/factories/**'
---

# Factories

## Derived columns in factory states MUST be per-attribute closures
SUPERSEDES the earlier note saying "to feed a value into a state, use ->state([...])->approved()". That workaround is no longer needed and should not be reintroduced.

A plain state closure — `$this->state(fn (array $attributes) => [...])` — receives ONLY the definition plus earlier states. It does NOT see the array passed to create(). Any column derived from another column inside one is computed from a throwaway random value and then silently contradicted when create()'s overrides land on top.

This shipped a real bug: TransfersTrackingFactory::received() produced rows whose net_amount_received was larger than amount_received.

The fix, now used by received(), approved(), reached(), running() and closed(): give the derived column its own closure value.

    return $this->state([
        'fees' => fn (array $attributes): float => $this->feeFor($attributes),
    ]);

Laravel resolves these in expandAttributes AFTER definition, states and create() overrides are merged, so they see the caller's real values.

Two traps: a closure must depend only on plain values, never on another closure's key — keys introduced by a state are resolved after definition keys, so read the source column and recompute rather than reading a sibling derived key. And tests/Feature/Models/FactoryStateTest.php overrides the source column for every such state; add a case there for any new one.
