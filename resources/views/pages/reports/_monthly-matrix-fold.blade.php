{{--
    The fold of one Monthly Matrix row: who was reached (the by-sex breakdown,
    D-50), the latest monthly reports, the next step, and the proposals and
    donors behind the row. Rendered with the row and shown by its Alpine
    `open` flag, so opening it never waits on the server. Included by
    _monthly-matrix-row.blade.php, which defines $row, $progress and
    $monthFormat.
--}}
@php
    $nextMonth = $progress['lastMissing'] ?? $progress['lastWaiting'];
    $nextUrl = $nextMonth === null ? null : $this->cellReportUrl($row, $nextMonth);
    $kindLabels = [
        'done' => __('reports.matrix.legend_done'),
        'waiting' => __('reports.matrix.legend_waiting'),
        'missing' => __('enums.matrix_cell_state.not_submitted'),
    ];
@endphp

<div class="mm-fold-grid">
    @if ($row['breakdown'] !== null)
        <section class="mm-panel mm-panel-wide">
            <h3>{{ __('reports.matrix.fold_reached') }}</h3>
            @include('pages.reports._monthly-matrix-breakdown')
        </section>
    @endif

    <section class="mm-panel">
        <h3>{{ __('reports.matrix.fold_reports') }}</h3>
        @forelse ($progress['recent'] as $recent)
            @php
                $recentUrl = $this->cellReportUrl($row, $recent['month']);
            @endphp
            <a @if ($recentUrl !== null) href="{{ $recentUrl }}" wire:navigate @endif class="mm-report-link">
                <b>{{ $recent['month']->translatedFormat($monthFormat) }}</b>
                <span @class(['mm-report-state', 'mm-report-missing' => $recent['kind'] === 'missing'])>{{ $kindLabels[$recent['kind']] }}</span>
            </a>
        @empty
            <p class="mm-panel-text">{{ __('reports.matrix.no_reports_yet') }}</p>
        @endforelse
    </section>

    <section class="mm-panel">
        <h3>{{ __('reports.matrix.fold_next') }}</h3>
        @if ($progress['lastMissing'] !== null)
            <p class="mm-panel-text">{{ __('reports.matrix.next_missing', ['month' => $nextMonth->translatedFormat($monthFormat)]) }}</p>
            @if ($nextUrl !== null)
                <a href="{{ $nextUrl }}" wire:navigate class="dk-btn dk-sm dk-primary">{{ __('reports.matrix.next_missing_action', ['month' => $nextMonth->translatedFormat($monthFormat)]) }}</a>
            @endif
        @elseif ($progress['lastWaiting'] !== null)
            <p class="mm-panel-text">{{ __('reports.matrix.next_waiting', ['month' => $nextMonth->translatedFormat($monthFormat)]) }}</p>
            @if ($nextUrl !== null)
                <a href="{{ $nextUrl }}" wire:navigate class="dk-btn dk-sm dk-primary">{{ __('reports.matrix.next_waiting_action', ['month' => $nextMonth->translatedFormat($monthFormat)]) }}</a>
            @endif
        @else
            <p class="mm-panel-text">{{ __('reports.matrix.next_none') }}</p>
        @endif
    </section>

    <section class="mm-panel">
        <h3>{{ __('reports.matrix.fold_sources') }}</h3>
        @foreach ($row['sources'] as $source)
            <div class="mm-source">
                @if ($source['proposal'] !== null)
                    @can('proposals.view')
                        <a href="{{ route('proposals.show', $source['proposal_slug']) }}" wire:navigate dir="auto">{{ $source['proposal'] }}</a>
                    @else
                        <span dir="auto">{{ $source['proposal'] }}</span>
                    @endcan
                @else
                    <span>{{ __('reports.matrix.annual_plan') }}</span>
                @endif
                @if ($source['donor'] !== null)
                    @can('donors.view')
                        <a href="{{ route('donors.show', $source['donor_slug']) }}" wire:navigate dir="auto" class="mm-source-donor">{{ $source['donor'] }}</a>
                    @else
                        <span dir="auto" class="mm-source-donor">{{ $source['donor'] }}</span>
                    @endcan
                @endif
            </div>
        @endforeach
    </section>
</div>
