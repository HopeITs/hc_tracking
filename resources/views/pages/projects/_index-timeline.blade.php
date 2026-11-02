{{--
    The timeline view of projects/index: every project's window plotted against
    the shared date axis. Included by ⚡index.blade.php, whose top PHP block
    defines $projects, $today, $scheduleOf and $elapsedOf. Each row is the
    project, its health word, its bar and its milestones; money and links stay
    on the list view's preview and the project page.
--}}
@use('App\Enums\MilestoneStatus')
@use('App\Enums\ProjectStatus')
@use('Carbon\CarbonImmutable')
                @php
                    $window = $this->timelineWindow;
                    $windowStart = $window['start'] ?? null;
                    $windowSpan = $window === null ? 1 : max(1, $window['end']->getTimestamp() - $windowStart->getTimestamp());
                    $position = fn (CarbonImmutable $date): float => $window === null
                        ? 0.0
                        : round(min(100, max(0, ($date->getTimestamp() - $windowStart->getTimestamp()) / $windowSpan * 100)), 3);

                    $ticks = [];
                    if ($window !== null) {
                        $months = (int) $windowStart->diffInMonths($window['end']) + 1;
                        $step = max(1, (int) ceil($months / 8));
                        for ($tick = $windowStart; $tick <= $window['end']; $tick = $tick->addMonthsNoOverflow($step)) {
                            $ticks[] = ['label' => $tick->translatedFormat(__('formats.month_year')), 'at' => $position($tick), 'nearToday' => abs($position($tick) - $position($today)) < 7];
                        }
                    }
                    $todayAt = $position($today);
                    $timelineColumns = '@4xl/directory:grid-cols-[minmax(15rem,19rem)_minmax(0,1fr)_7rem]';
                @endphp

                <x-ui.panel>
                    {{-- Same legend grammar as the milestones timeline. --}}
                    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-secondary-soft px-4 py-3 text-xs text-secondary-medium dark:border-line dark:text-ink-muted" aria-hidden="true">
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-secondary-dark bg-secondary-dark dark:border-ink-muted dark:bg-ink-muted"></span>{{ MilestoneStatus::Reached->label() }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-secondary-dark bg-white dark:border-ink-muted dark:bg-surface-1"></span>{{ MilestoneStatus::Planned->label() }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-primary bg-white dark:bg-surface-1"></span>{{ __('programs.timeline_overdue') }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-primary bg-primary dark:bg-status-critical dark:border-status-critical"></span>{{ __('milestones.missed') }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-3.5 w-0.5 bg-primary dark:bg-ink"></span>{{ __('programs.timeline_today') }}</span>
                    </div>

                    @if ($window !== null)
                        <div class="hidden gap-6 border-b border-secondary-soft px-4 {{ $timelineColumns }} @4xl/directory:grid dark:border-line">
                            <span></span>
                            <div class="relative h-9">
                                @foreach ($ticks as $tick)
                                    @continue($tick['nearToday'])
                                    <span class="absolute bottom-2 -translate-x-1/2 text-xs whitespace-nowrap tabular-nums text-secondary-light rtl:translate-x-1/2 dark:text-ink-faint" style="inset-inline-start: {{ $tick['at'] }}%">{{ $tick['label'] }}</span>
                                @endforeach
                                <span class="absolute bottom-1.5 -translate-x-1/2 rounded-full bg-primary dark:bg-ink dark:text-surface-0 px-2 py-0.5 text-[0.6875rem] font-bold whitespace-nowrap text-white rtl:translate-x-1/2" style="inset-inline-start: {{ $todayAt }}%">{{ __('programs.timeline_today') }}</span>
                            </div>
                            <span></span>
                        </div>
                    @endif

                    <ol class="divide-y divide-secondary-soft dark:divide-line">
                        @foreach ($projects as $project)
                            @php
                                $progress = $this->progressFor($project);
                                $schedule = $scheduleOf($project);
                                $elapsed = $elapsedOf($project);
                                $healthClass = match ($schedule['tone']) {
                                    'good' => 'dk-good',
                                    'warn' => 'dk-warn',
                                    'critical' => 'dk-bad',
                                    default => 'dk-calm',
                                };
                                $hasDates = $project->start_date !== null && $project->end_date !== null && $project->end_date->gte($project->start_date);
                                $hasWindow = $window !== null && $hasDates;
                                $barStart = $hasWindow ? $position($project->start_date) : 0;
                                $barWidth = $hasWindow ? max(1.5, $position($project->end_date) - $barStart) : 0;
                                $isLive = $project->status === ProjectStatus::Active;
                                $isFinished = in_array($project->status, [ProjectStatus::Closed, ProjectStatus::Archived], true);
                                $barLabel = ($progress === null ? __('programs.timeline_unmeasured') : $progress.'% '.__('programs.timeline_weighted_complete'))
                                    .($elapsed === null ? '' : ' · '.__('programs.timeline_expected', ['percent' => (int) round($elapsed)]));
                            @endphp

                            <li class="bg-white transition-colors hover:bg-secondary-soft/30 dark:bg-surface-1 dark:hover:bg-surface-2" wire:key="project-timeline-{{ $project->id }}">
                                <div class="grid gap-4 px-4 py-4 {{ $timelineColumns }} @4xl/directory:items-center @4xl/directory:gap-6">
                                    <div class="flex min-w-0 items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="line-clamp-2 text-sm font-semibold" title="{{ $project->name }}">{{ $project->name }}</p>
                                            <p class="mt-0.5 truncate text-xs text-secondary-medium dark:text-ink-muted">{{ $project->city?->name ?? __('common.unknown_city') }} · {{ $project->program->name }}</p>
                                            <span class="dk-health {{ $healthClass }} mt-1.5">{{ $schedule['label'] }}</span>
                                        </div>

                                        @include('pages.projects._index-row-actions')
                                    </div>

                                    <div class="min-w-0">
                                        <div class="relative h-11">
                                            @if ($window !== null)
                                                @foreach ($ticks as $tick)
                                                    <span aria-hidden="true" class="absolute inset-y-0 hidden w-px bg-secondary-soft @4xl/directory:block dark:bg-line" style="inset-inline-start: {{ $tick['at'] }}%"></span>
                                                @endforeach
                                                <span aria-hidden="true" class="absolute inset-y-0 z-10 w-0.5 -translate-x-1/2 bg-primary dark:bg-ink rtl:translate-x-1/2" style="inset-inline-start: {{ $todayAt }}%"></span>
                                            @endif

                                            @if ($hasWindow)
                                                <div
                                                    class="absolute top-1/2 h-6 -translate-y-1/2 overflow-hidden rounded-full border {{ $isFinished ? 'border-secondary-light bg-secondary-soft/60 dark:bg-white/10' : ($isLive ? 'border-secondary-light bg-secondary-soft dark:bg-surface-2' : 'border-dashed border-secondary-light bg-white dark:bg-surface-1') }}"
                                                    style="inset-inline-start: {{ $barStart }}%; width: {{ $barWidth }}%"
                                                    role="img"
                                                    aria-label="{{ $barLabel }}"
                                                >
                                                    <div class="h-full {{ $this->progressFillClasses($schedule['tone']) }}" style="width: {{ $progress ?? 0 }}%"></div>
                                                </div>

                                                @if ($elapsed !== null && $isLive)
                                                    <span aria-hidden="true" class="absolute top-1/2 h-8 w-0.5 -translate-x-1/2 -translate-y-1/2 bg-secondary-dark/40 rtl:translate-x-1/2 dark:bg-white/40" style="inset-inline-start: {{ round($barStart + ($barWidth * $elapsed / 100), 3) }}%"></span>
                                                @endif

                                                @foreach ($project->milestones as $milestone)
                                                    @php
                                                        $isMissedMarker = $milestone->isMissed();
                                                        $markerTone = match (true) {
                                                            $milestone->status === MilestoneStatus::Reached => 'border-secondary-dark bg-secondary-dark dark:border-ink-muted dark:bg-ink-muted',
                                                            $isMissedMarker || $milestone->isOverdue() => 'border-primary bg-primary dark:bg-status-critical dark:border-status-critical',
                                                            default => 'border-secondary-dark bg-white dark:border-ink-muted dark:bg-surface-1',
                                                        };
                                                        $markerLabel = __('programs.timeline_marker', [
                                                            'name' => $milestone->name,
                                                            'date' => $milestone->planned_start_date->translatedFormat(__('formats.date')),
                                                            'status' => $isMissedMarker ? __('milestones.missed') : $milestone->status->label(),
                                                        ]);
                                                    @endphp

                                                    <span
                                                        class="absolute top-1/2 z-20 block size-3 -translate-x-1/2 -translate-y-1/2 rotate-45 rounded-[2px] border-2 shadow-sm ring-2 ring-white rtl:translate-x-1/2 dark:ring-surface-1 {{ $markerTone }}"
                                                        style="inset-inline-start: {{ $position($milestone->planned_start_date) }}%"
                                                        role="img"
                                                        aria-label="{{ $markerLabel }}"
                                                        wire:key="timeline-marker-{{ $milestone->id }}"
                                                    ></span>
                                                @endforeach
                                            @else
                                                <span class="absolute inset-x-0 top-1/2 flex h-7 -translate-y-1/2 items-center justify-center rounded-full border border-dashed border-secondary-light bg-white text-xs text-secondary-medium dark:bg-surface-1 dark:text-ink-muted">{{ __('projects.unscheduled') }}</span>
                                            @endif
                                        </div>

                                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs tabular-nums text-secondary-medium dark:text-ink-muted">
                                            <span>
                                                {{ $project->start_date?->translatedFormat(__('formats.date')) ?? '—' }}
                                                <flux:icon.arrow-long-right variant="micro" class="inline size-3.5 rtl:-scale-x-100" aria-hidden="true" />
                                                {{ $project->end_date?->translatedFormat(__('formats.date')) ?? '—' }}
                                            </span>
                                        </div>
                                    </div>

                                    <div class="text-end">
                                        <p class="type-figure">{{ $progress === null ? '—' : round($progress).'%' }}</p>
                                        <p class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted">
                                            {{ $progress === null ? __('projects.no_weighted_milestones') : __('projects.milestones_reached', ['reached' => $project->reached_milestones_count, 'total' => $project->milestones_count]) }}
                                        </p>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </x-ui.panel>
