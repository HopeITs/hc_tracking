{{-- Section 3 of the plan: per activity, how it runs, what it counts, its batches and places. --}}
@php
    $items = $plan->items;
    $readyCount = $items->where('is_ready', true)->count();
@endphp

<section class="ap-card" data-plan-step="dates">
    <div>
        <h2>{{ __('annual_plans.wf.dates_title') }}</h2>
        <p class="ap-q">{{ __('annual_plans.wf.dates_question') }}</p>
    </div>

    @if ($plan->sent_back_reason)
        <div class="ap-info is-bad" data-sent-back>↩︎ <b>{{ __('annual_plans.wf.sent_back_by', ['name' => $plan->sentBackBy?->name ?? __('annual_plans.wf.meal')]) }}</b> “<span dir="auto">{{ $plan->sent_back_reason }}</span>” {{ __('annual_plans.wf.fix_and_send') }}</div>
    @endif

    {{-- The whole-plan calendar on request, not pinned over the form: kept on
         screen while typing, it covered the activity being filled (AP-1 UAT). --}}
    <div class="ap-cal" data-dates-calendar>
        <button type="button" class="ap-cal-toggle" wire:click="$toggle('showPlanCalendar')" aria-expanded="{{ $showPlanCalendar ? 'true' : 'false' }}" data-test="toggle-plan-calendar">
            <span>{{ __('annual_plans.wf.whole_plan') }}</span>
            <span class="ap-faint ap-sm">{{ $showPlanCalendar ? __('annual_plans.wf.hide_calendar') : __('annual_plans.wf.show_calendar') }}</span>
        </button>
        @if ($showPlanCalendar)
            @include('pages.annual-plans._plan-gantt')
        @endif
    </div>

    <div class="ap-row"><b data-ready-count>{{ __('annual_plans.wf.ready_count', ['ready' => $readyCount, 'total' => $items->count()]) }}</b></div>

    <div class="ap-acts">
        @foreach ($items as $item)
            @php $isOpen = $openItemId === $item->id; @endphp
            <div class="ap-act" wire:key="item-{{ $item->id }}" data-plan-item="{{ $item->id }}">
                @include('pages.annual-plans._plan-item-head', ['item' => $item, 'isOpen' => $isOpen, 'readOnly' => false])
                @if ($isOpen)
                    @include('pages.annual-plans._plan-item-form', ['item' => $item])
                @endif
            </div>
        @endforeach
    </div>
</section>
