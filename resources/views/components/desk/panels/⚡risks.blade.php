<?php

use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Risk;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Risks: a 3×3 heat map of the rated risks (likelihood up, severity across,
 * user decision 2026-10-22), then the list: open risks first, then the ones
 * that happened, worst score first in each group. A square filters the list
 * to its risks; clicking it again clears the filter. Unrated risks stay in the
 * list and off the map.
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
            ->select(['id', 'name', 'project_id', 'happened', 'likelihood', 'severity', 'created_at'])
            ->with('project:id,name')
            ->orderBy('happened')
            ->latest()
            ->orderByDesc('id')
            ->get()
            ->sortBy([
                fn (Risk $a, Risk $b): int => (int) $a->happened <=> (int) $b->happened,
                fn (Risk $a, Risk $b): int => ($b->heatScore() ?? 0) <=> ($a->heatScore() ?? 0),
            ])
            ->values();
    }

    /**
     * How many rated risks sit in each square, keyed "likelihood:severity".
     *
     * @return array<string, int>
     */
    #[Computed]
    public function heat(): array
    {
        return $this->rows
            ->filter(fn (Risk $risk): bool => $risk->heatScore() !== null)
            ->countBy(fn (Risk $risk): string => $risk->likelihood.':'.$risk->severity)
            ->all();
    }
};
?>

@php
    $rows = $this->rows;
    $cellFilter = preg_match('/^[1-3]:[1-3]$/', $filter) === 1 ? $filter : null;
    $shown = $cellFilter === null ? $rows : $rows->filter(fn ($risk): bool => $risk->likelihood.':'.$risk->severity === $cellFilter)->values();
    $pg = $this->paginate($shown, 3);
    $tone = fn (?int $score): string => match (true) {
        $score === null => '',
        $score >= 6 => 'dk-high',
        $score >= 4 => 'dk-med',
        default => 'dk-low',
    };
@endphp

<x-desk.panel id="risks" register="risks.index" :size="$size" :index="$index" :total="$total" :count="$rows->where('happened', false)->count()">
    @if ($rows->isNotEmpty())
        <div class="dk-heat" data-risk-heat>
            <span class="dk-ax-y">{{ __('risks.likelihood') }}</span>
            <div class="dk-hgrid">
                @foreach ([3, 2, 1] as $likelihood)
                    @foreach ([1, 2, 3] as $severity)
                        @php
                            $key = $likelihood.':'.$severity;
                            $count = $this->heat[$key] ?? 0;
                            $score = $likelihood * $severity;
                        @endphp
                        <button
                            type="button"
                            @class(['dk-hm', $score >= 6 ? 'dk-s3' : ($score >= 4 ? 'dk-s2' : 'dk-s1'), 'dk-on' => $cellFilter === $key])
                            wire:click="setFilter('{{ $cellFilter === $key ? 'all' : $key }}')"
                            aria-pressed="{{ $cellFilter === $key ? 'true' : 'false' }}"
                            aria-label="{{ __('desk.risk_cell', ['likelihood' => $likelihood, 'severity' => $severity, 'count' => $count]) }}"
                            data-risk-cell="{{ $key }}"
                        >{{ $count > 0 ? $count : '' }}</button>
                    @endforeach
                @endforeach
            </div>
            <span class="dk-ax-x">{{ __('risks.severity') }}</span>
        </div>
    @endif

    @if ($pg['total'] > 0)
        <ul class="dk-risks">
            @foreach ($pg['slice'] as $risk)
                <li wire:key="r-{{ $risk->id }}">
                    <span class="dk-riskdots"><i class="{{ $tone($risk->heatScore()) ?: ($risk->happened ? 'dk-high' : 'dk-med') }}"></i></span>
                    <span>{{ $risk->name }} <span class="dk-is">· {{ $risk->project?->name ?? '—' }}@if ($risk->happened) · {{ __('desk.happened') }}@endif @if ($risk->heatScore() === null) · {{ __('risks.not_rated') }}@endif</span></span>
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
