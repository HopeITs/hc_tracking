        <x-ui.panel :title="__('projects.risk_register')" :count="$this->risks->count()" :subtitle="__('projects.risks_that_have_materialised_are_listed_first')">
            <x-slot:toolbar>
                <a href="{{ route('risks.index', ['project' => $this->projectId]) }}" wire:navigate class="type-meta font-semibold text-secondary-medium hover:text-primary hover:underline dark:text-ink-muted dark:hover:text-brand-text">
                    {{ __('projects.manage_risks') }}
                </a>
            </x-slot:toolbar>

            @if ($this->risks->isEmpty())
                <x-ui.empty-state icon="exclamation-triangle" :heading="__('common.no_risks_logged')" :body="__('projects.risks_logged_against_this_project_will_appear_here')">
                    <x-slot:action>
                        <x-ui.button size="sm" tone="primary" icon="plus" href="{{ route('risks.index', ['project' => $this->projectId]) }}" wire:navigate>{{ __('risks.log_risk') }}</x-ui.button>
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                @php
                    [$materialisedRisks, $watchingRisks] = $this->risks->partition(fn ($risk) => $risk->happened);
                    $riskLanes = array_values(array_filter([
                        [
                            'key' => 'materialised',
                            'label' => __('common.materialised'),
                            'icon' => 'exclamation-triangle',
                            'risks' => $materialisedRisks,
                            'laneClasses' => 'border-primary bg-white dark:bg-surface-1',
                            'headerClasses' => 'bg-primary dark:border-transparent dark:bg-status-critical-soft dark:text-status-critical text-white',
                        ],
                        [
                            'key' => 'watching',
                            'label' => __('common.watching'),
                            'icon' => 'eye',
                            'risks' => $watchingRisks,
                            'laneClasses' => 'border-secondary-soft bg-white dark:border-line dark:bg-surface-1',
                            'headerClasses' => 'bg-board-head text-secondary-medium dark:bg-surface-2 dark:text-ink-muted',
                        ],
                    ], fn ($lane) => $lane['risks']->isNotEmpty()));
                @endphp
                <div class="p-4">
                    <div @class(['grid gap-4', '@lg/detail:grid-cols-2' => count($riskLanes) === 2])>
                        @foreach ($riskLanes as $lane)
                            <div class="overflow-hidden rounded-xl border {{ $lane['laneClasses'] }}" wire:key="project-risk-lane-{{ $lane['key'] }}">
                                <div class="flex items-center gap-1.5 px-3 py-2 {{ $lane['headerClasses'] }}">
                                    <flux:icon :name="$lane['icon']" variant="micro" class="size-4" />
                                    <p class="type-label">{{ $lane['label'] }}</p>
                                    <span class="ms-auto text-xs font-semibold tabular-nums">{{ $lane['risks']->count() }}</span>
                                </div>

                                <div class="flex flex-col divide-y divide-secondary-soft dark:divide-line">
                                    @foreach ($lane['risks'] as $risk)
                                        <article class="flex flex-col gap-2.5 p-3.5" wire:key="project-risk-{{ $risk->id }}">
                                            <p class="text-sm font-semibold text-secondary-dark dark:text-ink">{{ $risk->name }}</p>
                                            <p class="type-body text-secondary-medium dark:text-ink-muted">{{ $risk->impact }}</p>
                                            <div class="flex items-start gap-1.5 border-t border-secondary-soft pt-2.5 dark:border-line">
                                                @if ($risk->solution !== null)
                                                    <flux:icon name="check-circle" variant="micro" class="mt-0.5 size-4 shrink-0 text-secondary-medium dark:text-ink-muted" />
                                                    <p class="type-body text-secondary-dark dark:text-ink">{{ $risk->solution }}</p>
                                                @else
                                                    <flux:icon name="exclamation-circle" variant="micro" class="mt-0.5 size-4 shrink-0 text-primary dark:text-brand-text" />
                                                    <p class="type-body italic text-primary dark:text-brand-text">{{ __('risks.no_mitigation_recorded_yet') }}</p>
                                                @endif
                                            </div>
                                        </article>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-ui.panel>
