{{-- The whole annual plan as a plain page for the browser's print-to-PDF (ActivityPlanExportController): the plan, then its execution per centre. --}}
@php
    $mondays = $execution->calendar->mondays();
    $months = collect($mondays)->groupBy(fn ($monday) => $monday->format('Y-m'));
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $plan->proposal->name }} · {{ $plan->program?->name }}</title>
    <style>
        body { font-family: "Somar Sans", Tahoma, sans-serif; color: #232427; margin: 24px; font-size: 12px; line-height: 1.45; }
        h1 { font-size: 20px; margin: 0 0 4px; } h2 { font-size: 15px; margin: 18px 0 6px; border-bottom: 1px solid #dcddde; padding-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; } th, td { border: 1px solid #dcddde; padding: 4px 6px; text-align: start; vertical-align: top; }
        th { background: #f3f3f4; font-size: 10px; text-transform: uppercase; } .muted { color: #6d6e71; }
        .wk { padding: 0; width: 10px; } .on { background: #2563a8; } .done { background: #1d7a4c; } .bad { background: #be1f24; } .warn { background: #d6941f; }
        @media print { .no-print { display: none; } @page { size: landscape; } }
    </style>
</head>
<body>
    <p class="no-print"><button onclick="window.print()">{{ __('annual_plans.up.export_pdf') }}</button></p>
    <h1>{{ $plan->proposal->name }}</h1>
    <p class="muted">{{ $plan->program?->name }} · {{ $plan->status->label() }} · {{ $execution->calendar->begin->translatedFormat(__('formats.date')) }} → {{ $execution->calendar->end->translatedFormat(__('formats.date')) }}</p>

    <h2>{{ __('annual_plans.export.plan_part') }}</h2>
    <table>
        <thead>
            <tr><th rowspan="2">{{ __('annual_plans.wf.activity') }}</th><th rowspan="2">{{ __('annual_plans.export.details') }}</th>
                @foreach ($months as $month => $weeks)<th colspan="{{ count($weeks) }}">{{ $weeks->first()->translatedFormat('M') }}</th>@endforeach</tr>
            <tr>@foreach ($mondays as $monday)<th class="wk">{{ $monday->day }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @foreach ($plan->items as $item)
                <tr>
                    <td>{{ $item->activity?->statement }}</td>
                    <td>{{ $item->counted((int) $item->batches->sum('planned_count')) }} · {{ implode(', ', array_values(array_intersect_key($cityNames, array_flip($item->cityIds())))) }}</td>
                    @foreach ($mondays as $monday)
                        <td class="wk {{ $item->batches->contains(fn ($b) => $monday->betweenIncluded($b->start_week, $b->end_week)) ? 'on' : '' }}"></td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($execution->projects() !== [])
        <h2>{{ __('annual_plans.export.execution_part') }}</h2>
        <table>
            <thead><tr><th>{{ __('annual_plans.wf.activity') }}</th><th>{{ __('annual_plans.up.col_centre') }}</th><th>{{ __('annual_plans.up.col_status') }}</th><th>{{ __('annual_plans.export.held') }}</th><th>{{ __('annual_plans.export.planned_so_far') }}</th>
                @foreach ($mondays as $monday)<th class="wk">{{ $monday->day }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($plan->items as $item)
                    @foreach ($execution->projects() as $centre)
                        @continue(! in_array(\App\Support\MealCoverage::cityIdOf($centre), $item->cityIds(), true))
                        @php
                            $status = $execution->status($centre, $item);
                            $entries = collect($execution->entriesFor($centre, $item))->groupBy(fn ($row) => $row['week']->toDateString());
                        @endphp
                        <tr>
                            <td>{{ $item->activity?->statement }}</td>
                            <td>{{ $centre->name }}</td>
                            <td>{{ $status['key'] === 'late' ? __('annual_plans.live.late_weeks', ['weeks' => $status['weeks']]) : __('annual_plans.live.status_'.$status['key']) }}</td>
                            <td>{{ $execution->held($centre, $item) }}</td>
                            <td>{{ $execution->plannedToDate($centre, $item) }}</td>
                            @foreach ($mondays as $monday)
                                @php $top = $entries->get($monday->toDateString(), collect())->sortBy(fn ($row) => $row['entry']->status->rank())->first(); @endphp
                                <td class="wk {{ $top === null ? '' : ($top['entry']->status->isFinish() ? 'done' : ($top['entry']->status->isActive() ? 'on' : ($top['entry']->status === \App\Enums\PlanWeekStatus::Suspended ? 'warn' : 'bad'))) }}" title="{{ $top ? $top['entry']->status->label() : '' }}"></td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
