<?php

use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Support\Reporting\ActiveReports;
use App\Support\Reporting\ReportTrackerDoors;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Report Tracker in panel form: the page's doors (Monthly reports · My
 * reporting duties · Donor reports) as compact links, counted by
 * ReportTrackerDoors exactly as the page counts them, so a door says what
 * clicking it opens. Rows come from ActiveReports (each record's own rules).
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * @return list<string>
     */
    #[Computed]
    public function doors(): array
    {
        return ReportTrackerDoors::offered(ReportTrackerDoors::kinds(auth()->user()));
    }

    /**
     * @return array{duties: array{total: int, late: int, check: int}, monthly: array{needs: int, late: int, open: int, done: int}, donor: array{needs: int, late: int, open: int, done: int}}
     */
    #[Computed]
    public function counts(): array
    {
        $reports = app(ActiveReports::class);

        return ReportTrackerDoors::counts($reports->for(auth()->user()), $reports->for(auth()->user(), closed: true));
    }
};
?>

@php
    $counts = $this->counts;
    $doors = $this->doors;
    $late = $counts['monthly']['late'] + $counts['duties']['late'] + $counts['donor']['late'];
    $headline = in_array('duties', $doors, true) ? $counts['duties']['total'] : $counts['monthly']['needs'];
    $nothing = $counts['duties']['total'] === 0 && $counts['monthly']['open'] === 0 && $counts['donor']['open'] === 0;
    /* Each door: icon, title, the big number and its word, then late + two plain figures. */
    $spec = [
        'monthly' => ['icon' => 'cal', 'big' => $counts['monthly']['needs'], 'word' => __('active_reports.wait_on_you'), 'late' => $counts['monthly']['late'], 'stats' => [[$counts['monthly']['open'], __('active_reports.stat_open')], [$counts['monthly']['done'], __('active_reports.stat_done')]]],
        'duties' => ['icon' => 'check', 'big' => $counts['duties']['total'], 'word' => __('active_reports.to_do'), 'late' => $counts['duties']['late'], 'stats' => [[$counts['duties']['check'], __('active_reports.stat_check')], [$counts['duties']['total'] - $counts['duties']['check'], __('active_reports.stat_fill_send')]]],
        'donor' => ['icon' => 'file', 'big' => $counts['donor']['needs'], 'word' => __('active_reports.wait_on_you'), 'late' => $counts['donor']['late'], 'stats' => [[$counts['donor']['open'], __('active_reports.stat_open')], [$counts['donor']['done'], __('active_reports.stat_sent')]]],
    ];
@endphp

<x-desk.panel id="tracker" register="active-reports.index" :empty="$nothing" :filterable="false" :health="$late > 0 ? 'critical' : null" :size="$size" :cols="$gridCols" :height="$gridRows" :index="$index" :total="$total" :count="$headline">
    <nav class="dk-trk" data-doors="{{ count($doors) }}" aria-label="{{ __('desk.panel.tracker.title') }}">
        <ul class="dk-trk-doors">
            @foreach ($doors as $kind)
                @php $door = $spec[$kind]; @endphp
                <li @class(['dk-trk-li', 'is-duties' => $kind === 'duties']) wire:key="trk-{{ $kind }}">
                    <a href="{{ route('active-reports.index', ['kind' => $kind]) }}" wire:navigate @class(['dk-trk-door', 'is-late' => $door['late'] > 0]) data-tracker-panel-door="{{ $kind }}" data-door-count="{{ $door['big'] }}">
                        <span class="dk-trk-ic" aria-hidden="true"><x-desk.icon :name="$door['icon']" /></span>
                        <span class="dk-trk-title">{{ __("active_reports.kind_{$kind}") }}</span>
                        <span class="dk-trk-big"><b class="dk-num">{{ $door['big'] }}</b><span>{{ $door['word'] }}</span></span>
                        <span class="dk-trk-stats">
                            @if ($door['late'] > 0)
                                <span class="dk-trk-late"><b class="dk-num">{{ $door['late'] }}</b> {{ __('active_reports.stat_late') }}</span>
                            @endif
                            @foreach ($door['stats'] as [$figure, $word])
                                <span><b class="dk-num">{{ $figure }}</b> {{ $word }}</span>
                            @endforeach
                        </span>
                        <span class="dk-trk-go" aria-hidden="true"><x-desk.icon name="arrow" class="dk-flip" /></span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
</x-desk.panel>
