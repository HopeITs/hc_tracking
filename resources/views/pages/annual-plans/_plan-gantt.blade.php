{{--
    The whole plan on the calendar: one row per activity with its batches,
    then, for a proposal several programs share, the other programs' plans
    as their own sections of the same calendar (read only, greyed).
--}}
@php
    $ganttMonths = $this->timeline([], false)['months'];
    $ganttRow = function (\App\Models\ActivityPlanItem $row): array {
        $batches = $this->batchRows($row);

        return ['batches' => $batches, 'timeline' => $this->timeline($batches, $row->is_single_week), 'count' => array_sum(array_map(fn (array $batch): int => (int) ($batch['count'] ?? 0), $batches))];
    };
    $siblings = $this->siblingPlans->filter(fn ($sibling) => $sibling->items->isNotEmpty());
@endphp

<div class="ap-gantt" data-plan-gantt>
    <div class="ap-gr is-head">
        <div>{{ __('annual_plans.wf.activity') }}</div>
        <div class="ap-tl">@foreach ($ganttMonths as $month)<span class="ap-m" style="inset-inline-start: {{ $month['left'] }}%">{{ $month['label'] }}</span>@endforeach</div>
    </div>
    @if ($siblings->isNotEmpty())
        <div class="ap-gr is-sec"><div>{{ $plan->program?->name }}</div></div>
    @endif
    @foreach ($plan->items as $row)
        @php $g = $ganttRow($row); $places = array_values(array_intersect_key($this->cityNames, array_flip($row->cityIds()))); @endphp
        <div class="ap-gr" wire:key="gantt-{{ $row->id }}">
            <div><span dir="auto">{{ $row->activity?->statement }}</span><small>{{ implode('، ', $places) }} · {{ $row->counted($g['count']) }}@if ($row->expected_people) · {{ trans_choice('annual_plans.wf.people', $row->expected_people, ['count' => $row->expected_people]) }}@endif</small></div>
            <div class="ap-tl">
                @foreach ($g['timeline']['months'] as $month)<span class="ap-m" style="inset-inline-start: {{ $month['left'] }}%"></span>@endforeach
                @foreach ($g['timeline']['bars'] as $bar)
                    @if ($bar['single'])<span class="ap-o" style="inset-inline-start: {{ $bar['left'] }}%" title="{{ $bar['title'] }}"></span>
                    @else<span class="ap-b {{ $bar['alt'] ? 'is-alt' : '' }}" style="inset-inline-start: {{ $bar['left'] }}%; width: {{ $bar['width'] }}%" title="{{ $bar['title'] }}"></span>@endif
                @endforeach
            </div>
        </div>
    @endforeach
    @foreach ($siblings as $sibling)
        <div class="ap-gr is-sec" wire:key="sibling-{{ $sibling->id }}"><div>{{ $sibling->program?->name }} · {{ $sibling->status->label() }}</div></div>
        @foreach ($sibling->items as $row)
            @php $g = $ganttRow($row); @endphp
            <div class="ap-gr is-other" wire:key="sibling-row-{{ $row->id }}">
                <div><span dir="auto">{{ $row->activity?->statement }}</span><small>{{ $row->counted($g['count']) }}</small></div>
                <div class="ap-tl">
                    @foreach ($g['timeline']['bars'] as $bar)
                        @if ($bar['single'])<span class="ap-o" style="inset-inline-start: {{ $bar['left'] }}%"></span>
                        @else<span class="ap-b" style="inset-inline-start: {{ $bar['left'] }}%; width: {{ $bar['width'] }}%"></span>@endif
                    @endforeach
                </div>
            </div>
        @endforeach
    @endforeach
</div>
