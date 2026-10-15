{{--
    The board view of projects/index: one panel per lifecycle state, one card
    per project. Included by ⚡index.blade.php, whose top PHP block defines
    $projects, $fundingByProject, $scheduleOf and the other helpers.
--}}
@use('App\Enums\ProjectStatus')
@use('App\Models\Project')
                @foreach ($projects->getCollection()->groupBy(fn (Project $project): string => $project->status->value) as $bandStatus => $bandProjects)
                    @php $bandCase = ProjectStatus::from($bandStatus); @endphp

                    <x-ui.panel :title="$bandCase->label()" :count="$bandProjects->count()" wire:key="project-band-{{ $bandStatus }}">
                        <div class="grid grid-cols-1 items-stretch gap-3 p-3 @2xl/directory:grid-cols-2 @5xl/directory:grid-cols-3">
                            @foreach ($bandProjects as $project)
                                @php
                                    $progress = $this->progressFor($project);
                                    $schedule = $scheduleOf($project);
                                    $funding = $fundingByProject[$project->id] ?? null;
                                    $breakdown = $project->targetDemographicTotal();
                                    $demographics = $this->demographicsOf($project);

                                    /* The progress fill carries the same verdict as the pill above it. */
                                    $progressTone = $schedule['tone'];

                                    /* Three label rows collapse into one breadcrumb; the labels move to the tooltip. */
                                    $projectPath = [
                                        $project->city?->name ?? __('common.unknown_city'),
                                        $project->program->name,
                                        $project->program->strategy?->name ?? __('common.strategy_not_assigned'),
                                    ];
                                    $pathTitle = implode(' · ', [
                                        __('common.city').': '.$projectPath[0],
                                        __('common.program').': '.$projectPath[1],
                                        __('common.strategy').': '.$projectPath[2],
                                    ]);

                                    $demographicsTitle = implode(' · ', array_map(
                                        fn (string $label, int $value): string => $label.' '.number_format($value),
                                        array_keys($demographics),
                                        array_values($demographics),
                                    ));

                                    $connections = $this->connectionsOf($project);
                                @endphp

                                <article
                                    @class([
                                        'group/card relative isolate flex h-full flex-col rounded-xl border bg-board p-4',
                                        'transition-[transform,box-shadow,border-color] duration-200 ease-[cubic-bezier(0.16,1,0.3,1)] motion-reduce:transition-none',
                                        'hover:-translate-y-0.5 hover:shadow-[0_10px_24px_-12px_rgb(16_24_40/0.28),0_2px_6px_-2px_rgb(16_24_40/0.10)] motion-reduce:hover:translate-y-0',
                                        'border-primary/40' => $schedule['tone'] === 'critical',
                                        'border-status-warn/45' => $schedule['tone'] === 'warn',
                                        'border-board-line' => ! in_array($schedule['tone'], ['critical', 'warn'], true),
                                    ])
                                    wire:key="project-card-{{ $project->id }}"
                                >
                                    {{-- Identity. Open and edit sit together as icon actions, exactly as on programs. --}}
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="flex min-w-0 items-center gap-1.5">
                                                @if (in_array($project->id, $this->unreadIds, true))
                                                    <span class="size-2 shrink-0 rounded-full bg-primary dark:bg-ink" data-unread-dot="project-{{ $project->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                                                @endif
                                                <h3 class="line-clamp-2 min-w-0 text-[15px] leading-snug font-semibold text-secondary-dark dark:text-ink" title="{{ $project->name }}">{{ $project->name }}</h3>
                                            </div>

                                            {{-- City, program and strategy as one breadcrumb instead of three label rows. --}}
                                            <p class="type-meta mt-1 truncate text-secondary-medium dark:text-ink-muted" title="{{ $pathTitle }}">
                                                {{ $projectPath[0] }} <span class="text-secondary-soft dark:text-ink-faint">&middot;</span> {{ $projectPath[1] }} <span class="text-secondary-soft dark:text-ink-faint">&middot;</span> {{ $projectPath[2] }}
                                            </p>
                                        </div>

                                        <div class="flex shrink-0 items-center gap-1">
                                            <a
                                                href="{{ route('projects.show', $project) }}"
                                                wire:navigate
                                                class="grid size-11 place-items-center rounded-md border-0! bg-transparent! text-secondary-dark! transition-colors hover:bg-secondary-soft! focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-ink! dark:hover:bg-surface-2! @3xl/directory:size-8!"
                                                aria-label="{{ __('projects.open_project', ['name' => $project->name]) }}"
                                            >
                                                <flux:icon.eye class="size-4.5" />
                                            </a>

                                            <x-ui.blocked-badge :project="$project" />
                                            @can('update', $project)<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! touch-manipulation @3xl/directory:size-8!" wire:click="openEditModal({{ $project->id }})" :aria-label="__('common.edit_name', ['name' => $project->name])" />@endcan
                                            @if ($project->status === ProjectStatus::Archived)
                                                @can('delete', $project)<x-ui.button tone="ghost" size="sm" icon="arrow-uturn-left" class="size-11! shrink-0 touch-manipulation @3xl/directory:size-8!" wire:click="restoreProject({{ $project->id }})" :aria-label="__('projects.restore_name', ['name' => $project->name])" />@endcan
                                            @else
                                                @can('delete', $project)<x-ui.button tone="ghost" size="sm" icon="archive-box" class="size-11! shrink-0 touch-manipulation @3xl/directory:size-8!" wire:click="confirmArchive({{ $project->id }})" :aria-label="__('projects.archive_name', ['name' => $project->name])" />@endcan
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Delivery. The verdict, the number and the window read as one signal. --}}
                                    <div class="mt-4">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs whitespace-nowrap {{ $this->tonePillClasses($schedule['tone']) }}">
                                                <flux:icon :name="$schedule['icon']" variant="micro" class="size-3.5" aria-hidden="true" />{{ $schedule['label'] }}
                                            </span>

                                            <p class="shrink-0 text-xl leading-none font-bold tracking-tight tabular-nums text-secondary-dark dark:text-ink">
                                                @if ($progress === null)
                                                    <span class="text-base font-semibold text-secondary-medium dark:text-ink-muted">&mdash;</span>
                                                @else
                                                    {{ round($progress) }}<span class="text-xs font-semibold text-secondary-medium dark:text-ink-muted">%</span>
                                                @endif
                                            </p>
                                        </div>

                                        <x-ui.progress-bar class="mt-2.5" :value="$progress" :tone="$progressTone" :label="__('projects.milestone_progress_for', ['name' => $project->name])" label-hidden />

                                        <div class="mt-2 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                            <p class="type-meta text-secondary-medium dark:text-ink-muted">
                                                {{ $progress === null ? __('projects.no_weighted_milestones') : __('projects.milestones_reached', ['reached' => $project->reached_milestones_count, 'total' => $project->milestones_count]) }}
                                            </p>
                                            <p class="type-meta shrink-0 tabular-nums text-secondary-medium dark:text-ink-muted">
                                                {{ $project->start_date?->translatedFormat(__('formats.date')) ?? '—' }}
                                                <flux:icon.arrow-long-right variant="micro" class="inline size-3.5 rtl:-scale-x-100" aria-hidden="true" />
                                                {{ $project->end_date?->translatedFormat(__('formats.date')) ?? '—' }}
                                            </p>
                                        </div>
                                    </div>

                                    {{-- Money and people: the two commitments, on one shared baseline. --}}
                                    <div class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1 border-t border-board-line pt-3.5">
                                        <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('projects.funding_envelope') }}</p>
                                        <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('projects.col_beneficiaries') }}</p>

                                        <div class="min-w-0">
                                            @if ($funding === null)
                                                <p class="text-base font-semibold text-secondary-medium dark:text-ink-muted">{{ $project->proposals_count === 0 ? __('projects.not_funded_yet') : '—' }}</p>
                                            @else
                                                @foreach ($funding['currencies'] as $cardCurrency => $cardTotal)
                                                    <p class="text-base text-secondary-dark dark:text-ink" wire:key="funding-{{ $project->id }}-{{ $cardCurrency }}">
                                                        <x-ui.money :amount="$cardTotal" :currency="$cardCurrency" />
                                                    </p>
                                                @endforeach
                                                <p class="type-meta mt-0.5 truncate text-secondary-medium dark:text-ink-muted" title="{{ implode(' · ', $funding['donors']) }}">{{ implode(' · ', $funding['donors']) }}</p>
                                                <p class="type-meta truncate text-secondary-medium dark:text-ink-muted" title="{{ implode(' · ', $funding['proposals']) }}">{{ implode(' · ', $funding['proposals']) }}</p>
                                            @endif
                                        </div>

                                        <div class="min-w-0">
                                            <p class="text-base font-semibold tabular-nums text-secondary-dark dark:text-ink">{{ $project->target_beneficiaries === null ? '—' : number_format($project->target_beneficiaries) }}</p>
                                            @if ($demographics !== [])
                                                <p class="type-meta mt-0.5 truncate tabular-nums text-secondary-medium dark:text-ink-muted" title="{{ $demographicsTitle }}">
                                                    @foreach ($demographics as $demographicLabel => $demographicValue)
                                                        {{ $demographicLabel }} {{ number_format($demographicValue) }}@if (! $loop->last) <span class="text-secondary-soft dark:text-ink-faint">&middot;</span> @endif
                                                    @endforeach
                                                </p>
                                            @endif
                                            @if ($breakdown !== null && $breakdown !== $project->target_beneficiaries)
                                                <p class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted">{{ __('projects.breakdown_count', ['count' => number_format($breakdown)]) }}</p>
                                            @endif
                                        </div>

                                        @if ($funding !== null && $funding['shared'] > 0)
                                            <span class="type-meta col-span-2 mt-1.5 inline-flex w-fit items-center gap-1 rounded-full border border-board-line px-2 py-0.5 text-secondary-medium dark:text-ink-muted">
                                                <flux:icon.arrows-right-left variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                                                {{ trans_choice('projects.shared_with_other_projects', $funding['shared'], ['count' => $funding['shared']]) }}
                                            </span>
                                        @endif
                                    </div>

                                    {{-- Footer: what this project is linked to, and who owns it. --}}
                                    <div class="mt-auto pt-4">
                                        <div class="flex items-center justify-between gap-3 border-t border-board-line pt-3.5">
                                            @if ($connections !== [])
                                                <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                                                    @foreach ($connections as $connection)
                                                        <span
                                                            @class([
                                                                'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs tabular-nums',
                                                                'border-primary bg-primary dark:border-transparent dark:bg-status-critical-soft dark:text-status-critical font-semibold text-white' => $connection['alert'],
                                                                'border-board-line text-secondary-medium dark:text-ink-muted' => ! $connection['alert'],
                                                            ])
                                                            title="{{ $connection['label'] }}"
                                                        >
                                                            <flux:icon :name="$connection['icon']" variant="micro" class="size-3.5" aria-hidden="true" />
                                                            <span aria-hidden="true">{{ $connection['count'] }}</span>
                                                            <span class="sr-only">{{ $connection['label'] }}</span>
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @else
                                                <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('projects.no_connections_yet') }}</p>
                                            @endif

                                            <div class="flex shrink-0 flex-wrap items-center justify-end gap-1.5">
                                                <x-ui.employee-chip :employee="$project->user" compact />
                                                <x-ui.assignment-badge :project="$project" />
                                                <x-ui.project-funding-badge :project="$project" />
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </x-ui.panel>
                @endforeach
