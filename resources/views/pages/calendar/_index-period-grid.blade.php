{{--
    The day / 4-days / week grid of calendar/index: day headers, the all-day
    row with each day's chips, and the hourly rows with the "now" line.
    Included by ⚡index.blade.php, whose top PHP block defines $calendar,
    $today, $focusedType, $focusedGroupKeys, $allGroupKeys,
    $typeRingClasses, $typeDotClasses, $proposalTone and $projectTone.
--}}
                @php
                    $periodDays = $calendar['days'];
                    $now = \Carbon\CarbonImmutable::now();
                    $currentHour = $now->hour;
                    $currentMinuteFraction = $now->minute / 60;
                    $gridMinWidth = 4.5 + count($periodDays) * 6;
                    $gridColumns = 'grid-template-columns: 4.5rem repeat('.count($periodDays).', minmax(0, 1fr)); min-width: '.$gridMinWidth.'rem;';
                @endphp

                <div class="overflow-x-auto">
                <div class="grid" style="{{ $gridColumns }}">
                    <div class="border-b border-e border-secondary-soft dark:border-line"></div>
                    @foreach ($periodDays as $day)
                        @php $isToday = $day->isSameDay($today); @endphp
                        <div class="border-b border-e border-secondary-soft p-2 text-center last:border-e-0 dark:border-line">
                            <div @class(['text-sm font-semibold', 'text-primary dark:text-brand-text' => $isToday, 'text-secondary-dark dark:text-ink' => ! $isToday])>{{ $day->translatedFormat('l') }}</div>
                            <div @class(['text-xs', 'text-primary dark:text-brand-text' => $isToday, 'text-secondary-medium dark:text-ink-muted' => ! $isToday])>{{ $day->translatedFormat('j M') }}</div>
                        </div>
                    @endforeach

                    <div class="flex items-center justify-end border-e border-secondary-soft px-2 py-1.5 text-[11px] font-semibold text-secondary-medium dark:border-line dark:text-ink-muted">{{ __('calendar.all_day') }}</div>
                    @foreach ($periodDays as $day)
                        @php
                            $dayFocusedCount = $focusedType === null ? 0 : collect($focusedGroupKeys[$focusedType])->sum(fn (string $key) => $calendar[$key]->get($day->toDateString(), new \Illuminate\Support\Collection)->count());
                            $dayItemCount = collect($allGroupKeys)->sum(fn (string $key) => $calendar[$key]->get($day->toDateString(), new \Illuminate\Support\Collection)->count());
                        @endphp
                        <div
                            wire:key="calendar-allday-{{ $day->toDateString() }}"
                            x-on:click="menuOpen = true; menuX = $event.clientX; menuY = $event.clientY; menuDate = '{{ $day->toDateString() }}'"
                            x-on:contextmenu.prevent="menuOpen = true; menuX = $event.clientX; menuY = $event.clientY; menuDate = '{{ $day->toDateString() }}'"
                            @class([
                                'flex min-h-10 cursor-pointer flex-col gap-1 border-e border-secondary-soft p-1.5 text-start last:border-e-0 hover:bg-secondary-soft/40 dark:border-line dark:hover:bg-surface-2 transition-opacity',
                                'opacity-50' => $focusedType !== null && $dayFocusedCount === 0,
                                'ring-2 ring-inset '.$typeRingClasses[$focusedType ?? 'milestone'] => $focusedType !== null && $dayFocusedCount > 0,
                            ])
                            data-test="calendar-allday-{{ $day->toDateString() }}"
                        >
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
                </div>

                <div class="max-h-96 overflow-y-auto border-t border-secondary-soft dark:border-line" x-init="$el.scrollTop = Math.max(0, {{ $currentHour }} * 48 - 160)">
                    <div class="grid" style="{{ $gridColumns }}">
                        @for ($hour = 0; $hour < 24; $hour++)
                            <div class="h-12 border-b border-e border-secondary-soft px-2 py-1 text-end text-[11px] text-secondary-medium dark:border-line dark:text-ink-muted">
                                {{ $hour === 0 ? '' : \Carbon\CarbonImmutable::today()->addHours($hour)->translatedFormat('ga') }}
                            </div>
                            @foreach ($periodDays as $day)
                                <div class="relative h-12 border-b border-e border-secondary-soft last:border-e-0 dark:border-line">
                                    @if ($day->isSameDay($today) && $hour === $currentHour)
                                        <div class="pointer-events-none absolute inset-x-0 h-px bg-primary dark:bg-ink" style="top: {{ $currentMinuteFraction * 100 }}%;"></div>
                                    @endif
                                </div>
                            @endforeach
                        @endfor
                    </div>
                </div>
                </div>
