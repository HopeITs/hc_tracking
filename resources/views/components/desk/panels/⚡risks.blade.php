<?php

use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Risk;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Risks: open risks first, then the ones that happened, newest first in
 * each group. Real risks carry no likelihood or score, so the artifact's
 * heatmap is left out (user decision, .ai/rules/desk.md).
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * @return Collection<int, Risk>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->visible(Risk::query(), 'risks')
            ->select(['id', 'name', 'project_id', 'happened', 'created_at'])
            ->with('project:id,name')
            ->orderBy('happened')
            ->latest()
            ->orderByDesc('id')
            ->get();
    }
};
?>

@php
    $rows = $this->rows;
    $pg = $this->paginate($rows, 5);
@endphp

<x-desk.panel id="risks" register="risks.index" :size="$size" :index="$index" :total="$total" :count="$rows->where('happened', false)->count()">
    @if ($pg['total'] > 0)
        <ul class="dk-risks">
            @foreach ($pg['slice'] as $risk)
                <li wire:key="r-{{ $risk->id }}">
                    <span class="dk-riskdots"><i class="{{ $risk->happened ? 'dk-high' : 'dk-med' }}"></i></span>
                    <span>{{ $risk->name }} <span class="dk-is">· {{ $risk->project?->name ?? '—' }}@if ($risk->happened) · {{ __('desk.happened') }}@endif</span></span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="dk-pempty">{{ __('desk.panel.risks.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
