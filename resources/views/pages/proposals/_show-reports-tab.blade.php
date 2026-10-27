{{--
    The Reports tab of proposals/show: the tally and every report filed
    against this proposal's targets. Included by ⚡show.blade.php, whose top
    PHP block defines $mutedText, $inkText, $hairline, $amount, $term,
    $chronology and $arrivals.
--}}
@use('App\Enums\ReportStatus')
@use('App\Models\Report')
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <p class="max-w-prose text-xs {{ $mutedText }}">{{ __('proposals.reports_filed_against_the_targets_this_proposal_commits') }}</p>
                @if ($this->targets->isNotEmpty())
                    <a href="{{ route('reports.index', ['create' => 1]) }}" wire:navigate class="inline-flex min-h-10 touch-manipulation items-center gap-1.5 rounded-lg bg-primary dark:bg-brand dark:hover:bg-brand-hover px-3 text-sm font-semibold text-white hover:bg-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium" data-test="record-report">
                        <flux:icon.plus variant="micro" class="size-4" aria-hidden="true" />
                        {{ __('proposals.record_a_report') }}
                    </a>
                @endif
            </div>

            @if ($this->reports->isEmpty())
                <x-ui.empty-state icon="document-chart-bar" :heading="__('proposals.no_reports_yet')" :body="__('proposals.reports_on_this_proposal_s_targets_will_appear_here')" />
            @else
                @php
                    $today = \Carbon\CarbonImmutable::today();
                    $owed = $this->reports->filter(fn (Report $report): bool => $this->reportIsOwed($report));
                    $reportTally = [
                        ['key' => 'late', 'label' => __('proposals.owed_and_late'), 'count' => $owed->filter(fn (Report $report): bool => $report->planned_date->lt($today))->count(), 'alarm' => true],
                        ['key' => 'owed', 'label' => __('proposals.owed_on_time'), 'count' => $owed->filter(fn (Report $report): bool => ! $report->planned_date->lt($today))->count(), 'alarm' => false],
                        ['key' => 'review', 'label' => __('proposals.in_review'), 'count' => $this->reports->filter(fn (Report $report): bool => ! $this->reportIsOwed($report) && $report->status !== ReportStatus::Approved)->count(), 'alarm' => false],
                        ['key' => 'approved', 'label' => ReportStatus::Approved->label(), 'count' => $this->reports->where('status', ReportStatus::Approved)->count(), 'alarm' => false],
                    ];
                @endphp
                <dl class="grid grid-cols-2 gap-x-6 gap-y-4 border-b pb-6 {{ $hairline }} @2xl/detail:grid-cols-4" data-report-tally>
                    @foreach ($reportTally as $tally)
                        <div class="min-w-0" data-report-tally-item="{{ $tally['key'] }}">
                            <dt class="type-label {{ $mutedText }}">{{ $tally['label'] }}</dt>
                            <dd @class(['mt-1 text-2xl leading-none font-extrabold tracking-tight tabular-nums', 'text-status-critical' => $tally['alarm'] && $tally['count'] > 0, $inkText => ! ($tally['alarm'] && $tally['count'] > 0) && $tally['count'] > 0, $mutedText => $tally['count'] === 0])>{{ $tally['count'] }}</dd>
                        </div>
                    @endforeach
                </dl>

                <ul class="divide-y {{ $hairline }}" data-report-list>
                    @foreach ($this->reports as $report)
                        @php
                            $isOwed = $this->reportIsOwed($report);
                            $isLate = $isOwed && $report->planned_date->lt($today);
                        @endphp
                        <li class="flex min-w-0 flex-col gap-2 py-4 @2xl/detail:flex-row @2xl/detail:items-center @2xl/detail:gap-6" wire:key="report-{{ $report->id }}" data-report="{{ $report->id }}">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('reports.show', $report) }}" wire:navigate class="line-clamp-2 text-sm font-semibold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium {{ $inkText }}">{{ $report->planIndicator->indicator->name }}</a>
                                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs tabular-nums {{ $mutedText }}">
                                    <span>{{ $report->planIndicator->project?->name ?? __('common.organisation_wide') }}</span>
                                    @if ($report->period_start !== null && $report->period_end !== null)
                                        <span aria-hidden="true">·</span>
                                        <span>{{ __('proposals.period_range', ['from' => $report->period_start->translatedFormat(__('formats.date_short')), 'to' => $report->period_end->translatedFormat(__('formats.date_short'))]) }}</span>
                                    @endif
                                    <span aria-hidden="true">·</span>
                                    <span>{{ __('proposals.value_reported', ['value' => number_format((float) $report->value, 2)]) }}</span>
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-1 text-xs tabular-nums">
                                <span @class(['font-semibold text-status-critical' => $isLate, $mutedText => ! $isLate])>{{ __('proposals.due_on', ['date' => $report->planned_date->translatedFormat(__('formats.date'))]) }}</span>
                                @if ($report->submit_date !== null)
                                    <span class="{{ $mutedText }}">{{ __('proposals.submitted_on', ['date' => $report->submit_date->translatedFormat(__('formats.date'))]) }}</span>
                                @endif
                                <x-ui.enum-badge :case="$report->status" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
