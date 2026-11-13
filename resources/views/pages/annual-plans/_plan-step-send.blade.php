{{-- Step 4 of the plan: the summary and the calendar, then send it to MEAL. --}}
@php
    $governorates = array_values(array_intersect_key($this->cityNames, array_flip(array_unique(array_merge(...array_map(fn ($item) => $item->cityIds(), $plan->items->all()))))));
@endphp

<section class="ap-card" data-plan-step="send">
    <div>
        <h2>{{ __('annual_plans.wf.send_title') }}</h2>
        <p class="ap-q">{{ __('annual_plans.wf.send_question') }}</p>
    </div>
    <div class="ap-facts">
        <div class="ap-fact"><span>{{ __('annual_plans.wf.fact_proposal') }}</span><b dir="auto">{{ $plan->proposal->name }}</b></div>
        <div class="ap-fact"><span>{{ __('annual_plans.wf.fact_activities') }}</span><b class="ap-num">{{ $plan->items->count() }}</b></div>
        <div class="ap-fact"><span>{{ __('annual_plans.wf.fact_governorates') }}</span><b>{{ implode('، ', $governorates) }}</b></div>
        <div class="ap-fact"><span>{{ __('annual_plans.wf.fact_weeks') }}</span><b class="ap-num">{{ $this->calendar->weekCount() }}</b></div>
    </div>
    @include('pages.annual-plans._plan-gantt')
    <div class="ap-info is-blue">{{ __('annual_plans.wf.send_info') }}</div>
    @error('plan') <p class="ap-err">{{ $message }}</p> @enderror
</section>

<div class="ap-foot">
    <button type="button" class="dk-btn" wire:click="goToStep(2)">‹ {{ __('annual_plans.wf.back') }}</button>
    <span class="ap-sp"></span>
    <button type="button" class="dk-btn dk-primary" wire:click="submitPlan" data-test="send-to-meal">{{ __('annual_plans.wf.send_button') }} ✓</button>
</div>
