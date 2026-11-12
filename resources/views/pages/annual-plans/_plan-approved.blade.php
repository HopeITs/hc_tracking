{{-- Step 6: the approved plan, live. The calendar, MEAL's follow-up tasks, and what happens next. --}}
<section class="ap-card" data-plan-step="approved">
    <div>
        <h2>✓ {{ __('annual_plans.wf.approved_title') }}</h2>
        <p class="ap-q">{{ __('annual_plans.wf.approved_question', ['name' => $plan->approver?->name ?? '—', 'date' => $plan->approved_at ? $formatMoment($plan->approved_at) : '—']) }}</p>
    </div>
    @include('pages.annual-plans._plan-gantt')

    @if ($plan->followUps->isNotEmpty())
        <div class="ap-grp">
            <h3>{{ __('annual_plans.wf.follow_ups_title') }}</h3>
            @foreach ($plan->followUps as $followUp)
                @php $followItem = $plan->items->firstWhere('id', $followUp->activity_plan_item_id); @endphp
                <div class="ap-bt" wire:key="afu-{{ $followUp->id }}">
                    <b style="min-width: 0" dir="auto">{{ $followItem?->activity?->statement }}</b>
                    <span class="ap-sm ap-muted">{{ $followUp->trigger->label() }}</span>
                    <span class="ap-sm" dir="auto">→ {{ $followUp->description }}</span>
                </div>
            @endforeach
        </div>
    @endif

    <div class="ap-nlist">
        <div><span class="ap-n">1</span><span>{!! __('annual_plans.wf.next_1') !!}</span></div>
        <div><span class="ap-n">2</span><span>{!! __('annual_plans.wf.next_2') !!}</span></div>
        <div><span class="ap-n">3</span><span>{!! __('annual_plans.wf.next_3') !!}</span></div>
    </div>
</section>

@include('pages.annual-plans._plan-updates')
