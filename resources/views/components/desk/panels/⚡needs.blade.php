<?php

use App\Actions\Tasks\AcceptDepartmentTask;
use App\Enums\DeliveryState;
use App\Livewire\Concerns\Dashboard\ReadsDeskDecisions;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Task;
use Flux\Flux;
use Livewire\Component;

/**
 * Needs you: the decisions waiting on the viewer (ReadsDeskDecisions), with
 * who is waiting and for how long, and the button that settles each one.
 * The quick views are This week (default: late, due by the end of the
 * week, or undated), Late and All.
 */
new class extends Component {
    use IsDeskPanel;
    use ReadsDeskDecisions;

    public function mount(): void
    {
        $this->filter = 'week';
    }

    /**
     * Accept a delivered deliverable from the row. The row is read back from
     * decisions(), and AcceptDepartmentTask re-checks the policy.
     */
    public function accept(int $taskId, AcceptDepartmentTask $accept): void
    {
        $row = $this->decisions->first(fn (array $item): bool => $item['kind'] === 'accept' && $item['taskId'] === $taskId);

        if ($row === null) {
            return;
        }

        $accept->handle(Task::query()->findOrFail($taskId), DeliveryState::Delivered);
        unset($this->decisions);
        Flux::toast(text: __('desk.decision_done.accept'), variant: 'success');
    }
};
?>

@php
    $all = $this->decisions;
    $list = $this->decisionsIn($filter);
    $pg = $this->paginate($list, self::PerPage);
    $today = \Carbon\CarbonImmutable::today();
@endphp

<x-desk.panel :facets="$pg['facets']" id="needs" class="dk-needs" :size="$size" :index="$index" :total="$total" :count="$all->count()">
    <x-slot:tools>
        <div class="dk-seg" role="group">
            @foreach (['week' => __('desk.filter_week'), 'late' => __('desk.filter_late'), 'all' => __('desk.filter_all')] as $key => $label)
                <button type="button" wire:click="setFilter('{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
    </x-slot:tools>

    @if ($pg['total'] > 0)
        <ol class="dk-items" {{ $this->fitAttributes($pg) }}>
            @foreach ($pg['slice'] as $item)
                @php
                    $waited = $item['since'] === null ? null : (int) $item['since']->startOfDay()->diffInDays($today);
                @endphp
                <li wire:key="{{ $item['key'] }}" data-decision="{{ $item['kind'] }}">
                    <span @class(['dk-sev', 'dk-bad' => $item['late'], 'dk-warn' => ! $item['late']])></span>
                    <div class="dk-im">
                        <p class="dk-it" title="{{ $item['title'] }}">{{ $item['title'] }}</p>
                        <p class="dk-is">{{ $item['context'] }}@if ($item['who'] !== '') · <span class="dk-who">{{ $waited === null ? $item['who'] : trans_choice('desk.decision_waiting', $waited, ['who' => $item['who'], 'count' => $waited]) }}</span>@elseif ($waited !== null) · {{ trans_choice('desk.decision_waited', $waited, ['count' => $waited]) }}@endif @if ($item['due'] !== null) · <span @class(['dk-bad' => $item['late']])>{{ __('desk.due_on', ['date' => $item['due']->translatedFormat(__('formats.date'))]) }}</span>@endif</p>
                    </div>
                    <div class="dk-acts">
                        @if ($item['kind'] === 'accept')
                            <button type="button" class="dk-btn dk-sm dk-primary" wire:click="accept({{ $item['taskId'] }})">{{ $item['action'] }}</button>
                            <a href="{{ $item['href'] }}" wire:navigate class="dk-btn dk-sm">{{ __('desk.open') }}</a>
                        @else
                            <a href="{{ $item['href'] }}" wire:navigate @class(['dk-btn dk-sm', 'dk-primary' => $item['late']])>{{ $item['action'] }}</a>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @else
        <div class="dk-allclear">
            <span class="dk-allclear-dot" aria-hidden="true"></span>
            <p><strong>{{ __('desk.panel.needs.clear') }}</strong><span class="dk-is">{{ $all->isEmpty() ? __('desk.panel.needs.empty') : __('desk.panel.needs.empty_view') }}</span></p>
        </div>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
