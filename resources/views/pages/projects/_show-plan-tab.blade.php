        <x-ui.panel :title="__('gantt.plan')" :subtitle="__('gantt.plan_subtitle')">
            @if ($this->project->status === \App\Enums\ProjectStatus::Draft)
                <x-ui.empty-state icon="calendar-days" :heading="__('projects.plan_after_approval')" data-test="plan-after-approval" />
            @elseif ($this->ganttPayload['rows'] === [])
                <x-ui.empty-state icon="chart-bar" :heading="__('gantt.nothing_to_plan')" :body="__('gantt.nothing_to_plan_body')" />
            @else
                @php
                    $planSeries = $this->ganttPayload['series'];
                @endphp
                @if ($planSeries !== [])
                    {{-- Which proposal plans the chart draws, each with its colour (T31, D-05) --}}
                    <div class="mb-4 flex flex-wrap items-end gap-x-6 gap-y-3" data-plan-series>
                        <div class="w-full max-w-xs">
                            <x-ui.header-filter-select :label="__('gantt.plan_proposals')" wire:model.live="planProposal" :value="$planProposal">
                                <option value="">{{ __('gantt.all_proposals') }}</option>
                                @foreach ($planSeries as $line)
                                    <option value="{{ $line['id'] }}">{{ $line['name'] }}</option>
                                @endforeach
                            </x-ui.header-filter-select>
                        </div>
                        <ul class="flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-secondary-medium dark:text-ink-muted" aria-label="{{ __('gantt.plan_proposals') }}">
                            @foreach ($planSeries as $line)
                                <li class="inline-flex items-center gap-1.5" data-series="{{ $line['series'] }}">
                                    <span class="h-3 w-1 rounded-full bg-series-{{ $line['series'] }}" aria-hidden="true"></span>
                                    <span dir="auto">{{ $line['name'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <x-ui.gantt :payload="$this->ganttPayload" :scope="'project-'.$this->projectId" :editable="auth()->user()->can('update', $this->project)" wire:ignore wire:key="project-gantt-{{ $this->projectId }}-{{ $planProposal }}" />
            @endif
        </x-ui.panel>
