---
paths:
  - 'app/Models/**'
  - app/Models/Account.php
---

# Models

## plan_indicators sources are YearlyPlan and Proposal, via a morph map
`plan_indicators.source_type`/`source_id` is a morphTo relation with exactly two valid sources: YearlyPlan and Proposal. A target is committed either in an annual operational plan or in a donor grant proposal.

`source_type` is VARCHAR(50), so register short morph keys rather than storing class names:

    Relation::enforceMorphMap([
        'yearly_plan' => YearlyPlan::class,
        'proposal' => Proposal::class,
    ]);

Put this in a service provider's boot(). Storing 'App\\Models\\YearlyPlan' would work today but leaves no headroom in the column and couples stored data to the namespace.

No foreign key backs source_id — see the migrations rule about orphans for the cleanup obligation.

## Model file shape: strict_types, attributes, casts() and CarbonImmutable
Every class in app/Models carries `declare(strict_types=1);`. Pint's laravel preset does not add or strip it, so keep it by hand on new models.

Mass assignment and hidden fields use the Laravel 13 class attributes `#[Fillable([...])]` / `#[Hidden([...])]`, not `protected $fillable`. Casts go in `protected function casts(): array`. `users.role` is deliberately NOT fillable — adding it opens privilege escalation through registration.

`AppServiceProvider` calls `Date::use(CarbonImmutable::class)`, so every `date` / `datetime` cast returns `CarbonImmutable`, never `Illuminate\Support\Carbon`. Type `@property` docblocks accordingly.

`DonorProposal` and `TransfersTracking` are the only models needing `protected $table` (donor_proposal, transfers_tracking); everything else infers.

## is_active decides whether an account is usable, not closed_at
`accounts.is_active` is the single source of truth for whether an account can receive a transfer. `closed_at` only records when it was closed and must never be read as a usability check.

Call sites go through `isUsable()`, the `#[Scope] active()` query scope, or `close()` — never the raw columns, so the two can never be interpreted apart. `close()` writes both together so a closed account is never left looking active.

The model carries `protected $attributes = ['is_active' => true]` mirroring the column default. Without it `Account::create([...])->isUsable()` throws a TypeError, because the factory deliberately omits the column and the attribute is therefore unhydrated until the model is refreshed. The column default is still proven independently by a raw-insert test in tests/Feature/AccountTest.php.

Accounts are never deleted — transfers_tracking.account_id restricts on delete. Close them instead.
