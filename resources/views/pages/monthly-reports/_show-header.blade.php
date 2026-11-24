{{-- The header of ⚡show.blade.php: the template's place and the proposals it covers (Y-03, D-76: never the project), month, state and the return reason. Who prepared, submitted and validated it sits in the journey and the side column. --}}
@use('App\Enums\MonthlyReportStatus')
@use('App\Enums\ReopenRequestStatus')
@use('App\Support\MonthlyReportNames')
@use('App\Support\MonthlyReportPreparers')

@php
    $headerNames = app(MonthlyReportNames::class)->forTemplates([$report->template])[$report->report_template_id];
    $monthLabel = $report->period->translatedFormat('F Y');
    $hasPendingReopen = $report->reopenRequests->contains('status', ReopenRequestStatus::Pending);
    $headerState = match ($report->status) {
        MonthlyReportStatus::Validated => $hasPendingReopen ? 'reopen_pending' : 'validated',
        MonthlyReportStatus::Submitted => 'submitted',
        MonthlyReportStatus::Draft => match (true) {
            ! $this->periods->hasStarted($report->period) => 'upcoming',
            $report->returned_at !== null => 'returned',
            default => 'draft',
        },
    };
    $formatMoment = fn (\Carbon\CarbonInterface $moment): string => $moment->translatedFormat(__('formats.date'));
@endphp

<x-ui.detail-header
        :back="\App\Support\BackUrl::for(route('monthly-reports.index'))"
    :breadcrumbs="[
        ['label' => __('titles.monthly_reports'), 'url' => route('monthly-reports.index')],
        ['label' => $headerNames['place']],
        ['label' => $monthLabel],
    ]"
    :title="$monthLabel"
    :subtitle="$headerNames['place']"
    :unread="app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'monthly_report', $report->id)"
>
    {{-- The place is the subtitle; the proposals line tells two reports of one place and month apart. --}}
    <x-slot:meta>
        <div class="basis-full">@include('pages.monthly-reports._covers-proposals', ['proposals' => $headerNames['proposals']])</div>
        <x-monthly-reports.state-chip :state="$headerState" />
    </x-slot:meta>
</x-ui.detail-header>

@if ($headerState === 'returned' && filled($report->return_reason))
    <div class="mrp-card mrp-note" role="note">
        <flux:icon.arrow-uturn-left variant="mini" class="mt-0.5 size-4 shrink-0 text-status-warn rtl:-scale-x-100" aria-hidden="true" />
        <div>
            <p class="text-sm font-bold">
                {{ $report->returner !== null
                    ? __('monthly_reports.returned_by_on', ['name' => $report->returner->name, 'date' => $formatMoment($report->returned_at)])
                    : __('monthly_reports.returned_on', ['date' => $formatMoment($report->returned_at)]) }}
            </p>
            <p class="mt-1 max-w-3xl text-sm whitespace-pre-line text-secondary-medium dark:text-ink-muted" dir="auto">{{ $report->return_reason }}</p>
        </div>
    </div>
@endif

@if (app(MonthlyReportPreparers::class)->centerMismatch($report->template))
    <div class="mrp-card mrp-note" role="note" data-owner-center-warning>
        <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-4 shrink-0 text-status-warn" aria-hidden="true" />
        <p class="text-sm text-secondary-medium dark:text-ink-muted">{{ __('monthly_reports.owner_center_mismatch') }}</p>
    </div>
@endif
