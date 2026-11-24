{{--
    The side column of ⚡show.blade.php: who prepared, submitted, validated
    and saw the report and for which proposals; the template's other months
    of the year to step between; and the reopen requests.
--}}
@use('App\Enums\MonthlyReportStatus')
@use('App\Enums\ReopenRequestStatus')
@use('App\Support\MonthlyReportNames')

@php
    $asideNames = app(MonthlyReportNames::class)->forTemplates([$report->template])[$report->report_template_id];
    $asideDate = fn (\Carbon\CarbonInterface $moment): string => $moment->translatedFormat(__('formats.date'));
    $yearMonths = $this->yearReports->keyBy(fn ($sibling): int => $sibling->period->month);
@endphp

<aside class="mrp-aside">
    <section class="mrp-card mrp-side-card" aria-labelledby="report-about-heading">
        <h2 id="report-about-heading">{{ __('monthly_reports.about_report') }}</h2>
        <dl class="mrp-facts">
            <dt>{{ __('monthly_reports.about_place') }}</dt>
            <dd dir="auto">{{ $asideNames['place'] }}</dd>
            <dt>{{ __('monthly_reports.about_proposals') }}</dt>
            <dd>@include('pages.monthly-reports._covers-proposals', ['proposals' => $asideNames['proposals']])</dd>
            @if ($report->preparer !== null)
                <dt>{{ __('monthly_reports.about_prepared') }}</dt>
                <dd>{{ __('monthly_reports.prepared_by', ['name' => $report->preparer->name]) }}</dd>
            @endif
            @if ($report->submitted_at !== null && $report->submitter !== null)
                <dt>{{ __('monthly_reports.about_submitted') }}</dt>
                <dd class="tabular-nums">{{ __('monthly_reports.submitted_by_on', ['name' => $report->submitter->name, 'date' => $asideDate($report->submitted_at)]) }}</dd>
            @endif
            @if ($report->validated_at !== null && $report->validator !== null)
                <dt>{{ __('monthly_reports.about_validated') }}</dt>
                <dd class="tabular-nums">{{ __('monthly_reports.validated_by_on', ['name' => $report->validator->name, 'date' => $asideDate($report->validated_at)]) }}</dd>
            @endif
            @if ($report->seen_by_meal_at !== null || $report->status === MonthlyReportStatus::Submitted)
                <dt>{{ __('monthly_reports.about_seen') }}</dt>
                @if ($report->seen_by_meal_at !== null)
                    <dd class="inline-flex items-center gap-1" data-seen>
                        <flux:icon.eye variant="micro" class="size-3.5" aria-hidden="true" />
                        {{ __('monthly_reports.seen_on', ['date' => $asideDate($report->seen_by_meal_at)]) }}
                    </dd>
                @else
                    <dd>{{ __('monthly_reports.not_seen_yet') }}</dd>
                @endif
            @endif
        </dl>
    </section>

    <section class="mrp-card mrp-side-card" aria-labelledby="report-months-heading" data-year-months>
        <h2 id="report-months-heading">{{ __('monthly_reports.other_months', ['year' => $report->period->year]) }}</h2>
        <ol class="mrp-months">
            @foreach (range(1, 12) as $monthNumber)
                @php
                    $sibling = $yearMonths->get($monthNumber);
                    $monthDate = $report->period->setMonth($monthNumber)->startOfMonth();
                    $monthKind = match (true) {
                        $sibling === null => 'none',
                        $sibling->id === $report->id => 'this',
                        $sibling->status === MonthlyReportStatus::Validated => 'done',
                        $sibling->status === MonthlyReportStatus::Submitted => 'waiting',
                        default => 'draft',
                    };
                @endphp
                <li>
                    @if ($sibling !== null && $monthKind !== 'this')
                        <a href="{{ route('monthly-reports.show', $sibling) }}" wire:navigate @class(['mrp-month', 'mrp-month-'.$monthKind]) title="{{ $monthDate->translatedFormat('F Y') }}">{{ $monthDate->translatedFormat('M') }}</a>
                    @else
                        <span @class(['mrp-month', 'mrp-month-'.$monthKind]) title="{{ $monthDate->translatedFormat('F Y') }}" @if ($monthKind === 'this') aria-current="page" @endif>{{ $monthDate->translatedFormat('M') }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
        <p class="mrp-side-hint">{{ __('monthly_reports.other_months_hint') }}</p>
    </section>

@if ($report->reopenRequests->isNotEmpty())
    <section aria-labelledby="reopen-requests-heading" class="mrp-card mrp-side-card">
        <h2 id="reopen-requests-heading">{{ __('monthly_reports.reopen_requests') }}</h2>
        <ol class="flex flex-col divide-y divide-secondary-soft dark:divide-line" role="list">
            @foreach ($report->reopenRequests as $reopenRequest)
                <li class="flex flex-col gap-1 py-3" data-reopen-request="{{ $reopenRequest->id }}" wire:key="reopen-request-{{ $reopenRequest->id }}">
                    <p class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="font-bold">{{ $reopenRequest->requester?->name }}</span>
                        <span class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">{{ $reopenRequest->created_at?->translatedFormat(__('formats.date')) }}</span>
                        <span @class([
                            'inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-xs font-semibold',
                            'border-status-warn/30 bg-status-warn-soft text-status-warn' => $reopenRequest->status === ReopenRequestStatus::Pending,
                            'border-status-good/30 bg-status-good-soft text-status-good' => $reopenRequest->status === ReopenRequestStatus::Granted,
                            'border-secondary-light text-secondary-medium dark:border-line dark:text-ink-muted' => $reopenRequest->status === ReopenRequestStatus::Denied,
                        ])>{{ $reopenRequest->status->label() }}</span>
                    </p>
                    <p class="max-w-3xl text-sm whitespace-pre-line text-secondary-medium dark:text-ink-muted" dir="auto">{{ $reopenRequest->reason }}</p>
                    @if ($reopenRequest->decided_at !== null)
                        <p class="type-meta text-secondary-medium dark:text-ink-muted">
                            {{ __('monthly_reports.decided_by_on', ['name' => $reopenRequest->decider?->name ?? '—', 'date' => $reopenRequest->decided_at->translatedFormat(__('formats.date'))]) }}
                            @if (filled($reopenRequest->decision_note))
                                · <span dir="auto">{{ $reopenRequest->decision_note }}</span>
                            @endif
                        </p>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endif
</aside>
