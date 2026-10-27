{{--
    The chronology on the Funding tab overview of proposals/show: the term,
    projects, money and status moves on one scale, the scrubber and its
    readout. Included by _show-funding-tab; it reads $mutedText, $inkText,
    $hairline, $term, $chronology and $arrivals from the top PHP block of
    ⚡show.blade.php.
--}}
@use('App\Models\Project')
            {{-- The same money on one time scale with the term, the projects and the status moves. --}}
            <div class="mt-10 border-t pt-6 {{ $hairline }}">
                {{-- One scale: the grant term, the projects it funds, its money and its status moves. --}}
                <div class="min-w-0" data-chronology x-data="proposalClock(@js($arrivals['scrub']))">
                    <p class="text-xs {{ $mutedText }}">{{ __('proposals.one_scale_from_to', ['from' => $chronology['start']->translatedFormat(__('formats.date')), 'to' => $chronology['end']->translatedFormat(__('formats.date'))]) }}</p>

                    <div class="relative mt-6 pt-5">
                        @if ($chronology['todayPercent'] !== null)
                            <span class="pointer-events-none absolute inset-y-0 z-20 w-px bg-primary dark:bg-ink" style="inset-inline-start: {{ $chronology['todayPercent'] }}%" aria-hidden="true"></span>
                            <span class="absolute top-0 z-20 -translate-x-1/2 text-[10px] font-bold tracking-wide text-status-critical dark:text-ink uppercase rtl:translate-x-1/2" style="inset-inline-start: {{ $chronology['todayPercent'] }}%">{{ __('common.today') }}</span>
                        @endif

                        {{-- The scrubbing cursor, drawn over every lane at once. --}}
                        <span class="chrono-cursor pointer-events-none absolute inset-y-0 z-20 w-px" style="display: none" :style="`inset-inline-start: ${percent}%`" x-show="scrubbing" aria-hidden="true"></span>

                        {{--
                            The climb: how the money actually arrived against what was
                            committed. One plot per currency, each scaled to its own
                            commitment, because the two are never added together.
                        --}}
                        @foreach ($arrivals['climbs'] as $climb)
                            <div class="relative mb-1 h-16 @2xl/detail:h-20" wire:key="climb-{{ $climb['currency'] }}" data-money-climb>
                                <svg class="chrono-plot absolute inset-0 size-full" viewBox="0 0 1000 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                                    <path d="{{ $climb['area'] }}" class="chrono-area" />
                                    <polyline points="{{ $climb['line'] }}" class="chrono-line" />
                                    <line x1="0" x2="1000" y1="{{ $climb['ceiling'] }}" y2="{{ $climb['ceiling'] }}" class="chrono-ceiling" />
                                </svg>
                                <p class="pointer-events-none absolute end-0 text-[10px] font-semibold tabular-nums {{ $mutedText }}" style="inset-block-start: max(0px, calc({{ $climb['ceiling'] }}% - 1.05rem))">
                                    {{ __('proposals.committed_ceiling', ['amount' => number_format($climb['committed'], 0), 'currency' => $climb['currency']]) }}
                                </p>
                                <p class="pointer-events-none absolute start-0 bottom-0 text-[10px] font-semibold tabular-nums text-funding">
                                    {{ __('proposals.landed_so_far', ['amount' => number_format($climb['landed'], 0), 'currency' => $climb['currency']]) }}
                                </p>
                            </div>
                        @endforeach

                        {{-- Money above the line. --}}
                        @if ($chronology['money'] !== [])
                            {{-- Labels that would collide climb into higher lanes on a stem; labels near an edge anchor to it. --}}
                            <ol class="relative" style="height: {{ 4 + collect($chronology['money'])->max('lane') * 2 }}rem" aria-label="{{ __('common.transfers') }}">
                                @foreach ($chronology['money'] as $event)
                                    <li @class([
                                        'absolute bottom-0',
                                        '-translate-x-1/2 rtl:translate-x-1/2' => $event['anchor'] === 'center',
                                        '-translate-x-full rtl:translate-x-full' => $event['anchor'] === 'end',
                                    ]) style="inset-inline-start: {{ $event['at'] }}%" wire:key="chrono-{{ $event['key'] }}" data-lane="{{ $event['lane'] }}">
                                        <span @class(['flex flex-col gap-1', 'items-center' => $event['anchor'] === 'center', 'items-start' => $event['anchor'] === 'start', 'items-end' => $event['anchor'] === 'end'])>
                                            <span @class([
                                                'text-[11px] font-bold whitespace-nowrap tabular-nums',
                                                'text-funding' => $event['state'] === 'received',
                                                'text-status-critical' => in_array($event['state'], ['overdue', 'failed'], true),
                                                'text-status-warn' => $event['state'] === 'pending',
                                            ])>{{ number_format($event['amount'], 0) }} {{ $event['currency'] }}</span>
                                            <span class="text-[10px] whitespace-nowrap tabular-nums {{ $mutedText }}">{{ $event['date']->translatedFormat(__('formats.date_short')) }}</span>
                                            @if ($event['lane'] > 0)
                                                <span class="-my-1 w-px bg-secondary-light {{ $event['anchor'] === 'start' ? 'ms-[0.3125rem]' : ($event['anchor'] === 'end' ? 'me-[0.3125rem]' : '') }}" style="height: {{ $event['lane'] * 2 }}rem" aria-hidden="true"></span>
                                            @endif
                                            <span @class([
                                                'size-2.5 shrink-0 rounded-full',
                                                'bg-funding' => $event['state'] === 'received',
                                                'bg-primary dark:bg-status-critical' => in_array($event['state'], ['overdue', 'failed'], true),
                                                'border-2 border-status-warn bg-white dark:bg-surface-1' => $event['state'] === 'pending',
                                            ]) aria-hidden="true"></span>
                                        </span>
                                    </li>
                                @endforeach
                            </ol>
                        @endif

                        {{--
                            The spine: the grant's own term on the shared scale, and
                            the handle that reads the grant on any single day. It is
                            a slider, so the whole scale is reachable from the
                            keyboard; the page states every figure without it.
                        --}}
                        <div
                            class="group relative -my-3 cursor-ew-resize touch-none py-3 select-none focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-secondary-medium"
                            role="slider"
                            tabindex="0"
                            aria-valuemin="0"
                            aria-valuemax="{{ $arrivals['scrub']['days'] }}"
                            :aria-valuenow="day"
                            :aria-valuetext="readout.date"
                            aria-label="{{ __('proposals.read_the_grant_on_a_day') }}"
                            x-ref="spine"
                            x-on:pointerdown="scrubStart($event)"
                            x-on:pointermove="scrubMove($event)"
                            x-on:pointerup="scrubEnd($event)"
                            x-on:pointercancel="scrubEnd($event)"
                            x-on:keydown="scrubKey($event)"
                            x-on:blur="scrubbing = false"
                        >
                            <div class="relative h-2 w-full rounded-full bg-secondary-soft dark:bg-surface-2">
                                <span
                                    class="absolute inset-y-0 rounded-full bg-secondary-dark dark:bg-ink-muted"
                                    style="inset-inline-start: {{ $chronology['termStart'] }}%; width: {{ $chronology['termWidth'] }}%"
                                    role="img"
                                    aria-label="{{ __('proposals.grant_term_from_to', ['from' => $term['start']->translatedFormat(__('formats.date')), 'to' => $term['end']->translatedFormat(__('formats.date'))]) }}"
                                ></span>
                                <span
                                    class="chrono-handle absolute top-1/2 size-3.5 rounded-full border-2 border-white bg-secondary-dark opacity-0 transition-opacity duration-150 group-hover:opacity-100 group-focus-visible:opacity-100 dark:border-surface-1 dark:bg-ink"
                                    :class="scrubbing && 'opacity-100!'"
                                    :style="`inset-inline-start: ${percent}%`"
                                    aria-hidden="true"
                                ></span>
                            </div>
                        </div>

                        {{-- Work below the line. --}}
                        <ul class="mt-3 flex flex-col gap-2" aria-label="{{ __('common.projects') }}">
                            @forelse ($chronology['projects'] as $bar)
                                <li class="relative h-7" wire:key="chrono-project-{{ $bar['id'] }}">
                                    <a
                                        href="{{ route('projects.show', tap(new Project, fn ($project) => $project->id = $bar['id'])) }}"
                                        wire:navigate
                                        class="absolute top-0 flex h-7 min-w-0 items-center rounded-sm bg-funding-soft px-2 text-[11px] font-semibold text-funding transition-colors hover:bg-funding hover:text-white dark:hover:text-surface-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium dark:text-ink"
                                        style="inset-inline-start: {{ $bar['start'] }}%; width: {{ $bar['width'] }}%"
                                    >
                                        <span class="truncate">{{ $bar['name'] }}</span>
                                    </a>
                                </li>
                            @empty
                                <li class="text-xs {{ $mutedText }}">{{ __('proposals.no_projects_linked') }}</li>
                            @endforelse
                        </ul>

                        {{-- Status moves on the same scale. --}}
                        @if ($chronology['moves'] !== [])
                            <ol class="relative mt-4" style="height: {{ 2.25 + collect($chronology['moves'])->max('lane') * 1.25 }}rem" aria-label="{{ __('proposals.status_changes') }}">
                                @foreach ($chronology['moves'] as $move)
                                    <li @class([
                                        'absolute top-0',
                                        '-translate-x-1/2 rtl:translate-x-1/2' => $move['anchor'] === 'center',
                                        '-translate-x-full rtl:translate-x-full' => $move['anchor'] === 'end',
                                    ]) style="inset-inline-start: {{ $move['at'] }}%" wire:key="chrono-move-{{ $loop->index }}" data-lane="{{ $move['lane'] }}">
                                        <span @class(['flex flex-col gap-1', 'items-center' => $move['anchor'] === 'center', 'items-start' => $move['anchor'] === 'start', 'items-end' => $move['anchor'] === 'end'])>
                                            <span class="size-2 shrink-0 rounded-full bg-secondary-medium dark:bg-ink-muted" aria-hidden="true"></span>
                                            @if ($move['lane'] > 0)
                                                <span class="-my-1 w-px bg-secondary-light {{ $move['anchor'] === 'start' ? 'ms-1' : ($move['anchor'] === 'end' ? 'me-1' : '') }}" style="height: {{ $move['lane'] * 1.25 }}rem" aria-hidden="true"></span>
                                            @endif
                                            <span class="text-[10px] font-semibold whitespace-nowrap {{ $mutedText }}">{{ $move['status']->label() }}</span>
                                        </span>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </div>

                    {{--
                        The readout. It states today's position on its own, and
                        answers for whichever day the spine is scrubbed to — so the
                        question "had the money landed before that work was due?"
                        is read off the page rather than worked out from it.
                    --}}
                    @if ($arrivals['climbs'] !== [])
                        <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-4 border-t pt-5 {{ $hairline }} @2xl/detail:grid-cols-4" data-chrono-readout>
                            <div>
                                <dt class="type-label {{ $mutedText }}" x-text="scrubbing ? @js(__('proposals.as_of_that_day')) : @js(__('common.today'))">{{ __('common.today') }}</dt>
                                <dd class="mt-1 text-sm font-bold tabular-nums {{ $inkText }}" x-text="readout.date">{{ \Carbon\CarbonImmutable::today()->translatedFormat(__('formats.date')) }}</dd>
                            </div>
                            <div>
                                <dt class="type-label {{ $mutedText }}">{{ __('proposals.received_by_then') }}</dt>
                                <dd class="mt-1 text-sm font-bold tabular-nums text-funding">
                                    <span x-text="readout.received">{{ number_format($arrivals['climbs'][0]['landed'], 0) }}</span>
                                    <span class="text-[11px] font-semibold {{ $mutedText }}" x-text="currency">{{ $arrivals['climbs'][0]['currency'] }}</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="type-label {{ $mutedText }}">{{ __('proposals.still_owed_then') }}</dt>
                                <dd class="mt-1 text-sm font-bold tabular-nums {{ $inkText }}">
                                    <span x-text="readout.owed">{{ number_format(max(0, $arrivals['climbs'][0]['committed'] - $arrivals['climbs'][0]['landed']), 0) }}</span>
                                    <span class="text-[11px] font-semibold {{ $mutedText }}" x-text="currency">{{ $arrivals['climbs'][0]['currency'] }}</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="type-label {{ $mutedText }}">{{ __('proposals.projects_running_then') }}</dt>
                                <dd class="mt-1 text-sm font-bold tabular-nums {{ $inkText }}" x-text="readout.running">{{ $this->projects->count() }}/{{ $this->projects->count() }}</dd>
                            </div>
                        </dl>
                        <p class="mt-2 text-[11px] {{ $mutedText }}">{{ __('proposals.scrub_hint') }}</p>
                    @endif

                    <dl class="mt-8 grid grid-cols-2 gap-x-6 gap-y-4 border-t pt-5 {{ $hairline }} @2xl/detail:grid-cols-4">
                        <div>
                            <dt class="type-label {{ $mutedText }}">{{ __('proposals.planned_start') }}</dt>
                            <dd class="mt-1 text-sm font-bold tabular-nums {{ $inkText }}">{{ $this->proposal->planned_date_begin?->translatedFormat(__('formats.date')) ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="type-label {{ $mutedText }}">{{ __('proposals.planned_end') }}</dt>
                            <dd class="mt-1 text-sm font-bold tabular-nums {{ $inkText }}">{{ $this->proposal->planned_date_end?->translatedFormat(__('formats.date')) ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="type-label {{ $mutedText }}">{{ __('proposals.actual_start') }}</dt>
                            <dd class="mt-1 text-sm font-bold tabular-nums {{ $inkText }}">{{ $this->proposal->actual_date_begin?->translatedFormat(__('formats.date')) ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="type-label {{ $mutedText }}">{{ __('proposals.actual_end') }}</dt>
                            <dd class="mt-1 text-sm font-bold tabular-nums {{ $inkText }}">{{ $this->proposal->actual_date_end?->translatedFormat(__('formats.date')) ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
