{{-- The header of ⚡show.blade.php: project, month, state, who prepared, submitted and validated it, whether MEAL has seen it, and the return reason. --}}
@use('App\Enums\MonthlyReportStatus')
@use('App\Enums\ReopenRequestStatus')
@use('App\Support\MonthlyReportPeriods')

@php
    $headerProject = $report->project;
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
    :breadcrumbs="[
        ['label' => __('titles.monthly_reports'), 'url' => route('monthly-reports.index')],
        ['label' => $headerProject->name.' · '.$monthLabel],
    ]"
    :title="$monthLabel"
    :subtitle="$headerProject->name"
    :unread="app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'monthly_report', $report->id)"
    title-class="text-3xl! font-extrabold! leading-none! tracking-tight!"
>
    <x-slot:meta>
        <x-monthly-reports.state-chip :state="$headerState" />
        @can('projects.view')
            <a href="{{ route('projects.show', $headerProject) }}" wire:navigate class="hover:underline" dir="auto">{{ $headerProject->name }}</a>
        @else
            <span dir="auto">{{ $headerProject->name }}</span>
        @endcan
        @if ($report->preparer !== null)
            <span>{{ __('monthly_reports.prepared_by', ['name' => $report->preparer->name]) }}</span>
        @endif
        @if ($report->submitted_at !== null && $report->submitter !== null)
            <span class="tabular-nums">{{ __('monthly_reports.submitted_by_on', ['name' => $report->submitter->name, 'date' => $formatMoment($report->submitted_at)]) }}</span>
        @endif
        @if ($report->validated_at !== null && $report->validator !== null)
            <span class="tabular-nums">{{ __('monthly_reports.validated_by_on', ['name' => $report->validator->name, 'date' => $formatMoment($report->validated_at)]) }}</span>
        @endif
        @if ($report->seen_by_meal_at !== null)
            <span class="inline-flex items-center gap-1 text-status-good" data-seen>
                <flux:icon.eye variant="micro" class="size-3.5" aria-hidden="true" />
                {{ __('monthly_reports.seen_on', ['date' => $formatMoment($report->seen_by_meal_at)]) }}
            </span>
        @elseif ($report->status !== MonthlyReportStatus::Draft)
            <span>{{ __('monthly_reports.not_seen_yet') }}</span>
        @endif
    </x-slot:meta>
</x-ui.detail-header>

@if ($headerState === 'returned' && filled($report->return_reason))
    <div class="flex gap-3 border-s-2 border-status-warn ps-4" role="note">
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

@if (MonthlyReportPeriods::ownerCenterMismatch($headerProject))
    <div class="flex gap-3 border-s-2 border-status-warn ps-4" role="note" data-owner-center-warning>
        <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-4 shrink-0 text-status-warn" aria-hidden="true" />
        <p class="text-sm text-secondary-medium dark:text-ink-muted">{{ __('monthly_reports.owner_center_mismatch') }}</p>
    </div>
@endif
