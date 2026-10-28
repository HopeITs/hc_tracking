<?php

use App\Enums\MonthlyReportStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\MonthlyReport;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * To validate: submitted monthly reports the viewer may validate
 * (MonthlyReport::validatableBy), oldest submission first.
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * @return Collection<int, MonthlyReport>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->visible(MonthlyReport::query(), 'reports')
            ->validatableBy(auth()->user())
            ->where('status', MonthlyReportStatus::Submitted)
            ->with(['project:id,name', 'preparer:id,hr_user_id'])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();
    }
};
?>

@php
    $rows = $this->rows;
    $pg = $this->paginate($rows, self::PerPage);
@endphp

<x-desk.panel :facets="$pg['facets']" id="validate" register="monthly-reports.index" :size="$size" :index="$index" :total="$total" :count="$rows->count()">
    @if ($pg['total'] > 0)
        <ul class="dk-rv">
            @foreach ($pg['slice'] as $report)
                @php
                    $waited = $report->submitted_at === null ? 0 : (int) $report->submitted_at->startOfDay()->diffInDays(\Carbon\CarbonImmutable::today());
                @endphp
                <li wire:key="v-{{ $report->id }}">
                    <span class="dk-rv-n" title="{{ $report->project?->name }}">{{ $report->project?->name ?? '—' }}</span>
                    <span class="dk-pill dk-act dk-rv-s">{{ $report->period->translatedFormat('M Y') }}</span>
                    <span @class(['dk-rv-m dk-is', 'dk-bad' => $waited > 7])>@if ($report->preparer !== null){{ $report->preparer->name }} · @endif{{ $report->submitted_at === null ? '—' : __('desk.sent_on', ['date' => \App\Support\Desk\RelativeDay::for($report->submitted_at)]) }}@if ($waited > 7) · {{ __('desk.review_waiting_long') }}@endif</span>
                    <a href="{{ route('monthly-reports.show', $report) }}" wire:navigate class="dk-btn dk-sm dk-primary dk-rv-a">{{ __('desk.validate') }}</a>
                </li>
            @endforeach
        </ul>
    @else
        <p class="dk-pempty">{{ __('desk.panel.validate.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
