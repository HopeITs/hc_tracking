@props(['projects', 'window', 'statusSegments', 'search' => '', 'status' => ''])

@php
    use App\Enums\MilestoneStatus;
    use App\Enums\ProjectStatus;
    use App\Enums\RagStatus;
    use Carbon\CarbonImmutable;

    $today = CarbonImmutable::today();
    $windowStart = $window['start'] ?? null;
    $windowSpan = $window === null ? 0 : max(1, $window['end']->getTimestamp() - $windowStart->getTimestamp());

    /** Position of a date along the window, as a clamped percentage. */
    $position = fn (CarbonImmutable $date): float => $window === null ? 0.0 : round(min(100, max(0, ($date->getTimestamp() - $windowStart->getTimestamp()) / $windowSpan * 100)), 3);

    $ticks = [];
    if ($window !== null) {
        $months = (int) $windowStart->diffInMonths($window['end']) + 1;
        $step = max(1, (int) ceil($months / 8));
        for ($tick = $windowStart; $tick <= $window['end']; $tick = $tick->addMonthsNoOverflow($step)) {
            $ticks[] = ['label' => $tick->translatedFormat(__('formats.month_year')), 'at' => $position($tick), 'nearToday' => abs($position($tick) - $position($today)) < 7];
        }
    }
    $todayAt = $position($today);
    $totalProjects = array_sum(array_column($statusSegments, 'count'));

    $markerTone = fn ($milestone): string => match (true) {
        $milestone->status === MilestoneStatus::Reached => 'border-status-good bg-status-good',
        $milestone->isMissed() || $milestone->isOverdue() => 'border-primary bg-primary',
        $milestone->status === MilestoneStatus::Ongoing => 'border-status-warn bg-status-warn',
        default => 'border-secondary-dark bg-board dark:border-white',
    };
@endphp

<section {{ $attributes->class('overflow-hidden rounded-md border border-board-line bg-board') }} aria-labelledby="program-projects-heading">
    <header class="flex flex-col gap-4 border-b border-board-line bg-board-head px-5 py-5">
        <div class="flex flex-col gap-4 @4xl/detail:flex-row @4xl/detail:items-end @4xl/detail:justify-between">
            <div class="max-w-xl">
                <h2 id="program-projects-heading" class="text-xl font-bold text-balance">{{ __('common.projects') }}</h2>
                <p class="mt-1 text-sm text-secondary-medium dark:text-secondary-soft">{{ __('programs.timeline_description') }}</p>
            </div>

            <label class="block @4xl/detail:w-80">
                <span class="sr-only">{{ __('programs.find_a_project') }}</span>
                <span class="flex min-h-10 items-center gap-2 rounded-md border border-board-line bg-board px-3 focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/15">
                    <flux:icon.magnifying-glass variant="micro" class="size-4 shrink-0 text-secondary-light" aria-hidden="true" />
                    <input type="search" wire:model.live.debounce.300ms="projectSearch" placeholder="{{ __('programs.find_a_project') }}" class="min-w-0 flex-1 bg-transparent py-2 text-sm outline-none placeholder:text-secondary-light" />
                </span>
            </label>
        </div>

        <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('programs.timeline_filter_status') }}">
            <button type="button" wire:click="filterProjectsByStatus('')" aria-pressed="{{ $status === '' ? 'true' : 'false' }}" @class([
                'inline-flex cursor-pointer items-center gap-2 rounded-full border py-1 ps-3 pe-2 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                'border-secondary-dark bg-secondary-dark text-white dark:border-white dark:bg-white dark:text-secondary-dark' => $status === '',
                'border-board-line bg-board hover:border-secondary-light' => $status !== '',
            ])>
                {{ __('programs.timeline_all') }}
                <span class="rounded-full bg-black/10 px-1.5 text-xs tabular-nums dark:bg-white/15">{{ $totalProjects }}</span>
            </button>

            @foreach (ProjectStatus::cases() as $index => $case)
                @php $segment = $statusSegments[$index]; @endphp
                @continue($segment['count'] === 0 && $status !== $case->value)
                <button type="button" wire:click="filterProjectsByStatus('{{ $case->value }}')" aria-pressed="{{ $status === $case->value ? 'true' : 'false' }}" @class([
                    'inline-flex cursor-pointer items-center gap-2 rounded-full border py-1 ps-2.5 pe-2 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                    'border-secondary-dark bg-secondary-dark text-white dark:border-white dark:bg-white dark:text-secondary-dark' => $status === $case->value,
                    'border-board-line bg-board hover:border-secondary-light' => $status !== $case->value,
                ])>
                    <span aria-hidden="true" class="size-2 rounded-full {{ $segment['tone'] }}"></span>
                    {{ $segment['label'] }}
                    <span class="rounded-full bg-black/10 px-1.5 text-xs tabular-nums dark:bg-white/15">{{ $segment['count'] }}</span>
                </button>
            @endforeach
        </div>
    </header>

    @if ($projects->isEmpty())
        <x-ui.empty-state
            icon="rectangle-stack"
            :heading="match (true) {
                $search === '' && $status === '' => __('common.no_projects_yet'),
                $status === '' => __('programs.no_projects_match_search'),
                default => __('programs.timeline_no_match'),
            }"
            :body="match (true) {
                $search === '' && $status === '' => __('programs.projects_delivered_under_this_program_will_appear_here'),
                $status === '' => __('programs.adjust_your_project_search'),
                default => __('programs.timeline_adjust_filters'),
            }"
        />
    @else
        <div class="bg-ground" wire:loading.class="opacity-60" wire:target="projectSearch, filterProjectsByStatus, gotoPage, previousPage, nextPage">
            {{-- Legend --}}
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-board-line px-5 py-3 type-meta text-secondary-medium dark:text-secondary-soft" aria-hidden="true">
                <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-status-good bg-status-good"></span>{{ MilestoneStatus::Reached->label() }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-secondary-dark bg-board dark:border-white"></span>{{ MilestoneStatus::Planned->label() }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-status-warn bg-status-warn"></span>{{ MilestoneStatus::Ongoing->label() }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rotate-45 rounded-[2px] border-2 border-primary bg-primary"></span>{{ __('milestones.missed') }} / {{ __('milestones.overdue') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-4 border-t-2 border-dashed border-status-warn"></span>{{ __('programs.timeline_outside_legend') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="relative flex h-3.5 w-7 rounded-sm border border-primary/40 bg-primary/10"><span class="h-full w-3 rounded-s-sm bg-primary"></span><span class="absolute -inset-y-1 start-4 w-0.5 bg-primary"></span></span>{{ __('programs.timeline_legend_progress') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3.5 w-0.5 bg-primary"></span>{{ __('programs.timeline_today') }}</span>
            </div>

            {{-- Time axis (wide layout only) --}}
            @if ($window !== null)
                <div class="hidden grid-cols-[minmax(15rem,20rem)_1fr_10rem] gap-8 border-b border-board-line px-5 @4xl/detail:grid">
                    <span></span>
                    <div class="relative h-9">
                        @foreach ($ticks as $tick)
                            @continue($tick['nearToday'])
                            <span class="absolute bottom-2 -translate-x-1/2 type-meta whitespace-nowrap text-secondary-medium tabular-nums rtl:translate-x-1/2 dark:text-secondary-soft" style="inset-inline-start: {{ $tick['at'] }}%">{{ $tick['label'] }}</span>
                        @endforeach
                        <span class="absolute bottom-1.5 -translate-x-1/2 rounded-full bg-primary px-2 py-0.5 text-[0.6875rem] font-bold whitespace-nowrap text-white rtl:translate-x-1/2" style="inset-inline-start: {{ $todayAt }}%">{{ __('programs.timeline_today') }}</span>
                    </div>
                    <span></span>
                </div>
            @endif

            <ol class="divide-y divide-board-line">
                @foreach ($projects as $project)
                    @php
                        $weightTotal = (float) ($project->weight_total ?? 0);
                        $progress = $weightTotal > 0 ? (int) min(100, round((float) $project->weight_reached / $weightTotal * 100)) : null;
                        $openRisks = $project->risks_count - $project->materialised_risks_count;
                        $milestoneCount = $project->milestones->count();
                        $reachedMilestones = $project->milestones->where('status', MilestoneStatus::Reached)->count();
                        $hasDates = $project->start_date !== null && $project->end_date !== null && $project->end_date->gte($project->start_date);
                        $hasWindow = $window !== null && $hasDates;
                        $barStart = $hasWindow ? $position($project->start_date) : 0;
                        $barEnd = $hasWindow ? $position($project->end_date) : 0;
                        $barWidth = max(1.5, $barEnd - $barStart);
                        $isLive = $project->status === ProjectStatus::Active;
                        $isFinished = in_array($project->status, [ProjectStatus::Closed, ProjectStatus::Archived], true);
                        $isPreparing = ! $isLive && ! $isFinished;

                        $elapsed = null;
                        if ($hasDates) {
                            $span = max(1, $project->end_date->getTimestamp() - $project->start_date->getTimestamp());
                            $elapsed = min(100, max(0, ($today->getTimestamp() - $project->start_date->getTimestamp()) / $span * 100));
                        }

                        $neutralTone = 'border-board-line bg-board text-secondary-medium dark:text-secondary-soft';
                        $criticalTone = 'border-primary/30 bg-status-critical-soft text-primary';
                        $schedule = match (true) {
                            $isFinished => ['label' => $progress === null ? $project->status->label() : __('programs.timeline_closed_at', ['percent' => $progress]), 'tone' => $neutralTone, 'icon' => 'archive-box'],
                            $hasDates && $today->gt($project->end_date) && ($progress ?? 0) < 100 => ['label' => __('programs.timeline_past_end'), 'tone' => $criticalTone, 'icon' => 'calendar'],
                            $isPreparing => ['label' => __('programs.timeline_not_started'), 'tone' => $neutralTone, 'icon' => 'clock'],
                            $progress === null => ['label' => __('programs.timeline_unmeasured'), 'tone' => $neutralTone, 'icon' => 'minus-circle'],
                            $progress >= 100 => ['label' => __('programs.timeline_complete'), 'tone' => 'border-status-good/30 bg-status-good-soft text-status-good', 'icon' => 'check-circle'],
                            $elapsed === null => ['label' => __('programs.timeline_no_window'), 'tone' => $neutralTone, 'icon' => 'calendar'],
                            $today->lt($project->start_date) => ['label' => __('programs.timeline_not_started'), 'tone' => $neutralTone, 'icon' => 'clock'],
                            default => match (RagStatus::rate((float) $progress, (float) $elapsed)) {
                                RagStatus::Green => ['label' => __('programs.timeline_on_track'), 'tone' => 'border-status-good/30 bg-status-good-soft text-status-good', 'icon' => 'check-circle'],
                                RagStatus::Amber => ['label' => __('programs.timeline_slipping'), 'tone' => 'border-status-warn/30 bg-status-warn-soft text-status-warn', 'icon' => 'exclamation-triangle'],
                                RagStatus::Red => ['label' => __('programs.timeline_behind'), 'tone' => $criticalTone, 'icon' => 'exclamation-circle'],
                            },
                        };

                        $showExpected = $hasWindow && $isLive && $progress !== null && $progress < 100 && $elapsed !== null && $elapsed > 0;
                        $expectedLabel = $showExpected ? __('programs.timeline_expected', ['percent' => (int) round($elapsed)]) : null;
                        $fillTone = match (true) {
                            $isLive => 'bg-primary',
                            $isPreparing => 'bg-status-warn',
                            default => 'bg-secondary-light',
                        };
                        $trackTone = match (true) {
                            $isLive => 'border-primary/40 bg-primary/10',
                            $isPreparing => 'border-dashed border-status-warn/60 bg-status-warn-soft',
                            default => 'border-board-line bg-secondary-soft/60 dark:bg-secondary-medium/40',
                        };
                        $outside = $hasDates
                            ? $project->milestones->filter(fn ($milestone): bool => $milestone->planned_start_date->lt($project->start_date) || $milestone->planned_start_date->gt($project->end_date))
                            : collect();
                        $barLabel = ($progress === null ? __('programs.timeline_unmeasured') : $progress.'% '.__('programs.timeline_weighted_complete')).($expectedLabel === null ? '' : ' · '.$expectedLabel);
                    @endphp

                    <li class="group relative bg-board transition-colors duration-150 hover:bg-board-head" wire:key="program-project-{{ $project->id }}">
                        <div class="grid gap-4 px-5 py-4 @4xl/detail:grid-cols-[minmax(15rem,20rem)_1fr_10rem] @4xl/detail:items-center @4xl/detail:gap-8">
                            {{-- Identity --}}
                            <div class="min-w-0">
                                <a href="{{ route('projects.show', $project) }}" wire:navigate class="inline-flex max-w-full items-start gap-1.5 font-semibold text-pretty group-hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary after:absolute after:inset-0 after:content-['']">
                                    <span class="line-clamp-2">{{ $project->name }}</span>
                                    <flux:icon.arrow-up-right variant="micro" class="mt-1 size-3.5 shrink-0 opacity-0 transition-opacity duration-150 group-focus-within:opacity-100 group-hover:opacity-100 rtl:-scale-x-100" aria-hidden="true" />
                                </a>
                                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 type-meta text-secondary-medium dark:text-secondary-soft">
                                    <x-ui.enum-badge :case="$project->status" />
                                    <span class="inline-flex items-center gap-1"><flux:icon.map-pin variant="micro" class="size-3.5" aria-hidden="true" />{{ $project->city?->name ?? __('common.unknown_city') }}</span>
                                    <span class="inline-flex min-w-0 items-center gap-1"><flux:icon.user variant="micro" class="size-3.5 shrink-0" aria-hidden="true" /><span class="truncate">{{ $project->user?->name ?? __('common.not_assigned') }}</span></span>
                                </div>
                                <div class="mt-2 flex flex-wrap items-center gap-1.5 type-meta">
                                    <span @class(['inline-flex items-center gap-1 rounded-full border px-2 py-0.5', 'border-board-line text-secondary-medium dark:text-secondary-soft' => $openRisks === 0, 'border-status-warn/30 bg-status-warn-soft font-semibold text-status-warn' => $openRisks > 0])>
                                        <flux:icon.shield-exclamation variant="micro" class="size-3.5" aria-hidden="true" />{{ trans_choice('programs.timeline_open_risks', $openRisks, ['count' => $openRisks]) }}
                                    </span>
                                    @if ($project->materialised_risks_count > 0)
                                        <span class="inline-flex items-center gap-1 rounded-full border border-primary/30 bg-status-critical-soft px-2 py-0.5 font-semibold text-primary">
                                            <flux:icon.bolt variant="micro" class="size-3.5" aria-hidden="true" />{{ trans_choice('programs.timeline_materialised', $project->materialised_risks_count, ['count' => $project->materialised_risks_count]) }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            {{-- Delivery bar --}}
                            <div class="min-w-0">
                                <div class="relative h-11">
                                    @if ($window !== null)
                                        @foreach ($ticks as $tick)
                                            <span aria-hidden="true" class="absolute inset-y-0 hidden w-px bg-board-line @4xl/detail:block" style="inset-inline-start: {{ $tick['at'] }}%"></span>
                                        @endforeach
                                        <span aria-hidden="true" class="absolute inset-y-0 z-10 w-0.5 -translate-x-1/2 bg-primary/70 rtl:translate-x-1/2" style="inset-inline-start: {{ $todayAt }}%"></span>
                                    @endif

                                    @if ($hasWindow)
                                        <div class="absolute top-1/2 h-6 -translate-y-1/2 rounded-md border {{ $trackTone }}" style="inset-inline-start: {{ $barStart }}%; width: {{ $barWidth }}%" role="img" aria-label="{{ $barLabel }}">
                                            <div class="h-full overflow-hidden rounded-[inherit]">
                                                <div class="h-full {{ $fillTone }}" style="width: {{ $progress ?? 0 }}%"></div>
                                            </div>
                                        </div>

                                        @foreach ($outside as $milestone)
                                            @php
                                                $markerAt = $position($milestone->planned_start_date);
                                                $anchorAt = $milestone->planned_start_date->gt($project->end_date) ? $barEnd : $barStart;
                                            @endphp
                                            <span aria-hidden="true" class="absolute top-1/2 z-10 border-t-2 border-dashed border-status-warn" style="inset-inline-start: {{ min($markerAt, $anchorAt) }}%; width: {{ abs($markerAt - $anchorAt) }}%"></span>
                                        @endforeach

                                        @foreach ($project->milestones as $milestone)
                                            @php
                                                $isOutside = $outside->contains($milestone);
                                                $statusLabel = $milestone->isMissed() ? __('milestones.missed') : $milestone->status->label();
                                            @endphp
                                            @php
                                                $markerDetails = __('programs.timeline_marker', ['name' => $milestone->name, 'date' => $milestone->planned_start_date->translatedFormat(__('formats.date')), 'status' => $statusLabel]).($isOutside ? ' · '.__('programs.timeline_outside_legend') : '');
                                            @endphp
                                            <button
                                                type="button"
                                                class="absolute top-1/2 z-20 flex size-6 -translate-x-1/2 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full focus-visible:outline-2 focus-visible:outline-primary rtl:translate-x-1/2"
                                                style="inset-inline-start: {{ $position($milestone->planned_start_date) }}%"
                                                x-data="{ open: false }"
                                                x-on:mouseenter="open = true"
                                                x-on:mouseleave="open = false"
                                                x-on:focus="open = true"
                                                x-on:blur="open = false"
                                                x-on:click.prevent.stop="open = ! open"
                                                x-on:keydown.escape="open = false"
                                                aria-label="{{ $markerDetails }}"
                                            >
                                                <span aria-hidden="true" class="block size-3 rotate-45 rounded-[2px] border-2 shadow-sm {{ $isOutside ? 'ring-2 ring-status-warn ring-offset-1 ring-offset-board' : 'ring-2 ring-board' }} {{ $markerTone($milestone) }}"></span>
                                                <span
                                                    x-show="open"
                                                    style="display: none"
                                                    role="tooltip"
                                                    class="pointer-events-none absolute bottom-full start-1/2 z-30 mb-1.5 w-max max-w-56 -translate-x-1/2 rounded-md bg-secondary-dark px-2.5 py-1.5 text-start text-xs leading-snug text-white shadow-lg rtl:translate-x-1/2 dark:bg-white dark:text-secondary-dark"
                                                >
                                                    <span class="block font-semibold">{{ $milestone->name }}</span>
                                                    <span class="block opacity-80">{{ $milestone->planned_start_date->translatedFormat(__('formats.date')) }} · {{ $statusLabel }}</span>
                                                    @if ($isOutside)
                                                        <span class="block font-semibold text-status-warn-soft dark:text-status-warn">{{ __('programs.timeline_outside_legend') }}</span>
                                                    @endif
                                                </span>
                                            </button>
                                        @endforeach
                                    @else
                                        <span class="absolute inset-x-0 top-1/2 flex h-7 -translate-y-1/2 items-center justify-center rounded-md border border-dashed border-secondary-light bg-board type-meta text-secondary-medium dark:text-secondary-soft">{{ __('programs.timeline_no_window') }}</span>
                                    @endif
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 type-meta text-secondary-medium tabular-nums dark:text-secondary-soft">
                                    <span>{{ $project->start_date?->translatedFormat(__('formats.date')) ?? '—' }} <span aria-hidden="true">–</span> {{ $project->end_date?->translatedFormat(__('formats.date')) ?? '—' }}</span>
                                    @if ($showExpected)
                                        <span>{{ $expectedLabel }}</span>
                                    @endif
                                    @if ($outside->isNotEmpty())
                                        <span class="inline-flex items-center gap-1 font-semibold text-status-warn"><flux:icon.exclamation-triangle variant="micro" class="size-3.5" aria-hidden="true" />{{ trans_choice('programs.timeline_outside_window', $outside->count(), ['count' => $outside->count()]) }}</span>
                                    @endif
                                </div>
                            </div>

                            {{-- Schedule verdict and progress --}}
                            <div class="flex items-end justify-between gap-4 @4xl/detail:flex-col @4xl/detail:items-end @4xl/detail:gap-2">
                                <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 type-meta font-semibold whitespace-nowrap {{ $schedule['tone'] }}">
                                    <flux:icon :name="$schedule['icon']" variant="micro" class="size-3.5" aria-hidden="true" />{{ $schedule['label'] }}
                                </span>
                                <div class="text-end">
                                    <p class="text-2xl leading-none font-bold tabular-nums">{{ $progress === null ? '—' : $progress.'%' }}</p>
                                    <p class="mt-1 type-meta font-semibold text-secondary-dark dark:text-white">{{ __('programs.timeline_weighted_complete') }}</p>
                                    <p class="type-meta text-secondary-medium tabular-nums dark:text-secondary-soft">{{ trans_choice('programs.timeline_milestones_reached', $milestoneCount, ['count' => $milestoneCount, 'reached' => $reachedMilestones]) }}</p>
                                </div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>

        @if ($projects->hasPages())
            <div class="border-t border-board-line px-5 py-3">
                <flux:pagination :paginator="$projects" scroll-to="#program-projects-heading" />
            </div>
        @endif
    @endif
</section>
