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
        {{-- The three fields stay behind their own button, the way a missing activity is added on step 2. --}}
        @if ($addingFollowUp)
            <div class="ap-grp ap-add-task" data-add-follow-up-form>
                <h3>{{ __('annual_plans.wf.add_task') }}</h3>
                <label class="ap-grp">
                    <span class="ap-lbl">{{ __('annual_plans.wf.activity') }}</span>
                    <select class="ap-sel is-wide @error('followUpItem') is-err @enderror" wire:model="followUpItem" data-test="follow-up-item">
                        <option value="">{{ __('annual_plans.wf.pick_activity') }}</option>
                        @foreach ($plan->items as $item)
                            <option value="{{ $item->id }}">{{ $item->activity?->statement }}</option>
                        @endforeach
                    </select>
                </label>
                @error('followUpItem') <p class="ap-err">{{ $message }}</p> @enderror
                <label class="ap-grp">
                    <span class="ap-lbl">{{ __('annual_plans.wf.when') }}</span>
                    <select class="ap-sel is-wide" wire:model="followUpTrigger" data-test="follow-up-trigger">
                        @foreach ($this->followUpTriggers() as $trigger)
                            <option value="{{ $trigger->value }}">{{ $trigger->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="ap-grp">
                    <span class="ap-lbl">{{ __('annual_plans.wf.task_text') }}</span>
                    <input class="ap-inp @error('followUpText') is-err @enderror" wire:model="followUpText" maxlength="255" placeholder="{{ __('annual_plans.wf.follow_up_placeholder') }}" dir="auto" data-test="follow-up-text" autofocus>
                </label>
                @error('followUpText') <p class="ap-err">{{ $message }}</p> @enderror
                <div class="ap-row">
                    <button type="button" class="dk-btn" wire:click="$set('addingFollowUp', false)">{{ __('annual_plans.wf.cancel') }}</button>
                    <button type="button" class="dk-btn dk-primary" wire:click="addFollowUp" wire:loading.attr="disabled" wire:target="addFollowUp" data-test="add-follow-up">{{ __('annual_plans.wf.add_task') }}</button>
                </div>
            </div>
        @else
            <div class="ap-row">
                <button type="button" class="dk-btn" wire:click="$set('addingFollowUp', true)" data-test="open-follow-up">+ {{ __('annual_plans.wf.add_task') }}</button>
            </div>
        @endif
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
{{-- The plan it falls on is named in the dialog: two plans can wait with MEAL at once. --}}
<x-ui.confirm-modal
    icon="check-badge"
    wire:model="confirmingApproval"
    tone="neutral"
    :title="__('annual_plans.wf.approve_button')"
    :message="__('annual_plans.wf.approve_confirm')"
    :record="$plan->proposal->name"
    :record-meta="$plan->program?->name"
    record-icon="calendar-date-range"
    confirm="approvePlan"
    :confirm-label="__('annual_plans.wf.approve_button')"
    confirm-test="confirm-approve-plan"
/>
