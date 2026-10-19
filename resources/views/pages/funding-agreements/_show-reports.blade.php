        {{-- What the donor is owed back: every report, by when, and whether it went out. --}}
        <section class="mt-10 min-w-0 border-t pt-8 {{ $hairline }}" aria-labelledby="reports-heading" data-donor-reports data-tour="agreement-donor-reports">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                <h2 id="reports-heading" class="text-xl font-bold tracking-tight {{ $inkText }}">
                    {{ __('funding_agreements.donor_reports') }}
                    <span class="ms-1 text-base font-semibold tabular-nums {{ $mutedText }}">{{ $this->reports->count() }}</span>
                </h2>
                <div class="flex flex-wrap items-center gap-2">
                    @can('reports.aggregate')
                        <x-ui.button size="sm" icon="document-chart-bar" class="min-h-10 touch-manipulation" :href="route('donor-reports.compose', ['proposal' => $agreement->proposal_id])" wire:navigate data-test="compose-donor-report">{{ __('donor_reports.compose_report') }}</x-ui.button>
                    @endcan
                    @can('funding-agreements.update')
                        <x-ui.button size="sm" icon="plus" class="min-h-10 touch-manipulation" wire:click="openReportCreate" data-test="add-donor-report">{{ __('funding_agreements.add_report') }}</x-ui.button>
                    @endcan
                </div>
            </div>

            @if ($this->reports->isEmpty())
                <p class="mt-4 text-sm {{ $mutedText }}">{{ __('funding_agreements.no_donor_reports_yet') }}</p>
            @else
                <x-funding.register class="mt-4" size="sm" :caption="__('funding_agreements.donor_reports')">
                    <x-slot:head>
                        <x-funding.register.th>{{ __('funding_agreements.report_title') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('common.type') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('donor_reports.audience') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('donor_reports.period') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('donor_reports.responsible') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('funding_agreements.due') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('funding_agreements.submitted') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('common.state') }}</x-funding.register.th>
                        <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
                    </x-slot:head>

                    @foreach ($this->reports as $report)
                        @php
                            $reportDays = (int) \Carbon\CarbonImmutable::today()->diffInDays($report->due_on, false);
                            $reportOverdue = $report->isOverdue();
                            $responsible = $report->responsible_employee_id === null ? null : ($this->reportResponsibles[$report->responsible_employee_id] ?? null);
                        @endphp
                        <tr wire:key="donor-report-{{ $report->id }}" data-donor-report="{{ $report->id }}">
                            <td data-label="{{ __('funding_agreements.report_title') }}" data-wide>
                                <span class="font-semibold" dir="auto">{{ $report->title }}</span>
                                @if ($report->isComposed())
                                    <span class="ms-1 text-xs font-semibold tabular-nums {{ $mutedText }}" data-report-version>{{ __('donor_reports.version_number', ['version' => $report->version]) }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('common.type') }}">{{ $report->type->label() }}</td>
                            <td data-label="{{ __('donor_reports.audience') }}">{{ $report->audience->label() }}</td>
                            <td class="date" data-label="{{ __('donor_reports.period') }}">
                                @if ($report->isComposed())
                                    <span dir="ltr" class="tabular-nums">{{ $report->period_start->format('Y-m') }} – {{ $report->period_end->format('Y-m') }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td data-label="{{ __('donor_reports.responsible') }}">
                                @if ($responsible === null)
                                    <span class="{{ $mutedText }}">{{ __('common.not_assigned') }}</span>
                                @else
                                    <span dir="auto">{{ $responsible['employee']?->name ?? '#'.$report->responsible_employee_id }}</span>
                                    @unless ($responsible['reachable'])
                                        <span class="ms-1 text-xs font-semibold text-status-critical" data-responsible-inactive>{{ __('donor_reports.responsible_unreachable_short') }}</span>
                                    @endunless
                                @endif
                            </td>
                            <td class="date" data-label="{{ __('funding_agreements.due') }}">{{ $report->due_on->translatedFormat(__('formats.date')) }}</td>
                            <td class="date" data-label="{{ __('funding_agreements.submitted') }}">{{ $report->submitted_on?->translatedFormat(__('formats.date')) ?? '—' }}</td>
                            <td data-label="{{ __('common.state') }}" data-report-status>
                                <span @class([
                                    'text-xs font-semibold',
                                    'text-funding' => $report->isSubmitted(),
                                    'text-status-critical' => $reportOverdue,
                                    $mutedText => ! $report->isSubmitted() && ! $reportOverdue,
                                ])>
                                    @if ($report->isSubmitted())
                                        {{ __('funding_agreements.submitted') }}
                                    @elseif ($reportOverdue)
                                        {{ trans_choice('funding_agreements.count_days_overdue', -$reportDays, ['count' => -$reportDays]) }}
                                    @else
                                        {{ trans_choice('funding_agreements.due_in_days', $reportDays, ['count' => $reportDays]) }}
                                    @endif
                                </span>
                            </td>
                            <td>
                                <span class="flex items-center justify-end gap-1">
                                    <x-ui.button tone="ghost" size="sm" icon="eye" class="size-8!" :href="route('donor-reports.show', $report)" wire:navigate :aria-label="__('common.view_name', ['name' => $report->title])" data-test="view-report-{{ $report->id }}" />
                                    @unless ($report->isSubmitted())
                                        @can('submit', $report)
                                            <x-ui.button size="sm" icon="check" class="min-h-8 touch-manipulation" wire:click="openSubmit({{ $report->id }})" data-test="submit-report-{{ $report->id }}">{{ __('funding_agreements.mark_submitted') }}</x-ui.button>
                                        @endcan
                                        @can('funding-agreements.update')
                                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openReportEdit({{ $report->id }})" :aria-label="__('common.edit_name', ['name' => $report->title])" />
                                        @endcan
                                        @can('delete', $report)
                                            <x-ui.button tone="ghost" size="sm" icon="trash" class="size-8!" wire:click="confirmReportDelete({{ $report->id }})" :aria-label="__('funding_agreements.delete_report_name', ['name' => $report->title])" data-test="delete-report-{{ $report->id }}" />
                                        @endcan
                                    @endunless
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </x-funding.register>
            @endif
        </section>
