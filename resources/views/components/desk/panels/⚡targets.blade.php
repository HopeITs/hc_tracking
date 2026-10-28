<?php

use App\Enums\RagStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Indicator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Targets behind: indicators rated amber or red against this year
 * (Indicator::ragStatus(), the indicators page's "off track"), worst first,
 * each with its progress bar and a tick where it should be by now.
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * @return Collection<int, array{indicator: Indicator, achieved: float, expected: float, latest: float|null, rag: RagStatus}>
     */
    #[Computed]
    public function rows(): Collection
    {
        if (! $this->canView('targets') || ! $this->canView('indicators')) {
            return new Collection;
        }

        $start = now()->startOfYear();
        $end = now()->endOfYear();
        $expected = min(100.0, max(0.0, ($start->diffInSeconds(now()) / $start->diffInSeconds($end)) * 100));

        return Indicator::query()
            ->select(['id', 'name', 'code', 'project_id', 'measure', 'unit', 'baseline', 'target'])
            ->whereNotNull('target')
            ->with(['project:id,name', 'progress:id,indicator_id,date,value'])
            ->get()
            ->map(fn (Indicator $indicator): ?array => ($rag = $indicator->ragStatus($start, $end)) === null || $rag === RagStatus::Green ? null : [
                'indicator' => $indicator,
                'achieved' => (float) $indicator->percentAchieved(),
                'expected' => $expected,
                'latest' => $indicator->latestValue(),
                'rag' => $rag,
            ])
            ->filter()
            ->sortBy([fn (array $a, array $b): int => ($a['rag'] === RagStatus::Red ? 0 : 1) <=> ($b['rag'] === RagStatus::Red ? 0 : 1), fn (array $a, array $b): int => $a['achieved'] <=> $b['achieved']])
            ->values();
    }

    /**
     * Project and rating, for the filter popup.
     *
     * @return array<string, string>
     */
    protected function facetsOf(mixed $row): array
    {
        return array_filter([
            'project' => $row['indicator']->project?->name,
            'status' => $row['rag']->label(),
        ]);
    }
};
?>

@php
    $rows = $this->rows;
    $pg = $this->paginate($rows, self::PerPage);
    $number = fn (?float $value): string => $value === null ? '—' : rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
@endphp

<x-desk.panel :facets="$pg['facets']" id="targets" register="targets.index" :size="$size" :index="$index" :total="$total" :count="$rows->count()">
    @if ($pg['total'] > 0)
        <ul class="dk-tg" {{ $this->fitAttributes($pg) }}>
            @foreach ($pg['slice'] as $row)
                @php
                    $indicator = $row['indicator'];
                    $unit = $indicator->unit ?? '';
                @endphp
                <li wire:key="ind-{{ $indicator->id }}">
                    <span class="dk-tg-n" title="{{ $indicator->name }}@if ($indicator->project !== null) · {{ $indicator->project->name }}@endif">@if (filled($indicator->code))<span class="dk-mono">{{ $indicator->code }}</span> @endif{{ $indicator->name }}@if ($indicator->project !== null)<span class="dk-is"> · {{ $indicator->project->name }}</span>@endif</span>
                    <span @class(['dk-pill dk-tg-s', 'dk-bad' => $row['rag'] === RagStatus::Red, 'dk-warn' => $row['rag'] === RagStatus::Amber])>{{ $row['rag']->label() }}</span>
                    <span class="dk-tg-b" title="{{ __('desk.plans_bar', ['done' => (int) round($row['achieved']), 'expected' => (int) round($row['expected'])]) }}"><span class="dk-meter dk-res"><b style="width:{{ round(min(100, max(0, $row['achieved'])), 1) }}%"></b><span class="dk-tick" style="inset-inline-start:{{ round($row['expected'], 1) }}%"></span></span></span>
                    <span class="dk-tg-v dk-num"><strong>{{ $number($row['latest']) }}</strong> / {{ $number((float) $indicator->target) }}@if ($unit !== '') {{ $unit }}@endif · {{ (int) round($row['achieved']) }}%</span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="dk-pempty">{{ __('desk.panel.targets.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
