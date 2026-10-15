{{--
    The table view of projects/index: one row per project. Included by
    ⚡index.blade.php, whose top PHP block defines $projects, $fundingByProject
    and $scheduleOf.
--}}
@use('App\Enums\ProjectStatus')
                <x-ui.data-table :caption="__('projects.project_directory')">
                    <x-slot:head>
                        <th scope="col" class="w-32">{{ __('projects.col_scheduled') }}</th>
                        <th scope="col">{{ __('common.projects') }}</th>
                        <th scope="col" class="w-44">{{ __('projects.col_progress') }}</th>
                        <th scope="col" class="w-32 text-end! @max-6xl:hidden">{{ __('projects.col_beneficiaries') }}</th>
                        <th scope="col" class="w-44 text-end! @max-5xl:hidden">{{ __('projects.col_funding') }}</th>
                        <th scope="col" class="w-44 @max-6xl:hidden">{{ __('projects.connections') }}</th>
                        <th scope="col" class="w-40 @max-4xl:hidden">{{ __('projects.owner') }}</th>
                        <th scope="col" class="w-44 @max-6xl:hidden">{{ __('sectors.sectors') }}</th>
                        <th scope="col" class="w-36">{{ __('projects.col_schedule') }}</th>
                        <th scope="col" class="w-28">{{ __('common.status') }}</th>
                        <th scope="col" class="w-28"><span class="sr-only">{{ __('common.actions') }}</span></th>
                    </x-slot:head>

                    @foreach ($projects as $project)
                        @php
                            $progress = $this->progressFor($project);
                            $schedule = $scheduleOf($project);
                            $funding = $fundingByProject[$project->id] ?? null;
                            $breakdown = $project->targetDemographicTotal();
                            $connections = $this->connectionsOf($project);
                        @endphp

                        <tr wire:key="project-{{ $project->id }}">
                            <x-ui.data-table.cell :label="__('projects.col_scheduled')">
                                <span class="block text-xs tabular-nums text-secondary-medium dark:text-ink-muted">{{ $project->start_date?->translatedFormat(__('formats.date')) ?? '—' }}</span>
                                <span class="block text-xs tabular-nums text-secondary-medium dark:text-ink-muted">
                                    <flux:icon.arrow-long-right variant="micro" class="inline size-3.5 rtl:-scale-x-100" aria-hidden="true" />
                                    {{ $project->end_date?->translatedFormat(__('formats.date')) ?? '—' }}
                                </span>
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell>
                                <span class="flex min-w-0 items-center gap-1.5">
                                    @if (in_array($project->id, $this->unreadIds, true))
                                        <span class="size-2 shrink-0 rounded-full bg-primary dark:bg-ink" data-unread-dot="project-{{ $project->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                                    @endif
                                    <span class="line-clamp-2 min-w-0 text-sm font-semibold" title="{{ $project->name }}">{{ $project->name }}</span>
                                </span>
                                <span class="block truncate text-xs text-secondary-medium dark:text-ink-muted">{{ $project->city?->name ?? __('common.unknown_city') }} · {{ $project->program->name }}</span>
                                <span class="block truncate text-xs text-secondary-medium dark:text-ink-muted">{{ $project->program->strategy?->name ?? __('common.strategy_not_assigned') }}</span>
                                <span class="hidden truncate text-xs tabular-nums text-secondary-medium @max-6xl:block @max-3xl:hidden! dark:text-ink-muted">
                                    {{ $project->target_beneficiaries === null ? '—' : number_format($project->target_beneficiaries) }}
                                    @foreach ($connections as $connection)
                                        <span aria-hidden="true"> · </span><span aria-label="{{ $connection['label'] }}" class="{{ $connection['alert'] ? 'font-semibold text-primary dark:text-brand-text' : '' }}">{{ $connection['count'] }}</span>
                                    @endforeach
                                </span>
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell :label="__('projects.col_progress')">
                                <x-ui.progress-bar :value="$progress" :tone="$schedule['tone']" :label="__('projects.milestone_progress_for', ['name' => $project->name])" label-hidden :show-value="$progress !== null" />
                                <span class="mt-1 block text-xs tabular-nums text-secondary-medium dark:text-ink-muted">
                                    {{ $progress === null ? __('projects.no_weighted_milestones') : __('projects.milestones_reached', ['reached' => $project->reached_milestones_count, 'total' => $project->milestones_count]) }}
                                </span>
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell :label="__('projects.col_beneficiaries')" numeric class="@max-6xl:hidden @max-3xl:flex!">
                                <span class="block font-semibold">{{ $project->target_beneficiaries === null ? '—' : number_format($project->target_beneficiaries) }}</span>
                                @if ($breakdown !== null && $breakdown !== $project->target_beneficiaries)
                                    <span class="block text-xs text-secondary-medium dark:text-ink-muted">{{ __('projects.breakdown_count', ['count' => number_format($breakdown)]) }}</span>
                                @endif
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell :label="__('projects.col_funding')" numeric class="@max-5xl:hidden @max-3xl:flex!">
                                @if ($funding === null)
                                    <span class="text-xs text-secondary-light">{{ $project->proposals_count === 0 ? __('projects.not_funded_yet') : '—' }}</span>
                                @else
                                    @foreach ($funding['currencies'] as $rowCurrency => $rowTotal)
                                        <span class="block" wire:key="row-funding-{{ $project->id }}-{{ $rowCurrency }}">
                                            <x-ui.money :amount="$rowTotal" :currency="$rowCurrency" />
                                        </span>
                                    @endforeach
                                    <span class="block truncate text-xs text-secondary-medium dark:text-ink-muted" title="{{ implode(' · ', $funding['donors']) }}">{{ implode(' · ', $funding['donors']) }}</span>
                                    @if ($funding['shared'] > 0)
                                        <span class="block text-xs text-secondary-medium dark:text-ink-muted">{{ trans_choice('projects.shared_with_other_projects', $funding['shared'], ['count' => $funding['shared']]) }}</span>
                                    @endif
                                @endif
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell :label="__('projects.connections')" class="@max-6xl:hidden @max-3xl:flex!">
                                <span class="flex flex-wrap items-center gap-1">
                                    @foreach ($connections as $connection)
                                        <span
                                            @class([
                                                'inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-xs tabular-nums',
                                                'border-primary bg-primary dark:border-transparent dark:bg-status-critical-soft dark:text-status-critical font-semibold text-white' => $connection['alert'],
                                                'border-board-line text-secondary-medium dark:text-ink-muted' => ! $connection['alert'],
                                            ])
                                            title="{{ $connection['label'] }}"
                                            aria-label="{{ $connection['label'] }}"
                                        >
                                            <flux:icon :name="$connection['icon']" variant="micro" class="size-3.5" aria-hidden="true" />{{ $connection['count'] }}
                                        </span>
                                    @endforeach
                                </span>
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell :label="__('projects.owner')" class="@max-4xl:hidden @max-3xl:flex!">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <x-ui.employee-chip :employee="$project->user" compact />
                                    <x-ui.assignment-badge :project="$project" />
                                </div>
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell :label="__('sectors.sectors')" class="@max-6xl:hidden @max-3xl:flex!" data-test="project-sectors-cell">
                                <x-projects.sector-chips :sectors="$project->sectors" :empty="__('sectors.none')" class="text-xs" />
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell :label="__('projects.col_schedule')">
                                <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs whitespace-nowrap {{ $this->tonePillClasses($schedule['tone']) }}">
                                    <flux:icon :name="$schedule['icon']" variant="micro" class="size-3.5" aria-hidden="true" />{{ $schedule['label'] }}
                                </span>
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell :label="__('common.status')">
                                <x-ui.enum-badge :case="$project->status" />
                            </x-ui.data-table.cell>

                            <x-ui.data-table.cell>
                                <div class="flex items-center justify-end gap-1">
                                    <a
                                        href="{{ route('projects.show', $project) }}"
                                        wire:navigate
                                        class="grid size-11 shrink-0 place-items-center rounded-md border-0! bg-transparent! text-secondary-dark! transition-colors hover:bg-secondary-soft! focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-ink! dark:hover:bg-surface-2! @3xl/directory:size-8!"
                                        aria-label="{{ __('projects.open_project', ['name' => $project->name]) }}"
                                    >
                                        <flux:icon.eye class="size-4.5" />
                                    </a>
                                    <x-ui.blocked-badge :project="$project" />
                                    @can('update', $project)<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! shrink-0 touch-manipulation @3xl/directory:size-8!" wire:click="openEditModal({{ $project->id }})" :aria-label="__('common.edit_name', ['name' => $project->name])" />@endcan
                                    @if ($project->status === ProjectStatus::Archived)
                                        @can('delete', $project)<x-ui.button tone="ghost" size="sm" icon="arrow-uturn-left" class="size-11! shrink-0 touch-manipulation @3xl/directory:size-8!" wire:click="restoreProject({{ $project->id }})" :aria-label="__('projects.restore_name', ['name' => $project->name])" />@endcan
                                    @else
                                        @can('delete', $project)<x-ui.button tone="ghost" size="sm" icon="archive-box" class="size-11! shrink-0 touch-manipulation @3xl/directory:size-8!" wire:click="confirmArchive({{ $project->id }})" :aria-label="__('projects.archive_name', ['name' => $project->name])" />@endcan
                                    @endif
                                </div>
                            </x-ui.data-table.cell>
                        </tr>
                    @endforeach
                </x-ui.data-table>
