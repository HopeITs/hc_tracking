{{--
    Where the report is, as three steps: prepared, submitted (or returned),
    validated, each with who and when. The step the report waits on is
    outlined; a reached step is filled. Included by ⚡show.blade.php.
--}}
@use('App\Enums\MonthlyReportStatus')

@php
    $journeyDate = fn (?\Carbon\CarbonInterface $moment): ?string => $moment?->translatedFormat(__('formats.date'));
    $isReturned = $report->status === MonthlyReportStatus::Draft && $report->returned_at !== null;
    $journey = [
        [
            'key' => 'prepared',
            'title' => __('monthly_reports.step_prepared'),
            'sub' => $report->preparer?->name ?? __('monthly_reports.step_not_started'),
            'reached' => $report->preparer !== null || $report->status !== MonthlyReportStatus::Draft,
            'current' => $report->status === MonthlyReportStatus::Draft && ! $isReturned,
        ],
        [
            'key' => 'submitted',
            'title' => $isReturned ? __('monthly_reports.step_returned') : __('monthly_reports.step_submitted'),
            'sub' => match (true) {
                $isReturned => __('monthly_reports.step_returned_on', ['date' => $journeyDate($report->returned_at)]),
                $report->submitted_at !== null => trim(($report->submitter?->name ?? '').' · '.$journeyDate($report->submitted_at), ' ·'),
                default => __('monthly_reports.step_not_yet'),
            },
            'reached' => $report->status !== MonthlyReportStatus::Draft,
            'current' => $report->status === MonthlyReportStatus::Submitted || $isReturned,
        ],
        [
            'key' => 'validated',
            'title' => __('monthly_reports.step_validated'),
            'sub' => $report->validated_at !== null
                ? trim(($report->validator?->name ?? '').' · '.$journeyDate($report->validated_at), ' ·')
                : __('monthly_reports.step_counts_once_validated'),
            'reached' => $report->status === MonthlyReportStatus::Validated,
            'current' => $report->status === MonthlyReportStatus::Validated,
        ],
    ];
@endphp

<ol class="mrp-journey" aria-label="{{ __('monthly_reports.journey_label') }}" data-journey>
    @foreach ($journey as $step)
        <li @class(['mrp-step', 'mrp-step-current' => $step['current'], 'mrp-step-reached' => $step['reached']]) data-journey-step="{{ $step['key'] }}" @if ($step['current']) aria-current="step" @endif>
            <span class="mrp-step-dot" aria-hidden="true">
                @if ($step['reached'] && ! $step['current'])
                    <flux:icon.check variant="micro" class="size-4" />
                @else
                    {{ $loop->iteration }}
                @endif
            </span>
            <span class="mrp-step-text">
                <b>{{ $step['title'] }}</b>
                <span dir="auto">{{ $step['sub'] }}</span>
            </span>
        </li>
    @endforeach
</ol>
