---
paths:
  - 'app/Providers/**'
---

# Providers

## enforceMorphMap is global — database notifications will throw until User is mapped
`AppServiceProvider::configureMorphMap()` calls `Relation::enforceMorphMap()`, which applies to EVERY polymorphic relation in the app, not just plan_indicators. Any model on either side of a morph must be in the map or `getMorphClass()` throws `ClassMorphViolationException`.

Currently safe: the only morphs are plan_indicators' source, and Fortify/passkeys use a plain `user_id` foreign key with no polymorphism anywhere in vendor.

The trap: `User` uses `Notifiable`. The day someone adds a `notifications` table and stores a database notification, `notifiable_type` will throw until `'user' => User::class` is added to the map. The symptom looks nothing like the cause. Same applies to any future morphTo (attachments, comments, activity log).

User was deliberately NOT pre-registered: forcing a conscious decision when a new morph appears is the whole point of enforcing the map. `tests/Feature/Models/MorphMapTest.php` asserts the map holds exactly the two plan_indicator sources, so adding an entry is a visible, tested change.

## Model::shouldBeStrict is on outside production — keep it on
`AppServiceProvider::configureDefaults()` calls `Model::shouldBeStrict(! app()->isProduction())`. It turns three silent bug classes into loud failures in local and test: lazy loading (N+1), mass assignment that quietly discards attributes, and reading an attribute that was never retrieved.

It was enabled after two bugs found by hand would each have been caught automatically by it: `Account::close()` using fill() with a column outside #[Fillable] (silently discarded, returned true, account stayed open), and `isUsable()` reading `is_active` on a model whose factory omits it (returned null through a `: bool` return type).

It is off in production on purpose — a missing-attribute access should degrade there, not 500.

If a test starts failing with LazyLoadingViolationException, eager load the relation; do not disable the check. If you see MissingAttributeException on a model whose factory omits a defaulted column, mirror the column default in the model's `protected $attributes` (see Account) rather than making the factory set it — the factories omit defaulted columns on purpose.
