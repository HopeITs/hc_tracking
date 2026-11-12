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
        <div class="ap-info is-warn ap-row" data-no-activities>
            <span class="ap-sp">{{ __('annual_plans.wf.no_activities') }}</span>
            <a class="dk-btn" href="{{ route('proposals.show', [$plan->proposal, 'tab' => 'logframe']) }}" wire:navigate data-test="open-logframe">{{ __('annual_plans.wf.open_logframe') }}</a>
        </div>
    @endforelse

    @if ($groups !== [])
        <div class="ap-row">
            <button type="button" class="dk-btn" wire:click="selectAllActivities" data-test="select-all-activities">{{ __('annual_plans.wf.select_all') }}</button>
            <span class="ap-faint ap-sm">{{ trans_choice('annual_plans.wf.selected', count($picked), ['count' => count($picked)]) }}</span>
        </div>
    @endif
    @error('activities') <p class="ap-err">{{ $message }}</p> @enderror

    @php($parentOptions = $this->activityParentOptions)
    @if ($addingActivity)
        <div class="ap-grp" data-add-activity-form>
            <h3>{{ __('annual_plans.wf.add_activity') }}</h3>
            <label class="ap-sm">{{ __('annual_plans.wf.activity_name') }}
                <input type="text" class="form-box-input" wire:model="newActivityName" maxlength="500" dir="auto" data-test="new-activity-name" autofocus>
            </label>
            @error('newActivityName') <p class="ap-err">{{ $message }}</p> @enderror
            <label class="ap-sm">{{ __('annual_plans.wf.activity_serves') }}
                <select class="form-box-input" wire:model="newActivityParent" data-test="new-activity-parent">
                    <option value="">{{ __('annual_plans.wf.pick_outcome') }}</option>
                    @foreach ($parentOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
            @error('newActivityParent') <p class="ap-err">{{ $message }}</p> @enderror
            <label class="ap-row ap-sm"><input type="checkbox" wire:model="newActivitySingle"> {{ __('annual_plans.wf.activity_single_week') }}</label>
            <div class="ap-row">
                <button type="button" class="dk-btn" wire:click="$set('addingActivity', false)">{{ __('annual_plans.wf.cancel') }}</button>
                <button type="button" class="dk-btn dk-primary" wire:click="addActivity" wire:loading.attr="disabled" wire:target="addActivity" data-test="save-new-activity">{{ __('annual_plans.wf.add_activity') }}</button>
            </div>
        </div>
    @elseif ($parentOptions !== [])
        <div class="ap-row">
            <button type="button" class="dk-btn" wire:click="$set('addingActivity', true)" data-test="add-activity">+ {{ __('annual_plans.wf.add_activity') }}</button>
            <span class="ap-faint ap-sm">{{ __('annual_plans.wf.missing_activity') }}</span>
        </div>
    @endif
</section>

<div class="ap-foot">
    <button type="button" class="dk-btn" wire:click="goToStep(0)">‹ {{ __('annual_plans.wf.back') }}</button>
    <span class="ap-sp"></span>
    <button type="button" class="dk-btn dk-primary" wire:click="saveActivities" @disabled($picked === []) data-test="plan-next">{{ __('annual_plans.wf.next_dates') }} ›</button>
</div>
