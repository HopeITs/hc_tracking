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
    $pg = $this->paginate($rows, 5);
@endphp

<x-desk.panel id="validate" register="monthly-reports.index" :size="$size" :index="$index" :total="$total" :count="$rows->count()">
    @if ($pg['total'] > 0)
        <ul class="dk-plist">
            @foreach ($pg['slice'] as $report)
                <li wire:key="v-{{ $report->id }}">
                    <div>
                        <strong>{{ $report->project?->name ?? '—' }}</strong>
                        <span class="dk-is">{{ $report->period->translatedFormat('F Y') }}@if ($report->preparer !== null) · {{ $report->preparer->name }}@endif @if ($report->submitted_at !== null) · {{ \App\Support\Desk\RelativeDay::for($report->submitted_at) }}@endif</span>
                    </div>
                    <a href="{{ route('monthly-reports.show', $report) }}" wire:navigate class="dk-btn dk-sm dk-primary">{{ __('desk.validate') }}</a>
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
