@use('App\Models\PlanIndicator')
        <x-ui.panel :title="__('projects.project_indicators')" :count="$this->projectIndicators->sum(fn (array $group): int => $group['indicators']->count())" :subtitle="__('projects.project_indicators_subtitle')" class="mb-6" data-tour="project-indicators">
            @if ($this->projectIndicators->isEmpty())
                <x-ui.empty-state icon="chart-bar" :heading="__('projects.no_project_indicators')" :body="__('projects.no_project_indicators_body')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-line">
                    @foreach ($this->projectIndicators as $group)
                        <section class="px-4 py-3" wire:key="indicator-source-{{ $group['key'] }}" data-indicator-source="{{ $group['key'] }}">
                            <p class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-secondary-medium dark:text-ink-muted">
                                <flux:icon :name="$group['proposal'] === null ? 'squares-2x2' : 'document-text'" variant="micro" class="size-3.5" aria-hidden="true" />
                                @if ($group['proposal'] === null)
                                    {{ __('projects.program_framework') }}
                                @else
                                    <a href="{{ $this->attachedProposals->contains('id', $group['proposal']->id) ? $this->proposalStoryUrl($group['proposal']->id, 'targets') : route('proposals.show', ['proposal' => $group['proposal'], 'tab' => 'targets']) }}" wire:navigate class="normal-case underline-offset-2 hover:underline" dir="auto">{{ $group['proposal']->name }}</a>
                                @endif
                            </p>

                            <ul class="mt-2 flex flex-col gap-1.5">
                                @foreach ($group['indicators'] as $indicator)
                                    <li class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm" wire:key="project-indicator-{{ $indicator->id }}">
                                        <x-ui.enum-badge :case="$indicator->result->type" />
                                        @if ($indicator->code !== null)
                                            <bdi dir="ltr" class="font-mono text-xs text-secondary-medium dark:text-ink-muted">{{ $indicator->code }}</bdi>
                                        @endif
                                        <span class="min-w-0 text-secondary-dark dark:text-ink" dir="auto">{{ $indicator->libraryIndicator?->localizedName() ?? $indicator->name }}</span>
                                        @if ($indicator->library_indicator_id === null)
                                            <span class="inline-flex items-center gap-1 rounded-full border border-status-critical px-2 py-0.5 text-[11px] font-semibold text-status-critical">
                                                <flux:icon.link-slash variant="micro" class="size-3" aria-hidden="true" />
                                                {{ __('targets.not_linked_to_library') }}
                                            </span>
                                        @endif
                                        @if ($indicator->project_id === $this->projectId)
                                            <span class="ms-auto inline-flex items-center gap-1 text-[11px] font-semibold text-secondary-medium dark:text-ink-muted">
                                                <flux:icon.map-pin variant="micro" class="size-3" aria-hidden="true" />
                                                {{ __('projects.indicator_scoped_here') }}
                                            </span>
                                        @elseif ($group['proposal'] === null)
                                            @can('indicators.update')
                                                <flux:button size="xs" icon="map-pin" class="ms-auto" wire:click="scopeIndicator({{ $indicator->id }})" wire:loading.attr="disabled" wire:target="scopeIndicator({{ $indicator->id }})" data-scope-indicator="{{ $indicator->id }}">
                                                    {{ __('projects.scope_to_project') }}
                                                </flux:button>
                                            @endcan
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                </div>
                @error('indicator')
                    <p class="flex items-center gap-1.5 border-t border-status-critical bg-status-critical-soft px-4 py-2 text-sm font-semibold text-status-critical" role="alert">
                        <flux:icon.exclamation-triangle variant="micro" class="size-4 shrink-0" />{{ $message }}
                    </p>
                @enderror
            @endif
        </x-ui.panel>

        <x-ui.panel :title="__('projects.targets_naming_this_project')" :count="$this->targets->count()" :subtitle="__('projects.targets_linked_by_indicator_result_source_and_period')">
            @if ($this->targets->isEmpty())
                <x-ui.empty-state icon="chart-bar" :heading="__('common.no_targets_yet')" :body="__('projects.indicator_targets_that_name_this_project_will_appear_here')" />
            @else
                @php
                    $viaAnnualPlan = $this->targets->where('source_type', 'annual_plan')->count();
                    $viaProposal = $this->targets->count() - $viaAnnualPlan;
                    $reportsFiled = $this->targets->sum('reports_count');
                @endphp
                <div class="grid gap-3 border-b border-secondary-soft p-3.5 @lg/detail:grid-cols-4 dark:border-line">
                    <x-ui.stat-tile :label="__('projects.stat_indicators_covered')" :value="$this->targetedIndicatorCount" icon="chart-bar" />
                    <x-ui.stat-tile :label="__('projects.stat_committed_via_annual_plans')" :value="$viaAnnualPlan" icon="calendar-days" />
                    <x-ui.stat-tile :label="__('projects.stat_committed_via_proposals')" :value="$viaProposal" icon="document-text" />
                    <x-ui.stat-tile :label="__('projects.stat_reports_filed')" :value="$reportsFiled" icon="document-check" :tone="$reportsFiled > 0 ? 'good' : 'warn'" />
                </div>

                <div class="p-3.5 @3xl/detail:p-5">
                    <x-ui.target-lineage :groups="$this->targetsByResult" :format="fn (PlanIndicator $target): string => $this->formatTargetValue($target)" />
                </div>
            @endif
        </x-ui.panel>
