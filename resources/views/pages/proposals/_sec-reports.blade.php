{{--
    "Reports" section of proposals/show (stage 3b): a tally (filed, in
    review, approved, still owed, late), the reports filed against this
    proposal's targets (owed first, four shown then "Show all"), and the
    donor reports owed on its agreement (money viewers only, as before).
    Every row opens the existing report page; nothing is written here.
    Included by _show-more.blade.php.
--}}
@use('App\Enums\ReportStatus')
@use('App\Models\Report')
@php
    $today = \Carbon\CarbonImmutable::today();
    $reportList = $this->reports->sortBy([
        fn (Report $a, Report $b): int => ! $this->reportIsOwed($a) <=> ! $this->reportIsOwed($b),
        fn (Report $a, Report $b): int => $a->planned_date <=> $b->planned_date,
        fn (Report $a, Report $b): int => $a->id <=> $b->id,
    ])->values();
    $owed = $reportList->filter(fn (Report $report): bool => $this->reportIsOwed($report));
    $lateCount = $owed->filter(fn (Report $report): bool => $report->planned_date->lt($today))->count();
    $filed = $reportList->reject(fn (Report $report): bool => $this->reportIsOwed($report));
    // Donor reports count as filed once sent and as owed until then, so the
    // tally agrees with the section's summary line; review stages are target reports only.
    $donorSent = $this->donorReports->filter->isSubmitted()->count();
    $donorLate = $this->donorReports->filter->isOverdue()->count();
    $donorOwed = $this->donorReports->count() - $donorSent;
    $tally = [
        ['key' => 'filed', 'label' => __('proposals.sections.reports_filed'), 'count' => $filed->count() + $donorSent, 'hot' => false],
        ['key' => 'review', 'label' => __('proposals.in_review'), 'count' => $filed->where('status', '!==', ReportStatus::Approved)->count(), 'hot' => false],
        ['key' => 'approved', 'label' => ReportStatus::Approved->label(), 'count' => $filed->where('status', ReportStatus::Approved)->count(), 'hot' => false],
        ['key' => 'owed', 'label' => __('proposals.sections.reports_owed'), 'count' => ($owed->count() - $lateCount) + ($donorOwed - $donorLate), 'hot' => false],
        ['key' => 'late', 'label' => __('proposals.sections.reports_late'), 'count' => $lateCount + $donorLate, 'hot' => $lateCount + $donorLate > 0],
    ];
    $statusClass = [
        ReportStatus::Approved->value => 'pp-st-done',
    ];
@endphp

<div class="pp-tally" data-report-tally>
    @foreach ($tally as $item)
        <div @class(['pp-hot' => $item['hot']]) data-report-tally-item="{{ $item['key'] }}">
            <b>{{ $item['count'] }}</b>
            <span class="pp-small">{{ $item['label'] }}</span>
        </div>
    @endforeach
</div>

@if ($reportList->isEmpty() && $this->donorReports->isEmpty())
    <div class="pp-empty">{{ __('proposals.sections.reports_empty') }}</div>
@elseif ($reportList->isEmpty())
    <span class="pp-small">{{ __('proposals.sections.no_target_reports') }}</span>
@else
    <div x-data="{ all: false }" data-report-list>
        @foreach ($reportList as $report)
            @php
                $isOwed = $this->reportIsOwed($report);
                $isLate = $isOwed && $report->planned_date->lt($today);
            @endphp
            <div class="pp-lrow" wire:key="sec-report-{{ $report->id }}" data-report="{{ $report->id }}" @if ($loop->index >= 4) x-show="all" x-cloak @endif>
                <span class="pp-lrow-s">
                    <a href="{{ route('reports.show', $report) }}" wire:navigate dir="auto"><b>{{ $report->planIndicator->indicator->name }}</b></a>
                    <span class="pp-small">
                        <span dir="auto">{{ $report->planIndicator->project?->name ?? __('common.organisation_wide') }}</span> ·
                        @if ($isOwed)
                            {{ __('proposals.sections.due_on', ['date' => $report->planned_date->translatedFormat(__('formats.date'))]) }}
                            @if ($isLate)
                                · <span class="pp-red-text">{{ __('proposals.sections.late_word') }}</span>
                            @endif
                        @else
                            {{ __('proposals.sections.filed_on', ['date' => ($report->submit_date ?? $report->planned_date)->translatedFormat(__('formats.date'))]) }}
                        @endif
                    </span>
                </span>
                <span class="pp-lrow-r">
                    @if ($isOwed)
                        <a href="{{ route('reports.show', $report) }}" wire:navigate class="pp-btn pp-btn-sm">{{ __('proposals.sections.open_report') }}</a>
                    @else
                        <a href="{{ route('reports.show', $report) }}" wire:navigate class="pp-status {{ $statusClass[$report->status->value] ?? 'pp-st-review' }}">{{ $report->status->label() }}</a>
                    @endif
                </span>
            </div>
        @endforeach
        @if ($reportList->count() > 4)
            <div>
                <button type="button" class="pp-link" x-on:click="all = ! all" x-text="all ? @js(__('proposals.sections.show_fewer')) : @js(__('proposals.sections.show_all', ['count' => $reportList->count()]))" data-test="reports-show-all">{{ __('proposals.sections.show_all', ['count' => $reportList->count()]) }}</button>
            </div>
        @endif
    </div>
@endif

@if ($this->targets->isNotEmpty())
    <div>
        <a href="{{ route('reports.index', ['create' => 1]) }}" wire:navigate class="pp-btn pp-btn-sm">
            <flux:icon.plus variant="micro" class="size-4" aria-hidden="true" />{{ __('proposals.record_a_report') }}
        </a>
    </div>
@endif

@if ($this->donorReports->isNotEmpty())
    <details class="pp-sub" wire:ignore.self data-donor-reports>
        <summary>
            <span class="pp-grow">{{ __('proposals.sections.donor_reports') }}</span>
            <span class="pp-small">{{ __('proposals.sections.donor_reports_line', ['submitted' => $this->donorReports->filter->isSubmitted()->count(), 'total' => $this->donorReports->count()]) }}</span>
            @php
                $lateDonorReports = $this->donorReports->filter->isOverdue()->count();
            @endphp
            @if ($lateDonorReports > 0)
                <span class="pp-pill pp-pill-red">{{ trans_choice('proposals.sections.flag_late', $lateDonorReports, ['count' => $lateDonorReports]) }}</span>
            @endif
            <svg class="pp-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="pp-sub-b">
            @foreach ($this->donorReports as $donorReport)
                <div class="pp-lrow" wire:key="sec-donor-report-{{ $donorReport->id }}" data-donor-report="{{ $donorReport->id }}">
                    <span class="pp-lrow-s">
                        @can('view', $donorReport)
                            <a href="{{ route('donor-reports.show', $donorReport) }}" wire:navigate dir="auto"><b>{{ $donorReport->title }}</b></a>
                        @else
                            <b dir="auto">{{ $donorReport->title }}</b>
                        @endcan
                        <span class="pp-small">
                            {{ $donorReport->type->label() }} ·
                            @if ($donorReport->isSubmitted())
                                {{ __('proposals.sections.filed_on', ['date' => $donorReport->submitted_on->translatedFormat(__('formats.date'))]) }}
                            @else
                                {{ __('proposals.sections.due_on', ['date' => $donorReport->due_on->translatedFormat(__('formats.date'))]) }}
                                @if ($donorReport->isOverdue())
                                    · <span class="pp-red-text">{{ __('proposals.sections.late_word') }}</span>
                                @endif
                            @endif
                        </span>
                    </span>
                    <span class="pp-lrow-r"></span>
                </div>
            @endforeach
        </div>
    </details>
@endif
