{{-- Step 2 of the plan: the logframe activities it runs, grouped by outcome. --}}
@php
    $groups = $this->activityGroups;
@endphp

<section class="ap-card" data-plan-step="activities">
    <div>
        <h2>{{ __('annual_plans.wf.activities_title') }}</h2>
        <p class="ap-q">{{ __('annual_plans.wf.activities_question') }}</p>
    </div>

    @forelse ($groups as $group)
        <div class="ap-grp" wire:key="outcome-{{ $loop->index }}">
            <h3 dir="auto">{{ __('annual_plans.wf.outcome', ['name' => $group['outcome']]) }}</h3>
            @foreach ($group['activities'] as $activity)
                <label @class(['ap-opt', 'is-on' => in_array((string) $activity->id, $picked, true)]) wire:key="pick-{{ $activity->id }}">
                    <input type="checkbox" value="{{ $activity->id }}" wire:model.live="picked" data-test="pick-activity-{{ $activity->id }}">
                    <div><b dir="auto">{{ $activity->statement }}</b></div>
                </label>
            @endforeach
        </div>
    @empty
        <div class="ap-info is-warn" data-no-activities>{{ __('annual_plans.wf.no_activities') }}</div>
    @endforelse

    @if ($groups !== [])
        <div class="ap-row">
            <button type="button" class="dk-btn" wire:click="selectAllActivities" data-test="select-all-activities">{{ __('annual_plans.wf.select_all') }}</button>
            <span class="ap-faint ap-sm">{{ trans_choice('annual_plans.wf.selected', count($picked), ['count' => count($picked)]) }}</span>
        </div>
    @endif
    @error('activities') <p class="ap-err">{{ $message }}</p> @enderror

    <div class="ap-info is-warn">{{ __('annual_plans.wf.missing_activity') }}</div>
</section>

<div class="ap-foot">
    <button type="button" class="dk-btn" wire:click="goToStep(0)">‹ {{ __('annual_plans.wf.back') }}</button>
    <span class="ap-sp"></span>
    <button type="button" class="dk-btn dk-primary" wire:click="saveActivities" @disabled($picked === []) data-test="plan-next">{{ __('annual_plans.wf.next_dates') }} ›</button>
</div>
