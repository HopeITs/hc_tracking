{{--
    The list view of projects/index: one quiet line per project, read left to
    right as "what, how it is doing, how far, when it ends, who owns it". The
    rest (money, people, links, sectors) waits in a preview that opens on hover
    or focus, so the list stays scannable. Included by ⚡index.blade.php, whose
    top PHP block defines $projects, $fundingByProject and $scheduleOf.
--}}
<ol class="dk-pjl" aria-label="{{ __('projects.project_directory') }}">
    @foreach ($projects as $project)
        @php
            $progress = $this->progressFor($project);
            $schedule = $scheduleOf($project);
            $funding = $fundingByProject[$project->id] ?? null;
            $demographics = $this->demographicsOf($project);
            $connections = $this->connectionsOf($project);
            $healthClass = match ($schedule['tone']) {
                'good' => 'dk-good',
                'warn' => 'dk-warn',
                'critical' => 'dk-bad',
                default => 'dk-calm',
            };
            $hasEnded = $project->end_date !== null && $project->end_date->lt($today);
        @endphp

        <li @class(['dk-pjl-row', 'dk-pjl-alert' => $schedule['tone'] === 'critical']) wire:key="project-{{ $project->id }}">
            <div class="dk-pjl-main" tabindex="0" aria-describedby="project-peek-{{ $project->id }}">
                <p class="dk-pjl-name">
                    @if (in_array($project->id, $this->unreadIds, true))
                        <span class="dk-pjl-dot" data-unread-dot="project-{{ $project->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                    @endif
                    <span title="{{ $project->name }}">{{ $project->name }}</span>
                </p>
                <p class="dk-pjl-meta">
                    <span>{{ $project->city?->name ?? __('common.unknown_city') }}</span>
                    <span aria-hidden="true">·</span>
                    <span class="truncate">{{ $project->program->name }}</span>
                    @if ($status === '')
                        <span aria-hidden="true">·</span>
                        <span>{{ $project->status->label() }}</span>
                    @endif
                    <x-ui.assignment-badge :project="$project" />
                    <x-ui.project-funding-badge :project="$project" />
                </p>

                {{-- The preview: everything a card used to shout, one hover away. --}}
                <div class="dk-pjl-peek" id="project-peek-{{ $project->id }}" role="tooltip">
                    <dl>
                        <div>
                            <dt>{{ __('common.strategy') }}</dt>
                            <dd>{{ $project->program->strategy?->name ?? __('common.strategy_not_assigned') }}</dd>
                        </div>

                        <div>
                            <dt>{{ __('projects.funding_envelope') }}</dt>
                            <dd>
                                @if ($funding === null)
                                    {{ $project->proposals_count === 0 ? __('projects.not_funded_yet') : '—' }}
                                @else
                                    @foreach ($funding['currencies'] as $peekCurrency => $peekTotal)
                                        <strong class="dk-num" wire:key="funding-{{ $project->id }}-{{ $peekCurrency }}"><x-ui.money :amount="$peekTotal" :currency="$peekCurrency" /></strong>
                                    @endforeach
                                    <span class="dk-pjl-sub">{{ implode(' · ', $funding['donors']) }}</span>
                                    <span class="dk-pjl-sub">{{ implode(' · ', $funding['proposals']) }}</span>
                                    @if ($funding['shared'] > 0)
                                        <span class="dk-pjl-sub">{{ trans_choice('projects.shared_with_other_projects', $funding['shared'], ['count' => $funding['shared']]) }}</span>
                                    @endif
                                @endif
                            </dd>
                        </div>

                        <div>
                            <dt>{{ __('projects.col_beneficiaries') }}</dt>
                            <dd>
                                <strong class="dk-num">{{ $project->target_beneficiaries === null ? '—' : number_format($project->target_beneficiaries) }}</strong>
                                @if ($demographics !== [])
                                    <span class="dk-pjl-sub dk-num">
                                        @foreach ($demographics as $demographicLabel => $demographicValue)
                                            {{ $demographicLabel }} {{ number_format($demographicValue) }}@if (! $loop->last) · @endif
                                        @endforeach
                                    </span>
                                @endif
                            </dd>
                        </div>

                        <div>
                            <dt>{{ __('projects.connections') }}</dt>
                            <dd>
                                @forelse ($connections as $connection)
                                    <span @class(['dk-pjl-link', 'dk-bad' => $connection['alert']])>{{ $connection['label'] }}</span>
                                @empty
                                    {{ __('projects.no_connections_yet') }}
                                @endforelse
                            </dd>
                        </div>

                        <div data-test="project-sectors-cell">
                            <dt>{{ __('sectors.sectors') }}</dt>
                            <dd><x-projects.sector-chips :sectors="$project->sectors" :empty="__('sectors.none')" class="text-xs" /></dd>
                        </div>
                    </dl>
                </div>
            </div>

            <span class="dk-health {{ $healthClass }}" data-test="project-health" title="{{ $schedule['label'] }}">{{ $schedule['label'] }}</span>

            <div class="dk-pjl-prog">
                <x-ui.progress-bar :value="$progress" :tone="$schedule['tone']" :label="__('projects.milestone_progress_for', ['name' => $project->name])" label-hidden />
                <span class="dk-num" title="{{ $progress === null ? __('projects.no_weighted_milestones') : __('projects.milestones_reached', ['reached' => $project->reached_milestones_count, 'total' => $project->milestones_count]) }}">{{ $progress === null ? '—' : round($progress).'%' }}</span>
            </div>

            <p class="dk-pjl-end dk-num">
                @if ($project->end_date === null)
                    <span class="dk-muted">{{ __('projects.unscheduled') }}</span>
                @else
                    <span class="dk-pjl-k">{{ $hasEnded ? __('projects.ended') : __('projects.ends') }}</span>
                    {{ $project->end_date->translatedFormat(__('formats.date')) }}
                @endif
            </p>

            <div class="dk-pjl-owner">
                <x-ui.employee-chip :employee="$project->user" compact />
            </div>

            @include('pages.projects._index-row-actions')
        </li>
    @endforeach
</ol>
