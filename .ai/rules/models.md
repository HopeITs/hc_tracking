---
paths:
  - 'app/Models/**'
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
