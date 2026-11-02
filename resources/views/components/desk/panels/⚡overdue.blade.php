<?php

use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Livewire\Concerns\Desk\ReadsDeskAgenda;
use App\Livewire\Concerns\Desk\ReadsDeskOverdue;
use Livewire\Component;

/**
 * Past the deadline: one list of everything the viewer is responsible for
 * whose date has passed (ReadsDeskOverdue, the Coming up agenda read
 * backwards), most late first, filterable by type.
 */
new class extends Component {
    use IsDeskPanel;
    use ReadsDashboardAttention;
    use ReadsDeskAgenda;
    use ReadsDeskOverdue;

    /**
     * @return array<string, string>
     */
    protected function facetsOf(mixed $row): array
    {
        return ['type' => __('desk-cards.overdue.kind.'.$row['kind'])];
    }
};
?>

@php
    $all = $this->overdueItems;
    $pg = $this->paginate($all, self::PerPage);
@endphp

<x-desk.panel :facets="$pg['facets']" id="overdue" class="dk-overdue" :size="$size" :cols="$gridCols" :height="$gridRows" :index="$index" :total="$total" :count="$all->count()" :health="$all->isNotEmpty() ? 'critical' : null">
    @if ($pg['total'] > 0)
        <ol class="dk-od" {{ $this->fitAttributes($pg) }}>
            @foreach ($pg['slice'] as $item)
                <li wire:key="od-{{ $item['key'] }}">
                    <span class="dk-od-kind" data-kind="{{ $item['kind'] }}">{{ __('desk-cards.overdue.kind.'.$item['kind']) }}</span>
                    <span class="dk-od-main">
                        <a href="{{ $item['href'] }}" wire:navigate class="dk-od-t" title="{{ $item['title'] }}">{{ $item['title'] }}</a>
                        <span class="dk-is" title="{{ $item['context'] }}">{{ $item['context'] }} · {{ __('desk-cards.overdue.was_due', ['date' => $item['date']->translatedFormat(__('formats.date'))]) }}</span>
                    </span>
                    <span class="dk-od-late dk-num">{{ trans_choice('desk.days_late', $item['daysLate'], ['count' => $item['daysLate']]) }}</span>
                </li>
            @endforeach
        </ol>
    @else
        <div class="dk-allclear">
            <span class="dk-allclear-dot" aria-hidden="true"></span>
            <p><strong>{{ __('desk-cards.overdue.clear') }}</strong><span class="dk-is">{{ $all->isEmpty() ? __('desk-cards.overdue.clear_hint') : __('desk-cards.overdue.empty_view') }}</span></p>
        </div>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
