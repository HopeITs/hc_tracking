{{--
    The month grid of calendar/index: weekday headers and one cell per day
    with that day's chips. Included by ⚡index.blade.php, whose top PHP block
    defines $calendar, $today, $focusedType, $focusedGroupKeys,
    $allGroupKeys, $typeRingClasses, $typeDotClasses, $proposalTone and
    $projectTone.
--}}
                <div class="grid grid-cols-7 border-b border-secondary-soft bg-secondary-soft/40 text-center text-xs font-semibold uppercase tracking-wide text-secondary-medium dark:border-line dark:bg-surface-2 dark:text-ink-muted">
                    @foreach ($calendar['weeks'][0] as $weekdayHeader)
                        <div class="py-2">{{ $weekdayHeader->translatedFormat('D') }}</div>
                    @endforeach
                </div>

                <div class="grid grid-cols-7">
                    @foreach ($calendar['weeks'] as $week)
                        @foreach ($week as $day)
                            @php
                                $isCurrentMonth = $day->month === $calendar['month']->month;
                                $isToday = $day->isSameDay($today);
                                $dayFocusedCount = $focusedType === null ? 0 : collect($focusedGroupKeys[$focusedType])->sum(fn (string $key) => $calendar[$key]->get($day->toDateString(), new \Illuminate\Support\Collection)->count());
                                $dayItemCount = collect($allGroupKeys)->sum(fn (string $key) => $calendar[$key]->get($day->toDateString(), new \Illuminate\Support\Collection)->count());
                            @endphp
                            <div
                                wire:key="calendar-day-{{ $day->toDateString() }}"
                                x-on:click="menuOpen = true; menuX = $event.clientX; menuY = $event.clientY; menuDate = '{{ $day->toDateString() }}'"
                                x-on:contextmenu.prevent="menuOpen = true; menuX = $event.clientX; menuY = $event.clientY; menuDate = '{{ $day->toDateString() }}'"
                                @class([
                                    'flex min-h-28 cursor-pointer flex-col gap-1 border-b border-e border-secondary-soft p-1.5 text-start last:border-e-0 [&:nth-child(7n)]:border-e-0 hover:bg-secondary-soft/40 dark:border-line dark:hover:bg-surface-2 transition-opacity',
                                    'opacity-40' => ! $isCurrentMonth,
                                    'opacity-50' => $focusedType !== null && $dayFocusedCount === 0,
                                    'ring-2 ring-inset '.$typeRingClasses[$focusedType ?? 'milestone'] => $focusedType !== null && $dayFocusedCount > 0,
                                ])
                                data-test="calendar-day-{{ $day->toDateString() }}"
                            >
                                <span @class([
                                    'inline-flex size-6 items-center justify-center rounded-full text-xs font-semibold tabular-nums',
                                    'bg-primary dark:bg-ink dark:text-surface-0 text-white' => $isToday,
                                    'text-secondary-dark dark:text-ink' => ! $isToday,
                                ])>
                                    {{ $day->day }}
                                </span>

                                <div
                                    class="flex min-h-4 flex-1 flex-col gap-1"
                                    x-data="taskDropList('my-calendar', (wire, id) => wire.rescheduleTask(id, '{{ $day->toDateString() }}'))"
                                    @if ($dayItemCount > 0)
                                        wire:click.stop="openDayDetail('{{ $day->toDateString() }}')"
                                        role="button"
                                        tabindex="0"
                                        data-test="calendar-day-content-{{ $day->toDateString() }}"
                                    @endif
                                >
                                    @include('pages.calendar._item-chips', ['day' => $day, 'calendar' => $calendar, 'markerTone' => $this->markerTone(...), 'taskTone' => $this->taskTone(...), 'reportTone' => $this->reportTone(...), 'proposalTone' => $proposalTone, 'transferTone' => $this->transferTone(...), 'donorFollowUpTone' => $this->donorFollowUpTone(...), 'donorReportTone' => $this->donorReportTone(...), 'projectTone' => $projectTone, 'typeDotClasses' => $typeDotClasses])
                                </div>
                            </div>
                        @endforeach
                    @endforeach
                </div>
