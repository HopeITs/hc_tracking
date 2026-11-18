{{-- "My reports" on ⚡index.blade.php: the months waiting on the preparer first ("Needs you"), then one block per report template (D-76): its place, the proposals it covers (so two reports of one place are told apart) and its month strip, its months that still need something, and the validated ones folded away. --}}
@if ($this->myMonths === [])
    <x-ui.empty-state icon="calendar-days" :heading="__('monthly_reports.empty_mine')" :body="__('monthly_reports.empty_mine_body')" />
@else
    <div class="flex flex-col gap-10">
        @if ($this->needsYou !== [])
            <section class="flex flex-col gap-3" aria-labelledby="needs-you-heading" data-needs-you>
                <h2 id="needs-you-heading" class="text-lg font-bold">{{ __('monthly_reports.needs_you') }}</h2>

                <x-ui.panel>
                    <ul class="flex flex-col divide-y divide-secondary-soft dark:divide-line" role="list">
                        @foreach ($this->needsYou as $item)
                            @include('pages.monthly-reports._index-month-row', ['row' => $item['row'], 'rowGroup' => $item['group'], 'inThread' => false, 'projectHasIndicators' => true])
                        @endforeach
                    </ul>
                </x-ui.panel>
            </section>
        @endif

        @foreach ($this->visibleGroups as $group)
            @php
                $groupKey = $group['template']->id;
                $stripLabel = collect($group['strip'])
                    ->map(fn (array $row): string => $row['report']->period->translatedFormat('F Y').': '.($row['overdue'] ? __('monthly_reports.overdue') : __('monthly_reports.states.'.$row['state'])))
                    ->implode(', ');
            @endphp
            <section class="flex flex-col gap-3" aria-labelledby="template-months-{{ $groupKey }}" wire:key="template-months-{{ $groupKey }}" data-template-group="{{ $groupKey }}">
                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                    <div class="min-w-0">
                        <h2 id="template-months-{{ $groupKey }}" class="text-lg font-bold" dir="auto">{{ $group['place'] }}</h2>
                        @include('pages.monthly-reports._covers-proposals', ['proposals' => $group['proposals']])
                    </div>

                    {{-- The project's year at a glance: one square per generated month, coloured by its state. --}}
                    <div class="flex flex-wrap items-center gap-1" role="img" aria-label="{{ __('monthly_reports.month_strip_label', ['months' => $stripLabel]) }}" data-month-strip="{{ $groupKey }}">
                        @foreach ($group['strip'] as $stripRow)
                            @php
                                $stripState = $stripRow['overdue'] ? 'overdue' : $stripRow['state'];
                            @endphp
                            <span
                                @class([
                                    'size-3.5 rounded-[4px]',
                                    'bg-status-good' => $stripState === 'validated',
                                    'bg-secondary-dark dark:bg-ink' => $stripState === 'submitted',
                                    'bg-status-warn' => in_array($stripState, ['returned', 'reopen_pending'], true),
                                    'bg-primary' => $stripState === 'overdue',
                                    'border-2 border-secondary-light dark:border-line-strong' => in_array($stripState, ['draft', 'upcoming'], true),
                                ])
                                data-strip-month="{{ $stripRow['key'] }}" data-strip-state="{{ $stripState }}"
                                title="{{ $stripRow['report']->period->translatedFormat('F Y') }}"
                            ></span>
                        @endforeach
                    </div>
                </div>

                @if (! $group['hasIndicators'])
                    <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('monthly_reports.project_no_indicators') }}</p>
                @endif

                @if ($group['folded'] !== [])
                    <details class="group" data-validated-fold="{{ $groupKey }}">
                        <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 text-sm font-semibold text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink">
                            <flux:icon.chevron-right variant="micro" class="size-4 transition-transform group-open:rotate-90 rtl:-scale-x-100" aria-hidden="true" />
                            {{ trans_choice('monthly_reports.validated_months', count($group['folded']), ['count' => count($group['folded'])]) }}
                        </summary>
                        <ol class="relative mt-1 flex flex-col before:absolute before:inset-y-3 before:start-[5px] before:w-px before:bg-secondary-soft dark:before:bg-line" role="list">
                            @foreach ($group['folded'] as $row)
                                @include('pages.monthly-reports._index-month-row', ['row' => $row, 'rowGroup' => $group, 'inThread' => true, 'projectHasIndicators' => $group['hasIndicators']])
                            @endforeach
                        </ol>
                    </details>
                @endif

                @if ($group['open'] !== [])
                    <ol class="relative flex flex-col before:absolute before:inset-y-3 before:start-[5px] before:w-px before:bg-secondary-soft dark:before:bg-line" role="list">
                        @foreach ($group['open'] as $row)
                            @include('pages.monthly-reports._index-month-row', ['row' => $row, 'rowGroup' => $group, 'inThread' => true, 'projectHasIndicators' => $group['hasIndicators']])
                        @endforeach
                    </ol>
                @endif
            </section>
        @endforeach
    </div>
@endif
