{{-- Step 1 of the plan: the proposal it is for. Dates and governorates come from it. --}}
@php
    $proposal = $plan->proposal;
@endphp

<section class="ap-card" data-plan-step="proposal">
    <div>
        <h2>{{ __('annual_plans.wf.proposal_title') }}</h2>
        <p class="ap-q">{{ __('annual_plans.wf.proposal_question') }}</p>
    </div>
    <div class="ap-opt is-on">
        <input type="radio" checked disabled aria-label="{{ $proposal->name }}">
        <div>
            <b dir="auto">{{ $proposal->name }}</b>
            <span class="ap-sm ap-muted">{{ $proposal->internal_project_number }} · {{ $plan->program?->name }}</span><br>
            <span class="ap-sm ap-num">{{ $formatMoment($this->calendar->begin) }} → {{ $formatMoment($this->calendar->end) }} · {{ trans_choice('annual_plans.wf.weeks_count', $this->calendar->weekCount(), ['count' => $this->calendar->weekCount()]) }} · {{ implode('، ', $this->cityNames) ?: __('annual_plans.wf.no_centres') }}</span>
        </div>
    </div>
    <div class="ap-info is-blue">{!! __('annual_plans.wf.own_program_only') !!}</div>
</section>

<div class="ap-foot">
    <span class="ap-sp"></span>
    <button type="button" class="dk-btn dk-primary" wire:click="goToStep(1)" data-test="plan-next">{{ __('annual_plans.wf.next_activities') }} ›</button>
</div>
