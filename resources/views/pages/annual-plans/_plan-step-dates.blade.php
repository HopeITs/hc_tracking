{{-- Step 3 of the plan: per activity, how it runs, what it counts, its batches and places. --}}
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

    <div class="ap-pin" data-dates-calendar>
        <h3>{{ __('annual_plans.wf.whole_plan') }}</h3>
        @include('pages.annual-plans._plan-gantt')
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

<div class="ap-foot">
    <button type="button" class="dk-btn" wire:click="goToStep(1)">‹ {{ __('annual_plans.wf.back') }}</button>
    <span class="ap-sp"></span>
    <button type="button" class="dk-btn dk-primary" wire:click="goToStep(3)" @disabled($readyCount < $items->count()) data-test="plan-next">{{ __('annual_plans.wf.next_send') }} ›</button>
</div>
