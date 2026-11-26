{{-- "To validate" on ⚡index.blade.php: the MEAL queue (MonthlyReport::validatableBy()) as one calm full-width table, a line per report: program, city, the proposals its template covers (D-76), month, how long it has waited and one Review button. The longest-waiting come first; any heading re-sorts (queueRows()). "Start reviewing" in the register's actions opens the first row, and the report page's "Next report" walks the rest. The filters' one way back is the register bar's Clear. --}}
@use('App\Enums\MonthlyReportStatus')
@php
    $queueRows = $this->queueRows;
    $shownRows = $queueRows->take($queueLimit);
    $hiddenCount = $queueRows->count() - $shownRows->count();
    $sortColumns = [
        'program' => ['label' => __('monthly_reports.col_program'), 'class' => ''],
        'city' => ['label' => __('common.city'), 'class' => 'w-40!'],
        'proposals' => ['label' => __('monthly_reports.col_proposals'), 'class' => ''],
        'month' => ['label' => __('monthly_reports.month'), 'class' => 'w-44!'],
        'wait' => ['label' => __('monthly_reports.col_waiting'), 'class' => 'w-40!'],
    ];
@endphp

@if ($queueRows->isEmpty())
    <x-ui.panel>
        <x-ui.empty-state
            icon="check-badge"
            :heading="$filtersAreActive ? __('monthly_reports.empty_filtered') : __('monthly_reports.empty_validate')"
            :body="$filtersAreActive ? __('common.change_the_filters_and_try_again') : __('monthly_reports.empty_validate_body')"
        />
    </x-ui.panel>
@else
    <div class="flex flex-col gap-3">
        <x-ui.data-table :caption="__('monthly_reports.tab_validate')" data-validate-queue>
            <x-slot:head>
                @foreach ($sortColumns as $column => $heading)
                    @if ($column === 'proposals')
                        <th @class([$heading['class']])>{{ $heading['label'] }}</th>
                    @else
                        <th @class([$heading['class']]) aria-sort="{{ $sort === $column ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                            <button type="button" wire:click="sortQueue('{{ $column }}')" @class(['inline-flex items-center gap-1 uppercase transition-colors hover:text-secondary-dark dark:hover:text-ink', 'text-secondary-dark dark:text-ink' => $sort === $column]) data-sort="{{ $column }}">
                                {{ $heading['label'] }}
                                @if ($sort === $column)
                                    <span aria-hidden="true">{{ $direction === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </button>
                        </th>
                    @endif
                @endforeach
                <th class="w-48!"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($shownRows as $row)
                @php
                    $queued = $row['report'];
                    $isSubmitted = $queued->status === MonthlyReportStatus::Submitted;
                    $notSeen = $isSubmitted && $queued->seen_by_meal_at === null;
                    $monthLabel = $queued->period->translatedFormat('F Y');
                    $programName = $row['program'] !== '' ? $row['program'] : __('monthly_reports.no_program');
                    $rowName = implode(' · ', array_filter([$programName, $row['city'], $monthLabel]));
                @endphp
                <tr class="group [&>td]:px-3.5 [&>td]:py-3" wire:key="queued-{{ $queued->id }}" data-validate-row="{{ $queued->id }}" data-queue-template="{{ $queued->report_template_id }}">
                    <td class="py-3.5! font-semibold text-secondary-dark dark:text-ink">
                        <span class="flex items-center gap-3">
                            <span @class(['size-2 shrink-0 rounded-full', 'bg-primary' => $notSeen, 'ring-[1.5px] ring-inset ring-secondary-soft dark:ring-line' => ! $notSeen]) title="{{ $notSeen ? __('monthly_reports.not_seen_yet') : __('monthly_reports.seen') }}" aria-hidden="true" @if ($notSeen) data-not-seen="{{ $queued->id }}" @endif></span>
                            <span class="sr-only">{{ $notSeen ? __('monthly_reports.not_seen_yet') : __('monthly_reports.seen') }}</span>
                            <span class="min-w-0" dir="auto">
                                {{ $programName }}
                                @if ($row['center'] !== null)
                                    <span class="block text-xs font-normal text-secondary-medium dark:text-ink-muted">{{ $row['center'] }}</span>
                                @endif
                            </span>
                        </span>
                    </td>
                    <td class="text-secondary-medium dark:text-ink-muted" dir="auto">{{ $row['city'] !== '' ? $row['city'] : '—' }}</td>
                    <td class="max-w-md text-secondary-medium dark:text-ink-muted">
                        <span class="line-clamp-2" title="{{ $row['proposalsText'] }}" dir="auto">{{ $row['proposalsText'] !== '' ? $row['proposalsText'] : __('monthly_reports.no_proposal') }}</span>
                    </td>
                    <td class="whitespace-nowrap text-secondary-dark dark:text-ink">
                        {{ $monthLabel }}
                        @unless ($isSubmitted)
                            <span class="block"><x-monthly-reports.state-chip state="validated" /></span>
                        @endunless
                    </td>
                    <td class="whitespace-nowrap tabular-nums">
                        {{-- A plain fact: no validation deadline is set anywhere, so only a month of waiting turns the number red. --}}
                        @if ($row['waitingDays'] !== null)
                            <span @class(['font-semibold', 'text-secondary-dark dark:text-ink' => $row['waitingDays'] < 30, 'text-status-critical' => $row['waitingDays'] >= 30]) data-waiting="{{ $queued->id }}">
                                {{ trans_choice('monthly_reports.days_count', $row['waitingDays'], ['count' => $row['waitingDays']]) }}
                            </span>
                            <span class="block text-xs text-secondary-medium dark:text-ink-muted">{{ __('monthly_reports.submitted_on', ['date' => $queued->submitted_at->translatedFormat(__('formats.date'))]) }}</span>
                        @else
                            <span class="text-secondary-medium dark:text-ink-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <div class="flex items-center justify-end gap-1.5">
                            {{-- The monthly matrix for this template's place, with the list's proposal and window (Y-05). --}}
                            <x-ui.button tone="ghost" size="sm" icon="table-cells" class="max-sm:size-11" :href="$this->matrixUrlFor($queued->template)" wire:navigate :aria-label="__('monthly_reports.open_in_matrix', ['name' => $rowName])" data-matrix-link="{{ $queued->id }}" />
                            <a href="{{ route('monthly-reports.show', $queued) }}" wire:navigate
                               class="inline-flex h-9 items-center gap-1.5 rounded-[10px] border border-secondary-soft bg-white px-3.5 text-sm font-semibold text-secondary-dark transition-colors duration-150 group-hover:border-primary group-hover:bg-primary group-hover:text-white focus-visible:border-primary focus-visible:bg-primary focus-visible:text-white max-sm:h-11 max-sm:flex-1 max-sm:justify-center dark:border-line dark:bg-surface-2 dark:text-ink"
                               aria-label="{{ ($isSubmitted ? __('monthly_reports.review') : __('monthly_reports.view')).' · '.$rowName }}" data-review-link="{{ $queued->id }}">
                                {{ $isSubmitted ? __('monthly_reports.review') : __('monthly_reports.view') }}
                                <span aria-hidden="true" class="rtl:-scale-x-100">→</span>
                            </a>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.data-table>

        <div class="flex flex-wrap items-center justify-between gap-3 px-1 text-sm text-secondary-medium dark:text-ink-muted">
            <span data-queue-count>{{ trans_choice('monthly_reports.queue_count', $queueRows->count(), ['count' => $queueRows->count()]) }}</span>
            @if ($hiddenCount > 0)
                <button type="button" class="dk-btn dk-ghost dk-sm" wire:click="showMoreQueue" data-queue-more>
                    {{ __('monthly_reports.show_more', ['count' => min($hiddenCount, 25)]) }}
                </button>
            @endif
            @if ($shownRows->contains(fn (array $row): bool => $row['report']->status === MonthlyReportStatus::Submitted && $row['report']->seen_by_meal_at === null))
                <span class="inline-flex items-center gap-2"><span class="size-2 rounded-full bg-primary" aria-hidden="true"></span>{{ __('monthly_reports.not_seen_yet') }}</span>
            @endif
        </div>
    </div>
@endif
