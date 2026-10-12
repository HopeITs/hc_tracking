        {{-- What the donor is owed back: every report, by when, and whether it went out. --}}
        <section class="mt-10 min-w-0 border-t pt-8 {{ $hairline }}" aria-labelledby="reports-heading" data-donor-reports>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                <h2 id="reports-heading" class="text-xl font-bold tracking-tight {{ $inkText }}">
                    {{ __('funding_agreements.donor_reports') }}
                    <span class="ms-1 text-base font-semibold tabular-nums {{ $mutedText }}">{{ $this->reports->count() }}</span>
                </h2>
                @can('funding-agreements.update')
                <x-ui.button size="sm" icon="plus" class="min-h-10 touch-manipulation" wire:click="openReportCreate" data-test="add-donor-report">{{ __('funding_agreements.add_report') }}</x-ui.button>
                @endcan
            </div>

            @if ($this->reports->isEmpty())
                <p class="mt-4 text-sm {{ $mutedText }}">{{ __('funding_agreements.no_donor_reports_yet') }}</p>
            @else
                <x-funding.register class="mt-4" size="sm" :caption="__('funding_agreements.donor_reports')">
                    <x-slot:head>
                        <x-funding.register.th>{{ __('funding_agreements.report_title') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('common.type') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('funding_agreements.due') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('funding_agreements.submitted') }}</x-funding.register.th>
                        <x-funding.register.th>{{ __('common.state') }}</x-funding.register.th>
                        <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
                    </x-slot:head>

                    @foreach ($this->reports as $report)
                        @php
                            $reportDays = (int) \Carbon\CarbonImmutable::today()->diffInDays($report->due_on, false);
                            $reportOverdue = $report->isOverdue();
                        @endphp
                        <tr wire:key="donor-report-{{ $report->id }}" data-donor-report="{{ $report->id }}">
                            <td data-label="{{ __('funding_agreements.report_title') }}" data-wide><span class="font-semibold">{{ $report->title }}</span></td>
                            <td data-label="{{ __('common.type') }}">{{ $report->type->label() }}</td>
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
                                @can('funding-agreements.update')
                                    <span class="flex items-center justify-end gap-1">
                                        @unless ($report->isSubmitted())
                                            <x-ui.button size="sm" icon="check" class="min-h-8 touch-manipulation" wire:click="markReportSubmitted({{ $report->id }})" data-test="submit-report-{{ $report->id }}">{{ __('funding_agreements.mark_submitted') }}</x-ui.button>
                                        @endunless
                                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openReportEdit({{ $report->id }})" :aria-label="__('common.edit_name', ['name' => $report->title])" />
                                        <x-ui.button tone="ghost" size="sm" icon="trash" class="size-8!" wire:click="confirmReportDelete({{ $report->id }})" :aria-label="__('funding_agreements.delete_report_name', ['name' => $report->title])" data-test="delete-report-{{ $report->id }}" />
                                    </span>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </x-funding.register>
            @endif
        </section>
