{{-- A monthly update as a plain page for the browser's print-to-PDF (PlanUpdateExportController). --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $project->name }} · {{ $monthStart->translatedFormat('F Y') }}</title>
    <style>
        body { font-family: "Somar Sans", Tahoma, sans-serif; color: #232427; margin: 24px; font-size: 13px; line-height: 1.5; }
        h1 { font-size: 20px; margin: 0 0 4px; } h2 { font-size: 15px; margin: 18px 0 6px; border-bottom: 1px solid #dcddde; padding-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; } th, td { border: 1px solid #dcddde; padding: 5px 7px; text-align: start; vertical-align: top; }
        th { background: #f3f3f4; font-size: 11px; text-transform: uppercase; } p { margin: 0 0 6px; white-space: pre-line; } .muted { color: #6d6e71; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <p class="no-print"><button onclick="window.print()">{{ __('annual_plans.up.export_pdf') }}</button></p>
    <h1>{{ $project->name }} · {{ $monthStart->translatedFormat('F Y') }}</h1>
    <p class="muted">{{ $activityPlan->proposal->name }} · {{ $activityPlan->program?->name }} · {{ $update->status->label() }}</p>

    <h2>{{ __('annual_plans.up.part1') }}</h2>
    <table>
        <thead><tr><th>{{ __('annual_plans.wf.activity') }}</th>@foreach ($execution->calendar->mondaysOf($monthStart) as $monday)<th>{{ $monday->translatedFormat('j M') }}</th>@endforeach<th>{{ __('annual_plans.up.col_done', ['month' => $monthStart->translatedFormat('F')]) }}</th><th>{{ __('annual_plans.up.col_planned_batch') }}</th></tr></thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['item']->activity?->statement }} · {{ $row['item']->is_single_week ? __('annual_plans.wf.week_n', ['n' => $row['index'] + 1]) : __('annual_plans.wf.batch_n', ['n' => $row['index'] + 1]) }}</td>
                    @foreach ($execution->calendar->mondaysOf($monthStart) as $monday)
                        @php $week = $update->weeks->first(fn ($entry) => $entry->activity_plan_batch_id === $row['batch']->id && $entry->week->equalTo($monday)); @endphp
                        <td>{{ $week?->status->label() }}@if ($week?->reason)<br><span class="muted">{{ $week->reason }}</span>@endif</td>
                    @endforeach
                    <td>{{ (int) ($update->numbers->firstWhere('activity_plan_batch_id', $row['batch']->id)?->done_count ?? 0) }}</td>
                    <td>{{ $row['item']->counted((int) $row['batch']->planned_count) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="muted">{{ __('annual_plans.up.files_title') }}: {{ $update->files->pluck('original_name')->implode(', ') }}</p>

    <h2>{{ __('annual_plans.up.part2') }}</h2>
    <h3>{{ __('annual_plans.up.section_done') }}</h3><p>{{ $update->activities_implemented }}</p>
    <h3>{{ __('annual_plans.up.section_beneficiaries') }}</h3>
    <table><thead><tr><th>{{ __('annual_plans.up.male') }}</th><th>{{ __('annual_plans.up.female') }}</th><th>{{ __('annual_plans.up.total') }}</th><th>{{ __('annual_plans.up.with_disability') }}</th></tr></thead>
        <tbody><tr><td>{{ $update->males }}</td><td>{{ $update->females }}</td><td>{{ (int) $update->males + (int) $update->females }}</td><td>{{ $update->with_disability }}</td></tr></tbody></table>
    <h3>{{ __('annual_plans.up.section_challenges') }}</h3><p>{{ $update->challenges }}</p>
    <h3>{{ __('annual_plans.up.section_next') }}</h3><p>{{ $update->next_month }}</p>
    <h3>{{ __('annual_plans.up.section_accountability') }}</h3><p>{{ $update->accountability }}</p>
    @if ($update->program_manager_note)<h3>{{ __('annual_plans.up.pm_note_heading') }}</h3><p>{{ $update->program_manager_note }}</p>@endif
</body>
</html>
