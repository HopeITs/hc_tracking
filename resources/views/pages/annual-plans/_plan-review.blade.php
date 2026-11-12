{{--
    Step 5, the MEAL manager: the plan exactly as the program manager entered
    it, every field read-only, the calendar, follow-up tasks, then approve or
    send it back with a reason.
--}}
<section class="ap-card" data-plan-step="review">
    <div>
        <h2>{{ __('annual_plans.wf.review_title') }}</h2>
        <p class="ap-q">{!! __('annual_plans.wf.review_question', ['name' => e($plan->submitter?->name ?? '—'), 'plan' => e($plan->proposal->name), 'date' => e($formatMoment($plan->submitted_at))]) !!}</p>
    </div>
    <div class="ap-info is-blue">{{ __('annual_plans.wf.read_only_info') }}</div>

    <div class="ap-acts" data-review-items>
        @foreach ($plan->items as $item)
            <div class="ap-act is-ro" wire:key="ro-{{ $item->id }}">
                @include('pages.annual-plans._plan-item-head', ['item' => $item, 'isOpen' => true, 'readOnly' => true])
                @include('pages.annual-plans._plan-item-form', ['item' => $item, 'readOnly' => true])
            </div>
        @endforeach
    </div>

    <div class="ap-grp"><h3>{{ __('annual_plans.wf.whole_plan') }}</h3>@include('pages.annual-plans._plan-gantt')</div>

    <div class="ap-grp" data-follow-ups>
        <h3>{{ __('annual_plans.wf.follow_ups_title') }}</h3>
        <p class="ap-sm ap-muted">{{ __('annual_plans.wf.follow_ups_help') }}</p>
        @foreach ($plan->followUps as $followUp)
            @php $followItem = $plan->items->firstWhere('id', $followUp->activity_plan_item_id); @endphp
            <div class="ap-bt" wire:key="fu-{{ $followUp->id }}" data-follow-up="{{ $followUp->id }}">
                <b style="min-width: 0" dir="auto">{{ $followItem?->activity?->statement }}</b>
                <span class="ap-sm ap-muted">{{ $followUp->trigger->label() }}</span>
                <span class="ap-sm" dir="auto">→ {{ $followUp->description }}</span>
                <span class="ap-sp"></span>
                <button type="button" class="dk-btn dk-ghost" wire:click="removeFollowUp({{ $followUp->id }})">{{ __('annual_plans.wf.remove') }}</button>
            </div>
        @endforeach
        <div class="ap-task">
            <select class="ap-sel @error('followUpItem') is-err @enderror" wire:model="followUpItem" aria-label="{{ __('annual_plans.wf.activity') }}" data-test="follow-up-item">
                <option value="">{{ __('annual_plans.wf.pick_activity') }}</option>
                @foreach ($plan->items as $item)
                    <option value="{{ $item->id }}">{{ $item->activity?->statement }}</option>
                @endforeach
            </select>
            <select class="ap-sel" wire:model="followUpTrigger" aria-label="{{ __('annual_plans.wf.when') }}">
                @foreach ($this->followUpTriggers() as $trigger)
                    <option value="{{ $trigger->value }}">{{ $trigger->label() }}</option>
                @endforeach
            </select>
            <input class="ap-inp @error('followUpText') is-err @enderror" wire:model="followUpText" placeholder="{{ __('annual_plans.wf.follow_up_placeholder') }}" dir="auto" data-test="follow-up-text">
            <button type="button" class="dk-btn" wire:click="addFollowUp" data-test="add-follow-up">{{ __('annual_plans.wf.add_task') }}</button>
        </div>
        @error('followUpItem') <p class="ap-err">{{ $message }}</p> @enderror
        @error('followUpText') <p class="ap-err">{{ $message }}</p> @enderror
    </div>

    <div class="ap-grp">
        <h3>{{ __('annual_plans.wf.send_back_title') }}</h3>
        <textarea class="ap-inp @error('sendBackReason') is-err @enderror" wire:model="sendBackReason" placeholder="{{ __('annual_plans.wf.send_back_placeholder') }}" dir="auto" data-test="send-back-reason"></textarea>
        @error('sendBackReason') <p class="ap-err">{{ $message }}</p> @enderror
    </div>
</section>

<div class="ap-foot">
    <button type="button" class="dk-btn" wire:click="sendBackPlan" data-test="send-back">↩︎ {{ __('annual_plans.wf.send_back_button') }}</button>
    <span class="ap-sp"></span>
    <button type="button" class="dk-btn dk-primary" wire:click="$set('confirmingApproval', true)" data-test="approve-plan">{{ __('annual_plans.wf.approve_button') }} ✓</button>
</div>

{{-- The app's own confirmation, never the browser's (user report 2026-11-12). --}}
<x-ui.confirm-modal
    wire:model="confirmingApproval"
    tone="neutral"
    :title="__('annual_plans.wf.approve_button')"
    :message="__('annual_plans.wf.approve_confirm')"
    confirm="approvePlan"
    :confirm-label="__('annual_plans.wf.approve_button')"
    confirm-test="confirm-approve-plan"
/>
