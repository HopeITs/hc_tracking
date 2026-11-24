{{-- The header of ⚡show.blade.php: the template's place and the proposals it covers (Y-03, D-76: never the project), month, state and the return reason. Who prepared, submitted and validated it sits in the journey and the side column. --}}
@use('App\Enums\MonthlyReportStatus')
@use('App\Enums\ReopenRequestStatus')
@use('App\Support\MonthlyReportNames')
@use('App\Support\MonthlyReportPreparers')

@php
    $headerNames = app(MonthlyReportNames::class)->forTemplates([$report->template])[$report->report_template_id];
    $placeParts = app(MonthlyReportNames::class)->placeParts($report->template);
    $monthLabel =$report->period->translatedFormat('F Y');
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
        ['label' => $monthLabel],
    ]"
    :title="$monthLabel"
    :unread="app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'monthly_report', $report->id)"
>
    <x-slot:actions>
        <x-monthly-reports.state-chip :state="$headerState" class="px-2.5! py-1! text-sm!" />
    </x-slot:actions>

    {{-- Each part of the place and the proposals under its own label; the proposals tell two reports of one place and month apart. --}}
    <x-slot:meta>
        <dl class="mrp-head-facts">
            <div>
                <dt>{{ __('monthly_reports.head_city') }}</dt>
                <dd dir="auto">{{ $placeParts['city'] ?? __('monthly_reports.head_not_set') }}</dd>
            </div>
            <div>
                <dt>{{ __('monthly_reports.head_program') }}</dt>
                <dd dir="auto">{{ $placeParts['program'] ?? __('monthly_reports.head_not_set') }}</dd>
            </div>
            <div>
                <dt>{{ __('monthly_reports.head_center') }}</dt>
                <dd dir="auto" @class(['mrp-head-unset' => $placeParts['center'] === null])>{{ $placeParts['center'] ?? __('monthly_reports.head_not_set') }}</dd>
            </div>
            <div class="mrp-head-wide">
                <dt>{{ trans_choice('monthly_reports.head_proposal', max(1, count($headerNames['proposals']))) }}</dt>
                <dd>
                    @forelse ($headerNames['proposals'] as $coveredProposal)
                        @can('proposals.view')
                            <a href="{{ route('proposals.show', $coveredProposal['slug']) }}" wire:navigate dir="auto">{{ $coveredProposal['name'] }}</a>@if (! $loop->last){{ app()->getLocale() === 'ar' ? '، ' : ', ' }}@endif
                        @else
                            <span dir="auto">{{ $coveredProposal['name'] }}</span>@if (! $loop->last){{ app()->getLocale() === 'ar' ? '، ' : ', ' }}@endif
                        @endcan
                    @empty
                        <span class="mrp-head-unset">{{ __('monthly_reports.no_proposal') }}</span>
                    @endforelse
                </dd>
            </div>
        </dl>
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
