{{-- Section 2 of the plan: the logframe activities it runs, grouped by outcome. --}}
@php
    $groups = $this->activityGroups;
@endphp

<section class="ap-card" data-plan-step="activities">
    {{-- A standing link to the proposal's Logical Framework: activities are added and
         edited there, so the program manager always needs a way back to it, not only
         while the plan has no activities yet (the @empty branch below). --}}
    <div class="ap-row" style="align-items: flex-start; justify-content: space-between; gap: 12px">
        <div class="ap-sp">
            <h2>{{ __('annual_plans.wf.activities_title') }}</h2>
            <p class="ap-q">{{ __('annual_plans.wf.activities_question') }}</p>
        </div>
        <a class="dk-btn" href="{{ route('proposals.show', [$plan->proposal, 'tab' => 'logframe']) }}" wire:navigate data-test="open-logframe-link">{{ __('annual_plans.wf.open_logframe') }}</a>
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
            <span class="ap-faint ap-sm ap-sp">{{ trans_choice('annual_plans.wf.selected', count($picked), ['count' => count($picked)]) }}</span>
            @if ($picksUnsaved ?? false)
                <span class="ap-sm" data-picks-unsaved>{{ __('annual_plans.wf.activities_unsaved') }}</span>
            @endif
            <button type="button" class="dk-btn dk-primary" wire:click="saveActivities" wire:loading.attr="disabled" wire:target="saveActivities" @disabled($picked === [] || ! ($picksUnsaved ?? true)) data-test="save-activities">{{ __('annual_plans.wf.save_activities') }}</button>
        </div>
    @endif
    @error('activities') <p class="ap-err">{{ $message }}</p> @enderror

    @php($parentOptions = $this->activityParentOptions)
    @if ($addingActivity)
        {{-- Same field styles as the activity details (ap-lbl / ap-inp / ap-sel), in its own
             boxed panel: form-box-input draws no border on its own, so outside an
             x-ui form-box the name and outcome fields used to be all but invisible. --}}
        <div class="ap-grp ap-add-activity" data-add-activity-form>
            <h3>{{ __('annual_plans.wf.add_activity') }}</h3>
            <label class="ap-grp">
                <span class="ap-lbl">{{ __('annual_plans.wf.activity_name') }}</span>
                <input type="text" class="ap-inp @error('newActivityName') is-err @enderror" wire:model="newActivityName" maxlength="500" dir="auto" placeholder="{{ __('annual_plans.wf.activity_name_placeholder') }}" data-test="new-activity-name" autofocus>
            </label>
            @error('newActivityName') <p class="ap-err">{{ $message }}</p> @enderror
            <label class="ap-grp">
                <span class="ap-lbl">{{ __('annual_plans.wf.activity_serves') }}</span>
                <select class="ap-sel is-wide @error('newActivityParent') is-err @enderror" wire:model="newActivityParent" data-test="new-activity-parent">
                    <option value="">{{ __('annual_plans.wf.pick_outcome') }}</option>
                    @foreach ($parentOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
            @error('newActivityParent') <p class="ap-err">{{ $message }}</p> @enderror
            {{-- The same two questions, words and buttons as the Logical Framework's "Add result". --}}
            <x-ui.form-segmented
                wire:model="newActivityKind"
                :label="__('results_framework.activity_kind')"
                :help="__('results_framework.activity_kind_help')"
                required
                :options="collect(\App\Enums\ActivityKind::cases())->map(fn (\App\Enums\ActivityKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()])->all()"
            />
            <x-ui.form-segmented
                wire:model="newActivityPeriod"
                :label="__('results_framework.checked')"
                required
                :options="collect(\App\Enums\Period::cases())->map(fn (\App\Enums\Period $period): array => ['value' => $period->value, 'label' => $period->label()])->all()"
            />
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
